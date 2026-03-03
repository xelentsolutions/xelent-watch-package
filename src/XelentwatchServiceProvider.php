<?php

namespace Laravel\Xelentwatch;

use Illuminate\Auth\AuthManager;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\ForgettingKey;
use Illuminate\Cache\Events\KeyForgetFailed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWriteFailed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Cache\Events\RetrievingManyKeys;
use Illuminate\Cache\Events\WritingKey;
use Illuminate\Cache\Events\WritingManyKeys;
use Illuminate\Console\Events\ArtisanStarting;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Events\Terminating;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Queue\Queue;
use Illuminate\Routing\Events\PreparingResponse;
use Illuminate\Routing\Events\ResponsePrepared;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\ServiceProvider;
use Laravel\Xelentwatch\Console\AgentCommand;
use Laravel\Xelentwatch\Facades\Xelentwatch;
use Laravel\Xelentwatch\Factories\Logger;
use Laravel\Xelentwatch\Hooks\ArtisanStartingListener;
use Laravel\Xelentwatch\Hooks\CacheEventListener;
use Laravel\Xelentwatch\Hooks\CommandBootedHandler;
use Laravel\Xelentwatch\Hooks\CommandStartingListener;
use Laravel\Xelentwatch\Hooks\ContextDehydratingHandler;
use Laravel\Xelentwatch\Hooks\CreateQueuePayloadHandler;
use Laravel\Xelentwatch\Hooks\ExceptionHandlerResolvedHandler;
use Laravel\Xelentwatch\Hooks\GlobalMiddleware;
use Laravel\Xelentwatch\Hooks\HttpClientFactoryResolvedHandler;
use Laravel\Xelentwatch\Hooks\HttpKernelResolvedHandler;
use Laravel\Xelentwatch\Hooks\LivewireListener;
use Laravel\Xelentwatch\Hooks\LogoutListener;
use Laravel\Xelentwatch\Hooks\MailListener;
use Laravel\Xelentwatch\Hooks\NotificationListener;
use Laravel\Xelentwatch\Hooks\OctaneListener;
use Laravel\Xelentwatch\Hooks\PolyfillContextDehydration;
use Laravel\Xelentwatch\Hooks\PolyfillContextHydration;
use Laravel\Xelentwatch\Hooks\PreparingResponseListener;
use Laravel\Xelentwatch\Hooks\QueryExecutedListener;
use Laravel\Xelentwatch\Hooks\QueuedJobListener;
use Laravel\Xelentwatch\Hooks\RequestBootedHandler;
use Laravel\Xelentwatch\Hooks\RequestHandledListener;
use Laravel\Xelentwatch\Hooks\ResponsePreparedListener;
use Laravel\Xelentwatch\Hooks\RouteMatchedListener;
use Laravel\Xelentwatch\Hooks\RouteMiddleware;
use Laravel\Xelentwatch\Hooks\TerminatingListener;
use Laravel\Xelentwatch\Http\Middleware\Sample;
use Laravel\Xelentwatch\State\CommandState;
use Laravel\Xelentwatch\State\RequestState;
use Laravel\Xelentwatch\Support\Uuid;
use Laravel\Octane\Events\RequestReceived;
use Livewire\Livewire;
use Livewire\LivewireManager;
use Ramsey\Uuid\Uuid as BaseUuid;
use Throwable;

use function class_exists;
use function defined;
use function hash;
use function microtime;
use function substr;

/**
 * @internal
 */
final class XelentwatchServiceProvider extends ServiceProvider
{
    /**
     * @var Core<RequestState|CommandState>
     */
    private Core $core;

    private float $timestamp;

    private bool $isRequest;

    private Repository $config;

