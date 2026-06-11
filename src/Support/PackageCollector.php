<?php

namespace Laravel\Xelentwatch\Support;

/**
 * Package Collector for CVE Scanner
 * 
 * Collects package inventory from composer.lock and package-lock.json files.
 */
class PackageCollector
{
    /**
     * Collect packages from a Laravel/project directory.
     */
    public function collect(string $projectPath): array
    {
        $packages = [];

        // Collect from composer.lock (PHP packages)
        $composerPackages = $this->collectFromComposerLock($projectPath);
        $packages = array_merge($packages, $composerPackages);

        // Collect from package-lock.json (Node.js packages)
        $npmPackages = $this->collectFromPackageLock($projectPath);
        $packages = array_merge($packages, $npmPackages);

        return $packages;
    }

    /**
     * Collect packages from composer.lock.
     */
    public function collectFromComposerLock(string $projectPath): array
    {
        $lockFile = rtrim($projectPath, '/\\') . '/composer.lock';

        if (!file_exists($lockFile)) {
            return [];
        }

        $content = file_get_contents($lockFile);
        $data = json_decode($content, true);

        if (!isset($data['packages']) || !is_array($data['packages'])) {
            return [];
        }

        $packages = [];

        foreach ($data['packages'] as $package) {
            $name = $package['name'] ?? '';
            $version = $this->normalizeComposerVersion($package['version'] ?? '');

            if (empty($name) || empty($version)) {
                continue;
            }

            // Parse vendor and package name
            $parts = explode('/', $name);
            $vendor = $parts[0] ?? '';
            $packageName = $parts[1] ?? $name;

            $packages[] = [
                'name' => $packageName,
                'full_name' => $name,
                'vendor' => $vendor,
                'version' => $version,
                'ecosystem' => 'composer',
                'license' => $this->extractLicense($package['license'] ?? []),
                'description' => $package['description'] ?? null,
                'dependencies' => $this->extractDependencies($package['require'] ?? []),
                'is_dev' => false, // Will be determined by checking require-dev
            ];
        }

        // Also collect dev packages
        if (isset($data['packages-dev'])) {
            foreach ($data['packages-dev'] as $package) {
                $name = $package['name'] ?? '';
                $version = $this->normalizeComposerVersion($package['version'] ?? '');

                if (empty($name) || empty($version)) {
                    continue;
                }

                $parts = explode('/', $name);
                $vendor = $parts[0] ?? '';
                $packageName = $parts[1] ?? $name;

                $packages[] = [
                    'name' => $packageName,
                    'full_name' => $name,
                    'vendor' => $vendor,
                    'version' => $version,
                    'ecosystem' => 'composer',
                    'license' => $this->extractLicense($package['license'] ?? []),
                    'description' => $package['description'] ?? null,
                    'dependencies' => $this->extractDependencies($package['require'] ?? []),
                    'is_dev' => true,
                ];
            }
        }

        return $packages;
    }

    /**
     * Collect packages from package-lock.json.
     */
    public function collectFromPackageLock(string $projectPath): array
    {
        $lockFile = rtrim($projectPath, '/\\') . '/package-lock.json';

        if (!file_exists($lockFile)) {
            return [];
        }

        $content = file_get_contents($lockFile);
        $data = json_decode($content, true);

        if (!isset($data['packages']) || !is_array($data['packages'])) {
            return [];
        }

        $packages = [];

        foreach ($data['packages'] as $packagePath => $package) {
            // Skip the root package (empty string key or ".")
            if (empty($packagePath) || $packagePath === '') {
                continue;
            }

            // Remove "node_modules/" prefix if present
            $name = preg_replace('/^node_modules\//', '', $packagePath);

            // Handle scoped packages (@org/package)
            $name = ltrim($name, '@');

            $version = $this->normalizeNpmVersion($package['version'] ?? '');

            if (empty($name) || empty($version)) {
                continue;
            }

            // Parse vendor for scoped packages
            $vendor = '';
            if (strpos($name, '/') !== false) {
                $parts = explode('/', $name);
                $vendor = ltrim($parts[0], '@');
                $name = $parts[1] ?? $name;
            }

            $packages[] = [
                'name' => $name,
                'full_name' => $packagePath,
                'vendor' => $vendor ?: null,
                'version' => $version,
                'ecosystem' => 'npm',
                'license' => $this->extractNpmLicense($package['license'] ?? ''),
                'description' => $package['description'] ?? null,
                'dependencies' => $this->extractNpmDependencies($package['dependencies'] ?? []),
                'is_dev' => $package['dev'] ?? false,
            ];
        }

        return $packages;
    }

    /**
     * Normalize a composer version string.
     */
    private function normalizeComposerVersion(string $version): string
    {
        // Remove "v" prefix
        $version = ltrim($version, 'vV');

        // Handle dev versions
        if (str_starts_with($version, 'dev-')) {
            return '0.0.0-dev';
        }

        // Handle alias versions like "1.0.0 as 1.0.0"
        if (str_contains($version, ' as ')) {
            $parts = explode(' as ', $version);
            $version = end($parts);
        }

        return $version;
    }

    /**
     * Normalize an npm version string.
     */
    private function normalizeNpmVersion(string $version): string
    {
        // Remove leading operators
        $version = ltrim($version, '^~><=');

        // Handle ranges
        if (str_contains($version, ' - ')) {
            return '0.0.0-range';
        }

        // Handle "x" wildcards
        $version = str_replace('x', '0', $version);

        return $version;
    }

    /**
     * Extract license from composer package.
     */
    private function extractLicense(array|string $license): ?string
    {
        if (is_array($license)) {
            return implode(', ', $license);
        }
        return $license ?: null;
    }

    /**
     * Extract dependencies from composer package.
     */
    private function extractDependencies(array $require): array
    {
        $dependencies = [];

        foreach ($require as $name => $version) {
            // Skip PHP extensions and platform requirements
            if (str_starts_with($name, 'php:') || str_starts_with($name, 'ext-')) {
                continue;
            }

            $dependencies[$name] = $version;
        }

        return $dependencies;
    }

    /**
     * Extract license from npm package.
     */
    private function extractNpmLicense(mixed $license): ?string
    {
        if (is_string($license)) {
            return $license;
        }

        if (is_array($license)) {
            if (isset($license['type'])) {
                return $license['type'];
            }
            return implode(', ', $license);
        }

        return null;
    }

    /**
     * Extract dependencies from npm package.
     */
    private function extractNpmDependencies(array $dependencies): array
    {
        return $dependencies;
    }

    /**
     * Get package statistics.
     */
    public function getStats(array $packages): array
    {
        $stats = [
            'total' => count($packages),
            'by_ecosystem' => [],
            'by_license' => [],
            'dev_packages' => 0,
        ];

        foreach ($packages as $package) {
            // Count by ecosystem
            $ecosystem = $package['ecosystem'] ?? 'other';
            $stats['by_ecosystem'][$ecosystem] = ($stats['by_ecosystem'][$ecosystem] ?? 0) + 1;

            // Count by license
            $license = $package['license'] ?? 'unknown';
            $stats['by_license'][$license] = ($stats['by_license'][$license] ?? 0) + 1;

            // Count dev packages
            if ($package['is_dev'] ?? false) {
                $stats['dev_packages']++;
            }
        }

        return $stats;
    }
}
