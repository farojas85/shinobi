<?php

declare(strict_types=1);

namespace Laravel\Ronin;

use Laravel\Ronin\Models\Role;
use Laravel\Ronin\Models\Permission;
use Laravel\Ronin\Tactics\AssignRoleTo;
use Laravel\Ronin\Tactics\GivePermissionTo;
use Laravel\Ronin\Tactics\RevokePermissionFrom;

class Shinobi
{
    /**
     * Fetch an instance of the Role model.
     */
    public function role(): Role
    {
        /** @var Role $model */
        $model = app()->make(config('ronin.models.role'));

        return $model;
    }

    /**
     * Fetch an instance of the Permission model.
     */
    public function permission(): Permission
    {
        /** @var Permission $model */
        $model = app()->make(config('ronin.models.permission'));

        return $model;
    }

    /**
     * Assign roles to a user.
     *
     * @param  mixed  ...$roles
     */
    public function assign(mixed ...$roles): AssignRoleTo
    {
        return new AssignRoleTo(...$roles);
    }

    /**
     * Give permissions to a user or role.
     *
     * @param  mixed  ...$permissions
     */
    public function give(mixed ...$permissions): GivePermissionTo
    {
        return new GivePermissionTo(...$permissions);
    }

    /**
     * Revoke permissions from a user or role.
     *
     * @param  mixed  ...$permissions
     */
    public function revoke(mixed ...$permissions): RevokePermissionFrom
    {
        return new RevokePermissionFrom(...$permissions);
    }
}
