<?php

declare(strict_types=1);

namespace Laravel\Ronin\Concerns;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Model;
use Laravel\Ronin\Contracts\Role;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Laravel\Ronin\Guard;

use Laravel\Ronin\CacheRegistry;

trait HasRoles
{
    /**
     * Users can have many roles.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(config('ronin.models.role'))->withTimestamps();
    }

    /**
     * Get the cached roles for this model.
     *
     * @return array
     */
    public function getCachedRoles(): array
    {
        $class = get_class($this);
        $id = $this->getKey();

        if (config('ronin.cache.request_memory', true)) {
            $cached = CacheRegistry::get('roles', $class, $id);
            if ($cached !== null) {
                return $cached;
            }
        }

        $fetchRoles = function() {
            $table = config('ronin.tables.roles', 'roles');
            return $this->roles()->get(["{$table}.id", "{$table}.slug", "{$table}.guard_name", "{$table}.special"])->toArray();
        };

        if (config('ronin.cache.enabled') && config('ronin.cache.granular')) {
            $prefix = config('ronin.cache.prefix', 'ronin');
            $cacheKey = "{$prefix}:user:{$id}:roles";
            $roles = cache()->remember($cacheKey, config('ronin.cache.length'), $fetchRoles);
        } else {
            $roles = $fetchRoles();
        }

        if (config('ronin.cache.request_memory', true)) {
            CacheRegistry::set('roles', $class, $id, $roles);
        }

        return $roles;
    }

    /**
     * Clear the cached roles and permissions for this model.
     *
     * @return void
     */
    public function forgetCachedRolesAndPermissions(): void
    {
        $class = get_class($this);
        $id = $this->getKey();

        CacheRegistry::clear();

        if (config('ronin.cache.enabled')) {
            $prefix = config('ronin.cache.prefix', 'ronin');
            cache()->forget("{$prefix}:user:{$id}:roles");
            cache()->forget("{$prefix}:user:{$id}:permissions");
        }
    }

    /**
     * Checks if the model has the given role assigned.
     * 
     * @param  string  $role
     * @param  string|null  $guardName
     * @return boolean
     */
    public function hasRole($role, ?string $guardName = null): bool
    {
        $slug = Str::slug($role);
        $guards = $guardName ? collect([$guardName]) : Guard::getNames($this);

        if (config('ronin.cache.enabled') && config('ronin.cache.granular')) {
            $roles = $this->getCachedRoles();
            foreach ($roles as $r) {
                if ($r['slug'] === $slug && $guards->contains($r['guard_name'])) {
                    return true;
                }
            }
            return false;
        }

        return (bool) $this->roles
            ->where('slug', $slug)
            ->whereIn('guard_name', $guards)
            ->count();
    }

    /**
     * Checks if the model has any of the given roles assigned.
     * 
     * @param  array  $roles
     * @return bool
     */
    public function hasAnyRole(...$roles): bool
    {
        foreach ($roles as $role) {
            if ($this->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks if the model has all of the given roles assigned.
     * 
     * @param  array  $roles
     * @return bool
     */
    public function hasAllRoles(...$roles): bool
    {
        foreach ($roles as $role) {
            if (! $this->hasRole($role)) {
                return false;
            }
        }

        return true;
    }

    public function hasRoles(): bool
    {
        if (config('ronin.cache.enabled') && config('ronin.cache.granular')) {
            return count($this->getCachedRoles()) > 0;
        }

        return (bool) $this->roles->count();
    }

    /**
     * Assign the specified roles to the model.
     * 
     * @param  mixed  $roles,...
     * @return self
     */
    public function assignRoles(...$roles): self
    {
        $roles = Arr::flatten($roles);
        $roles = $this->getRoles($roles);

        if (! $roles) {
            return $this;
        }

        $this->roles()->syncWithoutDetaching($roles);
        $this->forgetCachedRolesAndPermissions();

        return $this;
    }

    /**
     * Remove the specified roles from the model.
     * 
     * @param  mixed  $roles,...
     * @return self
     */
    public function removeRoles(...$roles): self
    {
        $roles = Arr::flatten($roles);
        $roles = $this->getRoles($roles);

        $this->roles()->detach($roles);
        $this->forgetCachedRolesAndPermissions();

        return $this;
    }

    /**
     * Sync the specified roles to the model.
     * 
     * @param  mixed  $roles,...
     * @return self
     */
    public function syncRoles(...$roles): self
    {
        $roles = Arr::flatten($roles);
        $roles = $this->getRoles($roles);

        $this->roles()->sync($roles);
        $this->forgetCachedRolesAndPermissions();

        return $this;
    }

    /**
     * Get the specified roles.
     * 
     * @param  array  $roles
     * @return array
     */
    protected function getRoles(array $roles)
    {
        if (empty($roles)) {
            return [];
        }

        $model = $this->getRoleModel();
        
        $models = [];
        $ids = [];
        $slugs = [];

        foreach ($roles as $item) {
            if ($item instanceof Model) {
                $models[] = $item;
            } elseif (is_numeric($item)) {
                $ids[] = (int) $item;
            } elseif (is_string($item)) {
                $slugs[] = Str::slug($item);
            }
        }

        $resolved = [];

        foreach ($models as $item) {
            $resolved[] = (int) $item->getKey();
        }

        if (!empty($ids) || !empty($slugs)) {
            $guards = Guard::getNames($this);

            $items = $model->newQuery()
                ->where(function ($query) use ($ids, $slugs, $guards) {
                    if (!empty($ids)) {
                        $query->whereIn('id', $ids);
                    }
                    if (!empty($slugs)) {
                        $query->orWhere(function ($q) use ($slugs, $guards) {
                            $q->whereIn('slug', $slugs)
                              ->whereIn('guard_name', $guards);
                        });
                    }
                })->get();

            foreach ($items as $item) {
                $resolved[] = (int) $item->id;
            }
        }

        return array_unique($resolved);
    }

    public function hasPermissionRoleFlags()
    {
        if (config('ronin.cache.enabled') && config('ronin.cache.granular')) {
            $roles = $this->getCachedRoles();
            foreach ($roles as $role) {
                if ($role['special'] !== null) {
                    return true;
                }
            }
            return false;
        }

        if ($this->hasRoles()) {
            return ($this->roles
                ->filter(function($role) {
                    return ! is_null($role->special);
                })->count()) >= 1;
        }

        return false;
    }

    /**
     * Get the model instance responsible for permissions.
     * 
     * @return \Laravel\Ronin\Contracts\Role
     */
    protected function getRoleModel(): Role
    {
        return app()->make(config('ronin.models.role'));
    }
}