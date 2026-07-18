# Laravel Ronin
[![Source](https://img.shields.io/badge/source-farbesdev/laravel--ronin-blue.svg?style=flat-square)](https://github.com/farojas85/shinobi)
[![License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](https://tldrlegal.com/license/mit-license)

A simple, light-weight, and highly-performant role-based permissions system for Laravel's Authorization Gate. Fully optimized for high-concurrency environments using Redis and request-level memory caching.

- Every user can have zero or more permissions.
- Every user can have zero or more roles.
- Every role can have zero or more permissions.
- Every role can have one of two special flags: `all-access` (bypasses all checks) and `no-access` (denies all permissions).
- **High-Performance Caching**: Powered by request-level in-memory caching and granular Redis keys.

---

## Installation

Install the package via Composer:

```bash
composer require farbesdev/laravel-ronin
```

### 1. Publish Configuration
To publish the configuration file, execute:

```bash
php artisan vendor:publish --provider="Laravel\Ronin\ShinobiServiceProvider" --tag="config"
```

This will create a `config/ronin.php` file in your application.

### 2. Run Migrations
Run the migrations to create the roles, permissions, and pivot tables:

```bash
php artisan migrate
```

---

## Configuration

In `config/ronin.php`, you can customize your caching strategy to suit your scalability requirements:

```php
'cache' => [
    // Enable or disable cross-request caching (e.g. using Redis)
    'enabled' => env('RONIN_CACHE_ENABLED', false),

    // Enable in-memory PHP array cache for the duration of the current HTTP request
    'request_memory' => true,

    // Enable granular caching per user/role specifically instead of the entire table
    'granular' => true,

    // Cache TTL in seconds (default is 24 hours)
    'length' => 86400,

    // Prefix used for Redis cache keys
    'prefix' => 'ronin',
],
```

---

## Caching Architecture

Laravel Ronin features a hybrid, supercharged caching mechanism:

1. **Request-Level Cache**: Stores role/permission checks in a static memory registry for the duration of a single HTTP request lifecycle. Even if caching is disabled, this ensures that multiple checks on the same user instance execute exactly **zero additional queries**.
2. **Granular Redis Cache**: Instead of caching the entire permissions database (which degrades under large datasets), Ronin segments the cache keys:
   - `ronin:user:{id}:roles` -> Stores user roles.
   - `ronin:user:{id}:permissions` -> Stores user direct permissions.
   - `ronin:role:{id}:permissions` -> Stores role permissions.
3. **Smart Invalidation**: When relationships are modified via `assignRoles`, `removeRoles`, `givePermissionTo`, `syncPermissions`, etc., only the keys affected are flushed.

---

## Usage

### Traits Setup
Add the `HasRolesAndPermissions` trait to your `User` model:

```php
use Laravel\Ronin\Concerns\HasRolesAndPermissions;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasRolesAndPermissions;
}
```

### Assigning Roles & Permissions

```php
// Assigning roles
$user->assignRoles('admin');
$user->removeRoles('admin');
$user->syncRoles(['editor', 'moderator']);

// Granting permissions
$user->givePermissionTo('edit.posts');
$user->revokePermissionTo('edit.posts');
$user->syncPermissions(['edit.posts', 'delete.posts']);
```

### Checking Permissions

Use Laravel's native `Gate` or the model helpers:

```php
// Native gate checks
if (Gate::allows('edit.posts')) {
    // ...
}

// User model checks
if ($user->hasPermissionTo('edit.posts')) {
    // ...
}

if ($user->hasRole('admin')) {
    // ...
}
```

### Blade Directives

```html
@can('edit.posts')
    <!-- User has permission -->
@endcan

@role('admin')
    <!-- User has the admin role -->
@endrole

@anyrole('editor', 'moderator')
    <!-- User has at least one of these roles -->
@endanyrole

@allroles('editor', 'moderator')
    <!-- User has all of these roles -->
@endallroles
```

### Middleware Protection

You can protect your routes using the built-in middlewares:

```php
Route::group(['middleware' => ['role:admin']], function () {
    // Routes protected by role
});

Route::group(['middleware' => ['permission:edit.posts']], function () {
    // Routes protected by permission
});
```

---

## Testing

Run the test suite with PHPUnit:

```bash
composer test
```

## License

This package is open-sourced software licensed under the [MIT License](LICENSE).