    /**
     * @var array{
     *     enabled?: bool,
     *     sampling?: array{
     *        requests?: float,
     *        commands?: float,
     *        exceptions?: float,
     *        scheduled_tasks?: float,
     *     },
     *     filtering?: array{
     *         ignore_cache_events?: bool,
     *         ignore_mail?: bool,
     *         ignore_notifications?: bool,
     *         ignore_outgoing_requests?: bool,
     *         ignore_queries?: bool,
     *         log_level?: \Psr\Log\LogLevel::*,
     *     },
     *     token?: string,
     *     deployment?: string,
     *     server?: string,
     *     ingest?: array{ uri?: string, timeout?: float|int, connection_timeout?: float|int, event_buffer?: int },
     *     capture_exception_source_code?: bool,
     *     capture_request_payload?: bool,
     *     redact_payload_fields?: string[],
     *     redact_headers?: string[],
     *  }
     */
    private array $xelentwatchConfig;

    private ?Throwable $registerException = null;

    public function register(): void
    {
        try {
            $this->captureTimestamp();
            Compatibility::boot($this->app);
            $this->captureExecutionType();
            $this->registerAndCaptureConfig();
            $this->registerBindings();

            if (! $this->core->enabled()) {
                return;
            }

            $this->registerHooks();
        } catch (Throwable $e) {
            $this->registerException = $e;
        }
    }

    public function boot(): void
    {
        try {
            if ($this->registerException) {
                $this->handleAndClearRegisterException();

                return;
            }

            if ($this->app->runningInConsole()) {
                $this->registerPublications();
                $this->registerCommands();
            }
        } catch (Throwable $e) {
            Xelentwatch::unrecoverableExceptionOccurred($e);
        }
    }

