<?php

declare(strict_types=1);

namespace Laravel\Ronin\Concerns;

trait RefreshesPermissionCache
{
    /**
     * Boot the trait and register Eloquent event listeners to flush/invalidate the cache.
     *
     * @return void
     */
    public static function bootRefreshesPermissionCache(): void
    {
        $flushCache = function(mixed $model): void {
            // Clear the in-memory request-level cache
            \Laravel\Ronin\CacheRegistry::clear();

            if (! config('ronin.cache.enabled')) {
                return;
            }

            $prefix = config('ronin.cache.prefix', 'ronin');

            // Forget the global cached permissions list
            cache()->forget("{$prefix}:permissions:all");

            // If a role was modified or deleted, clear its specific cached permissions
            if ($model instanceof \Laravel\Ronin\Contracts\Role || method_exists($model, 'permissions')) {
                cache()->forget("{$prefix}:role:{$model->id}:permissions");
            }

            // Clear legacy/non-granular caches
            $cacheStore = cache()->store();
            if (method_exists($cacheStore, 'tags')) {
                $cacheStore->tags(config('ronin.cache.tag'))->flush();
            } else {
                cache()->forget(config('ronin.cache.key'));
            }
        };

        static::saved($flushCache);
        static::deleted($flushCache);
    }
}