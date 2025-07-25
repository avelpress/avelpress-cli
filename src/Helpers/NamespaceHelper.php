<?php

namespace AvelPress\Cli\Helpers;

class NamespaceHelper
{
    /**
     * Convert a string to PascalCase
     */
    public static function toPascalCase(string $name): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $name)));
    }

    /**
     * Detect the package namespace from PSR-4 autoload configuration in composer.json
     * 
     * @param string|null $subPath Path relative to src (e.g., 'app' for src/app)
     * @return string|null The namespace or null if not found
     */
    public static function detectPackageNamespace(?string $subPath = null): ?string
    {
        $currentDir = getcwd();
        $composerFile = $currentDir . '/composer.json';
        
        if (!file_exists($composerFile)) {
            return null;
        }

        $composer = json_decode(file_get_contents($composerFile), true);
        
        if (!isset($composer['autoload']['psr-4'])) {
            return null;
        }

        // If no subPath is provided, return the root namespace (typically mapped to "src/")
        if ($subPath === null) {
            foreach ($composer['autoload']['psr-4'] as $namespace => $path) {
                // Look for the root path (usually "src/")
                if (rtrim($path, '/') === 'src') {
                    return rtrim($namespace, '\\');
                }
            }
            
            // If no exact "src/" match, return the first namespace found
            foreach ($composer['autoload']['psr-4'] as $namespace => $path) {
                return rtrim($namespace, '\\');
            }
        }

        // If subPath is provided, look for the specific path
        $targetPath = 'src/' . trim($subPath, '/');
        
        foreach ($composer['autoload']['psr-4'] as $namespace => $path) {
            if (rtrim($path, '/') === rtrim($targetPath, '/')) {
                return rtrim($namespace, '\\');
            }
        }

        return null;
    }

    /**
     * Generate the package namespace for PHP usage (single backslash)
     */
    public static function getPackageNamespace(string $vendor, string $packageName): string
    {
        return self::toPascalCase($vendor) . '\\' . self::toPascalCase($packageName);
    }

    /**
     * Generate a full namespace for a specific class type
     */
    public static function getClassNamespace(string $baseNamespace, string $subNamespace = ''): string
    {
        if (empty($subNamespace)) {
            return $baseNamespace;
        }

        return $baseNamespace . '\\' . $subNamespace;
    }

    /**
     * Generate namespace for module-based classes
     */
    public static function getModuleNamespace(string $baseNamespace, string $moduleName, string $subNamespace = ''): string
    {
        $moduleNamespace = $baseNamespace . '\\App\\Modules\\' . self::toPascalCase($moduleName);
        
        if (empty($subNamespace)) {
            return $moduleNamespace;
        }

        return $moduleNamespace . '\\' . $subNamespace;
    }

    /**
     * Extract vendor and package name from current working directory
     * Useful for commands that run inside an existing project
     */
    public static function extractFromComposerJson(string $projectPath = null): ?array
    {
        $composerPath = ($projectPath ?: getcwd()) . '/composer.json';
        
        if (!file_exists($composerPath)) {
            return null;
        }

        $composer = json_decode(file_get_contents($composerPath), true);
        
        if (!isset($composer['name'])) {
            return null;
        }

        $parts = explode('/', $composer['name']);
        
        if (count($parts) !== 2) {
            return null;
        }

        return [
            'vendor' => $parts[0],
            'package' => $parts[1]
        ];
    }
}