    private function captureTimestamp(): void
    {
        $this->timestamp = match (true) {
            defined('LARAVEL_START') => LARAVEL_START,
            default => $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true),
        };
    }

    private function captureExecutionType(): void
    {
        $this->isRequest = ! $this->app->runningInConsole() || Env::get('XELENTWATCH_FORCE_REQUEST');
    }

    private function registerAndCaptureConfig(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/xelentwatch.php', 'xelentwatch');

        $this->config = $this->app->make(Repository::class);

        $this->xelentwatchConfig = $this->config->get('xelentwatch') ?? []; // @phpstan-ignore assign.propertyType
    }

    private function registerBindings(): void
    {
        $this->registerLogger();
        $this->registerMiddleware();
        $this->registerAgentCommand();
        $this->buildAndRegisterCore();
    }

    private function registerLogger(): void
    {
        if (! $this->config->has('logging.channels.xelentwatch')) {
            $this->config->set('logging.channels.xelentwatch', [
                'driver' => 'custom',
                'via' => Logger::class,
                'level' => $this->xelentwatchConfig['filtering']['log_level'] ?? 'debug',
            ]);
        }

        $this->app->singleton(Logger::class, fn() => new Logger($this->core));
    }

    private function registerMiddleware(): void
    {
        $this->app->singleton(RouteMiddleware::class, fn() => new RouteMiddleware($this->core)); // @phpstan-ignore argument.type

        $this->app->scoped(GlobalMiddleware::class, fn() => new GlobalMiddleware($this->core)); // @phpstan-ignore argument.type

        $this->app->singleton(Sample::class, fn() => new Sample($this->core)); // @phpstan-ignore argument.type
    }

    private function registerAgentCommand(): void
    {
        $this->app->singleton(AgentCommand::class, fn() => new AgentCommand(
            token: $this->xelentwatchConfig['token'] ?? null,
            server: $this->xelentwatchConfig['server'] ?? null,
            ingestUri: $this->xelentwatchConfig['ingest']['uri'] ?? null,
        ));
    }

    private function buildAndRegisterCore(): void
    {
        $clock = new Clock;
        $uuid = new Uuid(static fn() => BaseUuid::uuid4()->toString());
        $executionState = $this->executionState($uuid->make());
        $tokenHash = $this->xelentwatchConfig['token']
            ? hash('sha256', $this->xelentwatchConfig['token'])
            : '';

        // Get project_id and environment from config
        $projectId = $this->xelentwatchConfig['project_name'] ?? 'default';
        $environment = $this->xelentwatchConfig['environment'] ?? 'production';

        $ingestUri = $this->xelentwatchConfig['ingest']['uri'] ?? '127.0.0.1:2407';
        $eventBuffer = $this->xelentwatchConfig['ingest']['event_buffer'] ?? 500;

        // Log ingest configuration for debugging (only in local/debug environments)
        if ($this->app->environment('local', 'testing')) {
            \Illuminate\Support\Facades\Log::debug('[Xelentwatch] Creating Ingest', [
                'uri' => $ingestUri,
                'project_id' => $projectId,
                'environment' => $environment,
                'event_buffer' => $eventBuffer,
            ]);
        }

        $this->app->instance(Core::class, $this->core = new Core(
            ingest: new Ingest(
                transmitTo: $ingestUri,
                connectionTimeout: $this->xelentwatchConfig['ingest']['connection_timeout'] ?? 0.5,
                timeout: $this->xelentwatchConfig['ingest']['timeout'] ?? 0.5,
                streamFactory: new SocketStreamFactory,
                buffer: new RecordsBuffer(
                    length: $eventBuffer,
                ),
                tokenHash: $tokenHash,
                projectId: $projectId,
                environment: $environment,
            ),
            sensor: new SensorManager(
                executionState: $executionState,
                clock: $clock = new Clock,
                location: new Location(
                    basePath: $this->app->basePath(),
                    publicPath: $this->app->publicPath(),
                ),
                captureExceptionSourceCode: (bool) ($this->xelentwatchConfig['capture_exception_source_code'] ?? true),
                captureRequestPayload: (bool) ($this->xelentwatchConfig['capture_request_payload'] ?? false),
                redactPayloadFields: $this->xelentwatchConfig['redact_payload_fields'] ?? ['_token', 'password', 'password_confirmation'],
                redactHeaders: $this->xelentwatchConfig['redact_headers'] ?? ['Authorization', 'Cookie', 'Proxy-Authorization', 'X-XSRF-TOKEN'],
                config: $this->config,
            ),
            executionState: $executionState,
            clock: $clock,
            uuid: $uuid,
            config: [
                'enabled' => $this->xelentwatchConfig['enabled'] ?? true,
                'sampling' => [
                    'requests' => $this->xelentwatchConfig['sampling']['requests'] ?? 1.0,
                    'commands' => $this->xelentwatchConfig['sampling']['commands'] ?? 1.0,
                    'exceptions' => $this->xelentwatchConfig['sampling']['exceptions'] ?? 1.0,
                    'scheduled_tasks' => $this->xelentwatchConfig['sampling']['scheduled_tasks'] ?? 1.0,
                ],
                'filtering' => [
                    'ignore_cache_events' => (bool) ($this->xelentwatchConfig['filtering']['ignore_cache_events'] ?? false),
                    'ignore_mail' => (bool) ($this->xelentwatchConfig['filtering']['ignore_mail'] ?? false),
                    'ignore_notifications' => (bool) ($this->xelentwatchConfig['filtering']['ignore_notifications'] ?? false),
                    'ignore_outgoing_requests' => (bool) ($this->xelentwatchConfig['filtering']['ignore_outgoing_requests'] ?? false),
                    'ignore_queries' => (bool) ($this->xelentwatchConfig['filtering']['ignore_queries'] ?? false),
                ],
            ],
        ));
    }

    private function handleAndClearRegisterException(): void
    {
        Xelentwatch::unrecoverableExceptionOccurred($this->registerException); // @phpstan-ignore argument.type

        $this->registerException = null;
    }

    private function registerPublications(): void
    {
        $this->publishes([
            __DIR__ . '/../config/xelentwatch.php' => $this->app->configPath('xelentwatch.php'),
        ], ['xelentwatch', 'xelentwatch-config']);
    }

    private function registerCommands(): void
    {
        $this->commands([
            Console\AgentCommand::class,
            Console\StatusCommand::class,
            Console\AgentControlCommand::class,
        ]);
    }

    private function registerHooks(): void
    {
        $core = $this->core;

        /** @var Dispatcher */
        $events = $this->app->make(Dispatcher::class);

        //
        // -------------------------------------------------------------------------
        // Sensor hooks
        // --------------------------------------------------------------------------
        //

        /**
         * @see \Laravel\Xelentwatch\Records\Query
         */
        $events->listen(QueryExecuted::class, (new QueryExecutedListener($core))(...));

        /**
         * @see \Laravel\Xelentwatch\Records\Exception
         */
        $this->callAfterResolving(ExceptionHandler::class, (new ExceptionHandlerResolvedHandler($core))(...));

        /**
         * @see \Laravel\Xelentwatch\Records\QueuedJob
         */
        $events->listen([JobQueueing::class, JobQueued::class], (new QueuedJobListener($core))(...));

        /**
         * @see \Laravel\Xelentwatch\Records\Notification
         */
        $events->listen([NotificationSending::class, NotificationSent::class], (new NotificationListener($core))(...));

        /**
         * @see \Laravel\Xelentwatch\Records\Mail
         */
        $events->listen([MessageSending::class, MessageSent::class], (new MailListener($core))(...));

        /**
         * @see \Laravel\Xelentwatch\Records\OutgoingRequest
         */
        $this->callAfterResolving(Http::class, (new HttpClientFactoryResolvedHandler($core))(...));

        /**
         * @see \Laravel\Xelentwatch\Records\CacheEvent
         */
        $events->listen([
            RetrievingKey::class,
            RetrievingManyKeys::class,
            CacheHit::class,
            CacheMissed::class,
            WritingKey::class,
            WritingManyKeys::class,
            KeyWritten::class,
            KeyWriteFailed::class,
            ForgettingKey::class,
            KeyForgotten::class,
            KeyForgetFailed::class,
        ], (new CacheEventListener($core))(...));

        $events->listen(RequestReceived::class, (new OctaneListener($core))(...)); // @phpstan-ignore class.notFound

        Queue::createPayloadUsing(new CreateQueuePayloadHandler($core));

        if (Compatibility::$contextExists) {
            Context::dehydrating(new ContextDehydratingHandler($core));
        } else {
            Queue::createPayloadUsing(new PolyfillContextDehydration($core));
            $events->listen((new PolyfillContextHydration($core))(...));
        }

        //
        // -------------------------------------------------------------------------
        // Execution stage hooks
        // --------------------------------------------------------------------------
        //

        if ($this->isRequest) {
            /** @var Core<RequestState> $core */
            $this->registerRequestHooks($events, $core);
        } else {
            /** @var Core<CommandState> $core */
            $this->registerConsoleHooks($events, $core);
        }

        /** @var Core<RequestState|CommandState> $core */

        /**
         * @see \Laravel\Xelentwatch\ExecutionStage::Terminating
         */
        $events->listen(Terminating::class, (new TerminatingListener($core))(...));
    }

    /**
     * @param  Core<RequestState>  $core
     */
    private function registerRequestHooks(Dispatcher $events, Core $core): void
    {
        // TODO resolve the kernel inline rather than in the listener.

        /**
         * @see \Laravel\Xelentwatch\State\RequestState::$user
         *
         * TODO handle this on the queue
         */
        $events->listen(Logout::class, (new LogoutListener($core))(...));

        /**
         * @see \Laravel\Xelentwatch\ExecutionStage::BeforeMiddleware
         */
        $this->app->booted((new RequestBootedHandler($core))(...));

        /**
         * @see \Laravel\Xelentwatch\ExecutionStage::Action
         * @see \Laravel\Xelentwatch\ExecutionStage::Terminating
         */
        $events->listen(RouteMatched::class, (new RouteMatchedListener($core))(...));

        /**
         * @see \Laravel\Xelentwatch\ExecutionStage::Render
         */
        $events->listen(PreparingResponse::class, (new PreparingResponseListener($core))(...));

        /**
         * @see \Laravel\Xelentwatch\ExecutionStage::AfterMiddleware
         */
        $events->listen(ResponsePrepared::class, (new ResponsePreparedListener($core))(...));

        /**
         * @see \Laravel\Xelentwatch\ExecutionStage::Sending
         */
        $events->listen(RequestHandled::class, (new RequestHandledListener($core))(...));

        /**
         * @see \Laravel\Xelentwatch\ExecutionStage::End
         * @see \Laravel\Xelentwatch\Records\Request
         * @see \Laravel\Xelentwatch\ExecutionStage::Terminating
         * @see \Laravel\Xelentwatch\Core::finishExecution()
         */
        $this->callAfterResolving(HttpKernelContract::class, (new HttpKernelResolvedHandler($core))(...));

        $this->registerLivewireHooks($core);
    }

    /**
     * @param  Core<CommandState>  $core
     */
    private function registerConsoleHooks(Dispatcher $events, Core $core): void
    {
        /** @var ConsoleKernelContract */
        $kernel = $this->app->make(ConsoleKernelContract::class);

        /**
         * @see \Laravel\Xelentwatch\State\CommandState::$artisan
         */
        $events->listen(ArtisanStarting::class, (new ArtisanStartingListener($core))(...));

        /**
         * @see \Laravel\Xelentwatch\ExecutionStage::Action
         */
        $this->app->booted((new CommandBootedHandler($core))(...));

        /**
         * @see \Laravel\Xelentwatch\State\CommandState::$name
         *
         * Commands...
         * @see \Laravel\Xelentwatch\ExecutionStage::Terminating
         * @see \Laravel\Xelentwatch\ExecutionStage::End
         * @see \Laravel\Xelentwatch\Records\Command
         * @see \Laravel\Xelentwatch\Core::finishExecution()
         *
         * Jobs...
         * @see \Laravel\Xelentwatch\State\CommandState::$source
         * @see \Laravel\Xelentwatch\State\CommandState::flush()
         * @see \Laravel\Xelentwatch\State\CommandState::$timestamp
         * @see \Laravel\Xelentwatch\State\CommandState::$id
         * @see \Laravel\Xelentwatch\Records\JobAttempt
         * @see \Laravel\Xelentwatch\Records\Exception
         *
         * Scheduled tasks...
         * @see \Laravel\Xelentwatch\Core::finishExecution()
         */
        $events->listen(CommandStarting::class, (new CommandStartingListener($events, $core, $kernel))(...));
    }

    /**
     * @param  Core<RequestState>  $core
     */
    private function registerLivewireHooks(Core $core): void
    {
        if (! class_exists(Livewire::class)) {
            return;
        }

        $this->app->booted(static function ($app) use ($core) {
            if (! $app->bound(LivewireManager::class)) {
                return;
            }

            $listener = new LivewireListener($core);

            // Livewire 2
            Livewire::listen('component.hydrate.subsequent', $listener->componentHydrateSubsequent(...));

            // Livewire 3
            Livewire::listen('hydrate', $listener->hydrate(...));
        });
    }

    private function executionState(string $trace): RequestState|CommandState
    {
        Compatibility::addTraceIdToContext($trace);

        if ($this->isRequest) {
            return new RequestState(
                timestamp: $this->timestamp,
                trace: $trace,
                id: $trace,
                currentExecutionStageStartedAtMicrotime: $this->timestamp,
                deploy: $this->xelentwatchConfig['deployment'] ?? '',
                server: $this->xelentwatchConfig['server'] ?? '',
                user: $this->userProvider(),
            );
        } else {
            return new CommandState(
                timestamp: $this->timestamp,
                trace: new LazyValue(function () {
                    return (string) Compatibility::getTraceIdFromContext(function () { // @phpstan-ignore cast.string
                        $trace = $this->core->uuid->make();

                        Compatibility::addTraceIdToContext($trace);

                        return $trace;
                    });
                }),
                id: $trace,
                currentExecutionStageStartedAtMicrotime: $this->timestamp,
                deploy: $this->xelentwatchConfig['deployment'] ?? '',
                server: $this->xelentwatchConfig['server'] ?? '',
                user: $this->userProvider(),
            );
        }
    }

    private function userProvider(): UserProvider
    {
        /** @var AuthManager */
        $auth = $this->app->make(AuthManager::class);

        return new UserProvider(
            fn(callable $callback) => $this->core->ignore(static fn() => $callback($auth)),
            fn() => $this->core->userDetailsResolver,
            fn() => $this->core->report(...),
        );
    }
}
