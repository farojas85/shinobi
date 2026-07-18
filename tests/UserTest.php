<?php

declare(strict_types=1);

namespace Laravel\Ronin\Tests;

use Laravel\Ronin\Tests\User;
use Laravel\Ronin\Models\Role;
use Laravel\Ronin\Tests\TestCase;
use Laravel\Ronin\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

class UserTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_can_be_given_a_permission()
    {
        $user       = factory(User::class)->create();
        $permission = factory(Permission::class)->create();

        $this->assertCount(0, $user->permissions);
        
        $user->givePermissionTo($permission);

        $this->assertCount(1, $user->fresh()->permissions);
    }

    #[Test]
    public function it_can_be_given_a_permission_by_slug()
    {
        $user       = factory(User::class)->create();
        $permission = factory(Permission::class)->create();

        $this->assertCount(0, $user->permissions);
        
        $user->givePermissionTo($permission->slug);

        $this->assertCount(1, $user->fresh()->permissions);
    }

    #[Test]
    public function it_can_be_given_multiple_permissions()
    {
        $user        = factory(User::class)->create();
        $permissions = factory(Permission::class, 5)->create();
        
        $this->assertCount(0, $user->permissions);

        $user->givePermissionTo($permissions);

        $this->assertCount(5, $user->fresh()->permissions);
    }

    #[Test]
    public function it_can_be_given_multiple_permissions_by_slug()
    {
        $user        = factory(User::class)->create();
        $permissions = factory(Permission::class, 5)->create()->pluck('slug');

        $this->assertCount(0, $user->permissions);
        
        $user->givePermissionTo($permissions);

        $this->assertCount(5, $user->fresh()->permissions);
    }

    #[Test]
    public function it_can_be_revoked_a_permission()
    {
        $user       = factory(User::class)->create();
        $permission = factory(Permission::class)->create();
        
        $user->givePermissionTo($permission);
        
        $this->assertCount(1, $user->permissions);

        $user->revokePermissionTo($permission);

        $this->assertCount(0, $user->fresh()->permissions);
    }

    #[Test]
    public function it_can_be_revoked_a_permission_by_slug()
    {
        $user       = factory(User::class)->create();
        $permission = factory(Permission::class)->create();
        
        $user->givePermissionTo($permission->slug);
        
        $this->assertCount(1, $user->permissions);

        $user->revokePermissionTo($permission->slug);

        $this->assertCount(0, $user->fresh()->permissions);
    }

    #[Test]
    public function it_can_be_revoked_multiple_permissions()
    {
        $user        = factory(User::class)->create();
        $permissions = factory(Permission::class, 5)->create();
        
        $user->givePermissionTo($permissions);
        
        $this->assertCount(5, $user->permissions);

        $user->revokePermissionTo($permissions);

        $this->assertCount(0, $user->fresh()->permissions);
    }

    #[Test]
    public function it_can_be_revoked_multiple_permissions_by_slugs()
    {
        $user        = factory(User::class)->create();
        $permissions = factory(Permission::class, 5)->create()->pluck('slug');
        
        $user->givePermissionTo($permissions);
        
        $this->assertCount(5, $user->permissions);

        $user->revokePermissionTo($permissions);

        $this->assertCount(0, $user->fresh()->permissions);
    }

    #[Test]
    public function it_can_assert_has_a_given_permission()
    {
        $user       = factory(User::class)->create();
        $permission = factory(Permission::class)->create();

        $this->assertFalse($user->hasPermissionTo($permission->slug));
        
        $user->givePermissionTo($permission);

        $this->assertTrue($user->fresh()->hasPermissionTo($permission->slug));
    }

    #[Test]
    public function it_can_assert_does_not_have_a_given_permission()
    {
        $user       = factory(User::class)->create();
        $permission = factory(Permission::class)->create();

        $this->assertFalse($user->hasPermissionTo($permission->slug));
    }

    #[Test]
    public function it_can_be_assigned_a_role()
    {
        $user = factory(User::class)->create();
        $role = factory(Role::class)->create();

        $this->assertCount(0, $user->roles);
        
        $user->assignRoles($role);

        $this->assertCount(1, $user->fresh()->roles);
    }

    #[Test]
    public function it_can_be_assigned_a_role_by_slug()
    {
        $user = factory(User::class)->create();
        $role = factory(Role::class)->create();

        $this->assertCount(0, $user->roles);
        
        $user->assignRoles($role->slug);

        $this->assertCount(1, $user->fresh()->roles);
    }

    #[Test]
    public function it_can_be_assigned_multiple_roles()
    {
        $user  = factory(User::class)->create();
        $roles = factory(Role::class, 3)->create();

        $this->assertCount(0, $user->roles);
        
        $user->assignRoles($roles);

        $this->assertCount(3, $user->fresh()->roles);
    }

    #[Test]
    public function it_can_be_assigned_multiple_roles_by_slugs()
    {
        $user  = factory(User::class)->create();
        $roles = factory(Role::class, 3)->create()->pluck('slug');

        $this->assertCount(0, $user->roles);
        
        $user->assignRoles($roles);

        $this->assertCount(3, $user->fresh()->roles);
    }

    #[Test]
    public function it_has_a_given_permission_through_role()
    {
        $user       = factory(User::class)->create();
        $role       = factory(Role::class)->create();
        $permission = factory(Permission::class)->create();
        
        $role->givePermissionTo($permission);

        $this->assertFalse($user->hasPermissionTo($permission->slug));
        
        $user->assignRoles($role);

        $this->assertTrue($user->fresh()->hasPermissionTo($permission->slug));
    }

    #[Test]
    public function it_has_no_permissions_when_assigned_a_role_with_a_no_access_flag()
    {
        $user       = factory(User::class)->create();
        $role       = factory(Role::class)->create(['special' => 'no-access']);
        $permission = factory(Permission::class)->create();
        
        $user->givePermissionTo($permission);

        $this->assertTrue($user->fresh()->hasPermissionTo($permission->slug));
        
        $user->assignRoles($role);

        $this->assertFalse($user->fresh()->hasPermissionTo($permission->slug));
    }

    #[Test]
    public function it_can_verify_it_has_defined_role()
    {
        $user = factory(User::class)->create();
        $role = factory(Role::class)->create();

        $this->assertFalse($user->fresh()->hasRole($role->slug));

        $user->assignRoles($role);

        $this->assertTrue($user->fresh()->hasRole($role->slug));
    }

    #[Test]
    public function it_can_verify_it_has_any_defined_role()
    {
        $editor = factory(Role::class)->create([
            'name' => 'Editor',
            'slug' => 'editor',
        ]);
            
        $moderator = factory(Role::class)->create([
            'name' => 'Moderator',
            'slug' => 'moderator',
        ]);
                
        $user = factory(User::class)->create();

        $this->assertFalse($user->fresh()->hasAnyRole('moderator', 'editor'));

        $user->assignRoles($editor);

        $this->assertTrue($user->fresh()->hasAnyRole('moderator', 'editor'));
    }

     #[Test]
    public function it_can_verify_it_has_all_defined_roles()
    {
        $editor = factory(Role::class)->create([
            'name' => 'Editor',
            'slug' => 'editor',
        ]);
            
        $moderator = factory(Role::class)->create([
            'name' => 'Moderator',
            'slug' => 'moderator',
        ]);
        
        $user = factory(User::class)->create();

        $this->assertFalse($user->fresh()->hasAllRoles('moderator', 'editor'));

        $user->assignRoles($editor);

        $this->assertFalse($user->fresh()->hasAllRoles('moderator', 'editor'));

        $user->assignRoles($moderator);

        $this->assertTrue($user->fresh()->hasAllRoles('moderator', 'editor'));
    }

    #[Test]
    public function it_handles_multiple_guards_correctly()
    {
        $user = factory(User::class)->create();
        
        $webPermission = factory(Permission::class)->create([
            'slug' => 'edit-posts',
            'guard_name' => 'web',
        ]);
        $apiPermission = factory(Permission::class)->create([
            'slug' => 'edit-posts',
            'guard_name' => 'api',
        ]);

        $user->givePermissionTo($webPermission);

        // Standard lookup without guard should resolve using the default guard ('web' in this case)
        $this->assertTrue($user->fresh()->hasPermissionTo('edit-posts'));

        // Querying for 'api' guard should return false since only 'web' was granted
        $this->assertFalse($user->fresh()->hasPermissionTo($apiPermission->slug, 'api'));

        // Giving the api permission
        $user->givePermissionTo($apiPermission);
        $this->assertTrue($user->fresh()->hasPermissionTo('edit-posts', 'api'));
    }

    #[Test]
    public function it_can_check_any_and_all_permissions()
    {
        $user = factory(User::class)->create();
        $p1 = factory(Permission::class)->create(['slug' => 'p1']);
        $p2 = factory(Permission::class)->create(['slug' => 'p2']);

        $this->assertFalse($user->fresh()->hasAnyPermission('p1', 'p2'));
        
        $user->givePermissionTo($p1);
        $this->assertTrue($user->fresh()->hasAnyPermission('p1', 'p2'));
        $this->assertFalse($user->fresh()->hasAllPermissions('p1', 'p2'));

        $user->givePermissionTo($p2);
        $this->assertTrue($user->fresh()->hasAllPermissions('p1', 'p2'));
    }
}