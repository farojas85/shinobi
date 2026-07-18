<?php

declare(strict_types=1);

namespace Laravel\Ronin\Concerns;

use Illuminate\Support\Arr;
use Illuminate\Database\Eloquent\Model;
use Laravel\Ronin\Facades\Shinobi;
use Laravel\Ronin\Contracts\Permission;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Laravel\Ronin\Exceptions\PermissionNotFoundException;
use Laravel\Ronin\Guard;
use Laravel\Ronin\CacheRegistry;

trait HasPermissions
{
    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(config('ronin.models.permission'))->withTimestamps();
    }

    /**
     * Get the cached direct permissions for this model.
     *
     * @return array
     */
    public function getCachedDirectPermissions(): array
    {
        $class = get_class($this);
        $id = $this->getKey();

        if (config('ronin.cache.request_memory', true)) {
            $cached = CacheRegistry::get('permissions', $class, $id);
            if ($cached !== null) {
                return $cached;
            }
        }

        $fetchPermissions = function() {
            $table = config('ronin.tables.permissions', 'permissions');
            return $this->permissions()->get(["{$table}.id", "{$table}.slug", "{$table}.guard_name"])->toArray();
        };

        if (config('ronin.cache.enabled') && config('ronin.cache.granular')) {
            $prefix = config('ronin.cache.prefix', 'ronin');
            $cacheKey = "{$prefix}:user:{$id}:permissions";
            $permissions = cache()->remember($cacheKey, config('ronin.cache.length'), $fetchPermissions);
        } else {
            $permissions = $fetchPermissions();
        }

        if (config('ronin.cache.request_memory', true)) {
            CacheRegistry::set('permissions', $class, $id, $permissions);
        }

        return $permissions;
    }

    /**
     * Get the cached permissions for a specific role.
     *
     * @param  int  $roleId
     * @return array
     */
    public function getCachedRolePermissions(int $roleId): array
    {
        if (config('ronin.cache.request_memory', true)) {
            $cached = CacheRegistry::get('role_permissions', 'role', $roleId);
            if ($cached !== null) {
                return $cached;
            }
        }

        $fetchPermissions = function() use ($roleId) {
            $roleModelClass = config('ronin.models.role');
            $role = app()->make($roleModelClass)->find($roleId);
            if (!$role) {
                return [];
            }
            $table = config('ronin.tables.permissions', 'permissions');
            return $role->permissions()->get(["{$table}.id", "{$table}.slug", "{$table}.guard_name"])->toArray();
        };

        if (config('ronin.cache.enabled') && config('ronin.cache.granular')) {
            $prefix = config('ronin.cache.prefix', 'ronin');
            $cacheKey = "{$prefix}:role:{$roleId}:permissions";
            $permissions = cache()->remember($cacheKey, config('ronin.cache.length'), $fetchPermissions);
        } else {
            $permissions = $fetchPermissions();
        }

        if (config('ronin.cache.request_memory', true)) {
            CacheRegistry::set('role_permissions', 'role', $roleId, $permissions);
        }

        return $permissions;
    }

    /**
     * Clear cached permissions for this model.
     *
     * @return void
     */
    public function forgetCachedPermissions(): void
    {
        $class = get_class($this);
        $id = $this->getKey();

        CacheRegistry::clear();

        if (method_exists($this, 'forgetCachedRolesAndPermissions')) {
            $this->forgetCachedRolesAndPermissions();
            return;
        }

        if (config('ronin.cache.enabled')) {
            $prefix = config('ronin.cache.prefix', 'ronin');
            if ($this instanceof \Laravel\Ronin\Contracts\Role) {
                cache()->forget("{$prefix}:role:{$id}:permissions");
            } else {
                cache()->forget("{$prefix}:user:{$id}:permissions");
            }
        }
    }

    /**
     * The mothergoose check. Runs through each scenario provided
     * by Shinobi - checking for special flags, role permissions, and
     * individual user permissions; in that order.
     * 
     * @param  Permission|string  $permission
     * @param  string|null  $guardName
     * @return boolean
     */
    public function hasPermissionTo($permission, ?string $guardName = null): bool
    {
        $class = get_class($this);
        $id = $this->getKey();
        $slug = is_string($permission) ? $permission : $permission->slug;
        $guard = $guardName ?: Guard::getDefaultName($this);

        // In-Memory cache check (Fast Path)
        if (config('ronin.cache.request_memory', true)) {
            $cacheKey = "{$slug}:{$guard}";
            $cached = CacheRegistry::get('has_permission', $class, $id);
            if ($cached !== null && isset($cached[$cacheKey])) {
                return $cached[$cacheKey];
            }
        }

        // Check role flags
        if (method_exists($this, 'hasPermissionRoleFlags') 
            && $this->hasPermissionRoleFlags() 
            && method_exists($this, 'hasPermissionThroughRoleFlag')
        ) {
            $hasIt = $this->hasPermissionThroughRoleFlag();
            if (config('ronin.cache.request_memory', true)) {
                $cacheKey = "{$slug}:{$guard}";
                $cached = CacheRegistry::get('has_permission', $class, $id) ?: [];
                $cached[$cacheKey] = $hasIt;
                CacheRegistry::set('has_permission', $class, $id, $cached);
            }
            return $hasIt;
        }

        if ((method_exists($this, 'hasPermissionFlags') and $this->hasPermissionFlags())) {
            $hasIt = $this->hasPermissionThroughFlag();
            if (config('ronin.cache.request_memory', true)) {
                $cacheKey = "{$slug}:{$guard}";
                $cached = CacheRegistry::get('has_permission', $class, $id) ?: [];
                $cached[$cacheKey] = $hasIt;
                CacheRegistry::set('has_permission', $class, $id, $cached);
            }
            return $hasIt;
        }
        
        $guards = $guardName ? collect([$guardName]) : Guard::getNames($this);

        // Fetch permission if we pass through a string
        if (is_string($permission)) {
            $model = $this->getPermissionModel();
            
            if ($model instanceof \Illuminate\Database\Eloquent\Collection) {
                $permission = $model
                    ->where('slug', $permission)
                    ->whereIn('guard_name', $guards)
                    ->first();
            } else {
                $permission = $model
                    ->where('slug', $permission)
                    ->whereIn('guard_name', $guards)
                    ->first();
            }

            if (! $permission) {
                throw new PermissionNotFoundException();
            }
        }

        // Verify guard match if guardName was explicitly requested
        if ($guardName && $permission->guard_name !== $guardName) {
            return false;
        }

        $hasIt = false;

        if (config('ronin.cache.enabled') && config('ronin.cache.granular')) {
            // Check direct permissions
            $directPermissions = $this->getCachedDirectPermissions();
            foreach ($directPermissions as $p) {
                if ($p['slug'] === $permission->slug && $guards->contains($p['guard_name'])) {
                    $hasIt = true;
                    break;
                }
            }

            // Check role permissions
            if (!$hasIt && method_exists($this, 'getCachedRoles')) {
                $roles = $this->getCachedRoles();
                foreach ($roles as $role) {
                    $rolePermissions = $this->getCachedRolePermissions((int) $role['id']);
                    foreach ($rolePermissions as $p) {
                        if ($p['slug'] === $permission->slug && $guards->contains($p['guard_name'])) {
                            $hasIt = true;
                            break 2;
                        }
                    }
                }
            }
        } else {
            // Check role permissions
            if (method_exists($this, 'hasPermissionThroughRole') and $this->hasPermissionThroughRole($permission)) {
                $hasIt = true;
            }
            // Check user permission
            elseif ($this->hasPermission($permission, $guards)) {
                $hasIt = true;
            }
        }

        if (config('ronin.cache.request_memory', true)) {
            $cacheKey = "{$permission->slug}:{$permission->guard_name}";
            $cached = CacheRegistry::get('has_permission', $class, $id) ?: [];
            $cached[$cacheKey] = $hasIt;
            CacheRegistry::set('has_permission', $class, $id, $cached);
        }

        return $hasIt;
    }

    /**
     * Checks if the user has any of the given permissions assigned.
     * 
     * @param  mixed  ...$permissions
     * @return bool
     */
    public function hasAnyPermission(...$permissions): bool
    {
        $permissions = Arr::flatten($permissions);

        foreach ($permissions as $permission) {
            if ($this->hasPermissionTo($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks if the user has all of the given permissions assigned.
     * 
     * @param  mixed  ...$permissions
     * @return bool
     */
    public function hasAllPermissions(...$permissions): bool
    {
        $permissions = Arr::flatten($permissions);

        foreach ($permissions as $permission) {
            if (! $this->hasPermissionTo($permission)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Exception-throwing permission checker.
     * 
     * @param  Permission|string  $permission
     * @param  string|null  $guardName
     * @return bool
     * @throws PermissionNotFoundException
     */
    public function checkPermissionTo($permission, ?string $guardName = null): bool
    {
        if (! $this->hasPermissionTo($permission, $guardName)) {
            throw new PermissionNotFoundException();
        }

        return true;
    }
    
    /**
     * Give the specified permissions to the model.
     * 
     * @param  array  $permissions
     * @return self
     */
    public function givePermissionTo(...$permissions): self
    {        
        $permissions = Arr::flatten($permissions);
        $permissions = $this->getPermissions($permissions);

        if (! $permissions) {
            return $this;
        }

        $this->permissions()->syncWithoutDetaching($permissions);
        $this->forgetCachedPermissions();

        return $this;
    }

    /**
     * Revoke the specified permissions from the model.
     * 
     * @param  array  $permissions
     * @return self
     */
    public function revokePermissionTo(...$permissions): self
    {
        $permissions = Arr::flatten($permissions);
        $permissions = $this->getPermissions($permissions);

        $this->permissions()->detach($permissions);
        $this->forgetCachedPermissions();

        return $this;
    }

    /**
     * Sync the specified permissions against the model.
     * 
     * @param  array  $permissions
     * @return self
     */
    public function syncPermissions(...$permissions): self
    {
        $permissions = Arr::flatten($permissions);
        $permissions = $this->getPermissions($permissions);

        $this->permissions()->sync($permissions);
        $this->forgetCachedPermissions();

        return $this;
    }

    /**
     * Get the specified permissions.
     * 
     * @param  array<int, mixed>  $collection
     * @return array<int, int>
     */
    protected function getPermissions(array $collection): array
    {
        if (empty($collection)) {
            return [];
        }

        $model = $this->getPermissionModel();
        
        $models = [];
        $ids = [];
        $slugs = [];

        foreach ($collection as $item) {
            if ($item instanceof Model) {
                $models[] = $item;
            } elseif (is_numeric($item)) {
                $ids[] = (int) $item;
            } elseif (is_string($item)) {
                $slugs[] = $item;
            }
        }

        $resolved = [];

        foreach ($models as $item) {
            $resolved[] = (int) $item->getKey();
        }

        if (!empty($ids) || !empty($slugs)) {
            $guards = Guard::getNames($this);

            if ($model instanceof \Illuminate\Database\Eloquent\Collection) {
                $items = $model->filter(function ($permission) use ($ids, $slugs, $guards) {
                    $matchId = in_array((int) $permission->id, $ids, true);
                    $matchSlug = in_array($permission->slug, $slugs, true) && $guards->contains($permission->guard_name);
                    return $matchId || $matchSlug;
                });
            } else {
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
            }

            foreach ($items as $item) {
                $resolved[] = (int) $item->id;
            }
        }

        return array_unique($resolved);
    }

    /**
     * Checks if the user has the given permission assigned.
     * 
     * @param  \Laravel\Ronin\Models\Permission  $permission
     * @param  \Illuminate\Support\Collection|null  $guards
     * @return boolean
     */
    protected function hasPermission($permission, $guards = null): bool
    {
        $slug = $permission;
        if ($permission instanceof Permission) {
            $slug = $permission->slug;
        }

        $guards = $guards ?: Guard::getNames($this);

        return (bool) $this->permissions
            ->where('slug', $slug)
            ->whereIn('guard_name', $guards)
            ->count();
    }

    /**
     * Get the model instance responsible for permissions.
     * 
     * @return mixed
     */
    protected function getPermissionModel(): mixed
    {
        $permissionModel = app()->make(config('ronin.models.permission'));

        if (! config('ronin.cache.enabled')) {
            return $permissionModel;
        }

        if (config('ronin.cache.granular')) {
            $prefix = config('ronin.cache.prefix', 'ronin');
            $cacheKey = "{$prefix}:permissions:all";
            
            return cache()->remember($cacheKey, config('ronin.cache.length'), function () use ($permissionModel) {
                return $permissionModel->all();
            });
        }

        $cacheStore = cache()->store();

        // Handle tags fallback if cache store doesn't support tags
        $hasTags = method_exists($cacheStore, 'tags');
        
        $callback = function () use ($permissionModel) {
            return $permissionModel->with('roles')->get();
        };

        if ($hasTags) {
            return $cacheStore->tags(config('ronin.cache.tag'))->remember(
                'permissions',
                config('ronin.cache.length'),
                $callback
            );
        }

        return $cacheStore->remember(
            config('ronin.cache.key'),
            config('ronin.cache.length'),
            $callback
        );
    }
}
