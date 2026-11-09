<?php
// src/config.php - load and validate config.php.

/**
 * Return the validated config, or one value by dotted key (e.g. 'rules.unwatched.min_added_months').
 * Throws RuntimeException naming the problem if config.php is missing or invalid.
 */
function config(?string $key = null): mixed
{
    static $config = null;
    if ($config === null) {
        $config = loadConfig(dirname(__DIR__) . '/config.php');
    }
    if ($key === null) {
        return $config;
    }
    $value = $config;
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            throw new RuntimeException("Unknown config key: $key");
        }
        $value = $value[$part];
    }
    return $value;
}

function loadConfig(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException("Config file not found: $path");
    }
    $config = require $path;
    if (!is_array($config)) {
        throw new RuntimeException("$path must return an array");
    }
    $errors = validateConfig($config);
    if ($errors) {
        throw new RuntimeException("Invalid config in $path:\n  - " . implode("\n  - ", $errors));
    }
    return $config;
}

/** Return a list of problems with the config; empty if it is valid. */
function validateConfig(array $c): array
{
    $errors = [];
    $get = function (string $key) use ($c) {
        $v = $c;
        foreach (explode('.', $key) as $part) {
            if (!is_array($v) || !array_key_exists($part, $v)) {
                return null;
            }
            $v = $v[$part];
        }
        return $v;
    };
    $check = function (string $key, string $type, bool $positive = false) use ($get, &$errors) {
        $v = $get($key);
        $ok = match ($type) {
            'bool'     => is_bool($v),
            'int'      => is_int($v) && (!$positive || $v > 0),
            'string'   => is_string($v) && trim($v) !== '',
            'string[]' => is_array($v) && array_is_list($v) && !in_array(false, array_map('is_string', $v), true),
        };
        if (!$ok) {
            $want = $type === 'int' && $positive ? 'a whole number above 0' : match ($type) {
                'bool' => 'true or false', 'int' => 'a whole number', 'string' => 'non-empty text', 'string[]' => 'a list of text values',
            };
            $errors[] = "$key must be $want (got " . ($v === null ? 'nothing' : json_encode($v)) . ')';
        }
    };

    $check('rules.unwatched.enabled', 'bool');
    $check('rules.unwatched.min_added_months', 'int', true);
    $check('rules.movie_watched.enabled', 'bool');
    $check('rules.movie_watched.max_plays', 'int', true);
    $check('rules.movie_watched.min_last_viewed_months', 'int', true);
    $check('rules.movie_watched.min_added_months', 'int', true);
    $check('rules.show_watched.enabled', 'bool');
    $check('rules.show_watched.min_last_viewed_months', 'int', true);
    $check('rules.show_watched.min_added_months', 'int', true);
    $check('tmdb.protect_in_production', 'bool');
    $check('tmdb.protected_statuses', 'string[]');
    $check('tmdb.protect_unknown', 'bool');
    $check('countdown_days', 'int', true);
    $check('labels.keep', 'string');
    $check('labels.queue_prefix', 'string');
    $check('collections.movie', 'string');
    $check('collections.show', 'string');
    $check('dry_run', 'bool');

    if (is_string($get('labels.keep')) && $get('labels.keep') === $get('labels.queue_prefix')) {
        $errors[] = 'labels.keep and labels.queue_prefix must differ';
    }
    if (is_string($get('collections.movie')) && $get('collections.movie') === $get('collections.show')) {
        $errors[] = 'collections.movie and collections.show must differ';
    }
    return $errors;
}
