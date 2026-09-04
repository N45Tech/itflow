<?php

/*
 * Stable boundary for fork-owned runtime modules and deployment feature flags.
 * Module files are always loaded so integrity and cleanup calls remain defined.
 * A feature flag may stop only the optional ingress/processing listed in the
 * manifest; it never rolls back schema or disables a security control.
 */

function n45ForkManifest(): array
{
    static $manifest = null;
    if ($manifest === null) {
        $manifest = require __DIR__ . '/manifest.php';
        if (!is_array($manifest) || intval($manifest['schema_version'] ?? 0) !== 2) {
            throw new RuntimeException('The N45 fork manifest is invalid');
        }
    }

    return $manifest;
}

function n45ParseBooleanFlag($value): ?bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return intval($value) !== 0;
    }
    if (!is_string($value)) {
        return null;
    }

    $normalized = strtolower(trim($value));
    if (in_array($normalized, ['1', 'true', 'yes', 'on', 'enabled'], true)) {
        return true;
    }
    if (in_array($normalized, ['0', 'false', 'no', 'off', 'disabled'], true)) {
        return false;
    }

    return null;
}

function n45FeatureEnabled(string $feature): bool
{
    $manifest = n45ForkManifest();
    $definition = $manifest['features'][$feature] ?? null;
    if (!is_array($definition)) {
        return false;
    }

    $enabled = boolval($definition['default'] ?? false);
    if (empty($definition['toggleable'])) {
        return $enabled;
    }

    $configured = $GLOBALS['n45_feature_flags'] ?? [];
    if (is_array($configured) && array_key_exists($feature, $configured)) {
        $configured_value = n45ParseBooleanFlag($configured[$feature]);
        if ($configured_value === null) {
            return false;
        }
        $enabled = $configured_value;
    }

    $environment_name = (string) ($definition['environment'] ?? '');
    $environment_value = $environment_name === '' ? false : getenv($environment_name);
    if ($environment_value !== false) {
        $environment_enabled = n45ParseBooleanFlag($environment_value);
        if ($environment_enabled === null) {
            return false;
        }
        $enabled = $environment_enabled;
    }

    return $enabled;
}

function n45RequireModule(string $module): void
{
    $manifest = n45ForkManifest();
    $definition = $manifest['modules'][$module] ?? null;
    if (!is_array($definition)) {
        throw new InvalidArgumentException("Unknown N45 module: $module");
    }

    $root = dirname(__DIR__);
    foreach (($definition['runtime_files'] ?? []) as $relative_path) {
        if (!is_string($relative_path) || !preg_match('#^functions/[a-z0-9_]+\.php$#', $relative_path)) {
            throw new RuntimeException("Unsafe runtime path in N45 module: $module");
        }
        $path = $root . '/' . $relative_path;
        if (!is_file($path)) {
            throw new RuntimeException("Missing runtime file for N45 module: $module");
        }
        require_once $path;
    }

    $GLOBALS['n45_loaded_modules'][$module] = true;
}

function n45LoadedModules(): array
{
    $loaded = $GLOBALS['n45_loaded_modules'] ?? [];
    return is_array($loaded) ? array_keys(array_filter($loaded)) : [];
}
