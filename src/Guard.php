<?php

declare(strict_types=1);

namespace Laravel\Ronin;

use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Model;

class Guard
{
    /**
     * Get the guard names for the given model class.
     *
     * @param string|Model $model
     * @return Collection
     */
    public static function getNames($model): Collection
    {
        $class = is_object($model) ? get_class($model) : $model;

        if (is_object($model) && method_exists($model, 'guardName')) {
            return collect($model->guardName());
        }

        if (is_object($model) && isset($model->guard_name)) {
            return collect($model->guard_name);
        }

        if (is_object($model) && property_exists($model, 'guard_name')) {
            $reflect = new \ReflectionClass($class);
            $props = $reflect->getDefaultProperties();
            if (isset($props['guard_name'])) {
                return collect($props['guard_name']);
            }
        }

        $guards = self::getGuardsForModel($class);

        if ($guards->isEmpty()) {
            return collect(config('auth.defaults.guard', 'web'));
        }

        return $guards;
    }

    /**
     * Get guards defined in config/auth.php that use the model's class.
     *
     * @param string $class
     * @return Collection
     */
    public static function getGuardsForModel(string $class): Collection
    {
        return collect(config('auth.guards', []))
            ->map(function ($guard) {
                if (! isset($guard['provider'])) {
                    return null;
                }
                return config("auth.providers.{$guard['provider']}.model");
            })
            ->filter(fn ($model) => $model === $class)
            ->keys();
    }

    /**
     * Get the default guard name for the given model/class.
     *
     * @param string|Model $model
     * @return string
     */
    public static function getDefaultName($model): string
    {
        $guards = self::getNames($model);

        if ($guards->isEmpty()) {
            return config('auth.defaults.guard', 'web');
        }

        return $guards->first();
    }
}
