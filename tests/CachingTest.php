<?php

declare(strict_types=1);

namespace Laravel\Ronin\Tests;

use Laravel\Ronin\Tests\User;
use Laravel\Ronin\Models\Role;
use Laravel\Ronin\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class CachingTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
    }

    #[Test]
    public function it_caches_has_permission_checks_at_request_level()
    {
        // Enable request-level memory cache, but disable cross-request cache
        config(['ronin.cache.enabled' => false]);
        config(['ronin.cache.request_memory' => true]);

        $user       = factory(User::class)->create();
        $permission = factory(Permission::class)->create(['slug' => 'test-permission']);
        $user->givePermissionTo($permission);

        // Warm up and verify
        $this->assertTrue($user->hasPermissionTo('test-permission'));

        // Listen for queries
        DB::enableQueryLog();

        // Perform checks again
        $this->assertTrue($user->hasPermissionTo('test-permission'));
        $this->assertTrue($user->hasPermissionTo('test-permission'));

        // Query log should be empty for the subsequent checks of this user instance
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertEmpty($queries, 'Duplicate checks should use request memory cache and execute zero DB queries.');
    }

    #[Test]
    public function it_uses_and_clears_granular_cache_on_redis_or_array_store()
    {
        // Enable both caches
        config(['ronin.cache.enabled' => true]);
        config(['ronin.cache.granular' => true]);
        config(['ronin.cache.request_memory' => true]);
        config(['ronin.cache.prefix' => 'ronin_test']);

        $user       = factory(User::class)->create();
        $role       = factory(Role::class)->create(['slug' => 'admin-role']);
        $permission = factory(Permission::class)->create(['slug' => 'delete-everything']);

        $role->givePermissionTo($permission);
        $user->assignRoles($role);

        // Fetch to trigger cache generation
        $this->assertTrue($user->hasPermissionTo('delete-everything'));

        // Verify keys exist in cache store
        $prefix = 'ronin_test';
        $userRolesKey = "{$prefix}:user:{$user->id}:roles";
        $userPermissionsKey = "{$prefix}:user:{$user->id}:permissions";
        $rolePermissionsKey = "{$prefix}:role:{$role->id}:permissions";

        $this->assertTrue(Cache::has($userRolesKey));
        $this->assertTrue(Cache::has($userPermissionsKey));
        $this->assertTrue(Cache::has($rolePermissionsKey));

        // Revoke role and verify invalidation
        $user->removeRoles($role);

        $this->assertFalse(Cache::has($userRolesKey), 'User roles cache should be cleared on modification');
        $this->assertFalse(Cache::has($userPermissionsKey), 'User permissions cache should be cleared on modification');
    }
}
