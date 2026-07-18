<?php

declare(strict_types=1);

namespace Laravel\Ronin;

class CacheRegistry
{
    /**
     * @var array<string, array<string, array<mixed, mixed>>>
     */
    protected static array $cache = [];

    /**
     * Get a value from the request cache.
     *
     * @param  string  $type
     * @param  string  $class
     * @param  mixed   $id
     * @param  mixed   $default
     * @return mixed
     */
    public static function get(string $type, string $class, mixed $id, mixed $default = null): mixed
    {
        return self::$cache[$type][$class][$id] ?? $default;
    }

    /**
     * Set a value in the request cache.
     *
     * @param  string  $type
     * @param  string  $class
     * @param  mixed   $id
     * @param  mixed   $value
     * @return void
     */
    public static function set(string $type, string $class, mixed $id, mixed $value): void
    {
        self::$cache[$type][$class][$id] = $value;
    }

    /**
     * Clear the request cache.
     *
     * @return void
     */
    public static function clear(): void
    {
        self::$cache = [];
    }
}
