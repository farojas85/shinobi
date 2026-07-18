# Laravel Ronin
[![Source](https://img.shields.io/badge/source-farbesdev/laravel--ronin-blue.svg?style=flat-square)](https://github.com/farojas85/shinobi)
[![License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](https://tldrlegal.com/license/mit-license)

Un sistema de permisos basado en roles simple, ligero y altamente eficiente para el Authorization Gate nativo de Laravel. Totalmente optimizado para entornos de alta concurrencia mediante el uso de caché híbrida en memoria y Redis.

- Cada usuario puede tener cero o más permisos.
- Cada usuario puede tener cero o más roles.
- Cada rol puede tener cero o más permisos.
- Cada rol puede tener uno de dos flags especiales: `all-access` (otorga todos los accesos) y `no-access` (deniega todos los permisos).
- **Caché de Alto Rendimiento**: Potenciado por almacenamiento en caché estática en memoria a nivel de petición y llaves granulares en Redis.

---

## Instalación

Instala el paquete a través de Composer:

```bash
composer require farbesdev/laravel-ronin
```

### 1. Publicar Configuración
Para publicar el archivo de configuración, ejecuta:

```bash
php artisan vendor:publish --provider="Laravel\Ronin\ShinobiServiceProvider" --tag="config"
```

Esto creará un archivo `config/ronin.php` en tu aplicación.

### 2. Ejecutar Migraciones
Ejecuta las migraciones para crear las tablas de roles, permisos y tablas pivote correspondientes:

```bash
php artisan migrate
```

---

## Configuración

En `config/ronin.php`, puedes personalizar tu estrategia de caché para adaptarla a tus requisitos de escalabilidad:

```php
'cache' => [
    // Habilitar o deshabilitar la caché entre peticiones (ej. usando Redis)
    'enabled' => env('RONIN_CACHE_ENABLED', false),

    // Habilitar la caché en memoria local para la petición HTTP actual
    'request_memory' => true,

    // Habilitar caché granular por usuario/rol en lugar de toda la tabla de permisos
    'granular' => true,

    // TTL de caché en segundos (por defecto es 24 horas)
    'length' => 86400,

    // Prefijo usado para las llaves de caché en Redis
    'prefix' => 'ronin',
],
```

---

## Arquitectura de Caché

Laravel Ronin incluye un mecanismo híbrido de caché:

1. **Caché a nivel de Petición (Request)**: Almacena los resultados de verificación de roles/permisos en memoria estática para la petición HTTP actual. Incluso si la caché persistente está desactivada, esto garantiza que múltiples verificaciones del mismo usuario ejecuten exactamente **cero consultas a la base de datos**.
2. **Caché Granular en Redis**: En lugar de guardar toda la base de datos de permisos (lo cual degrada el rendimiento al crecer el volumen de datos), Ronin segmenta las llaves:
   - `ronin:user:{id}:roles` -> Guarda los roles del usuario.
   - `ronin:user:{id}:permissions` -> Guarda los permisos directos del usuario.
   - `ronin:role:{id}:permissions` -> Guarda los permisos de un rol.
3. **Invalidación Inteligente**: Al modificar relaciones mediante `assignRoles`, `removeRoles`, `givePermissionTo`, `syncPermissions`, etc., únicamente se invalidan las llaves específicas afectadas.

---

## Uso

### Configuración del Modelo
Añade el trait `HasRolesAndPermissions` a tu modelo `User`:

```php
use Laravel\Ronin\Concerns\HasRolesAndPermissions;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasRolesAndPermissions;
}
```

### Asignación de Roles y Permisos

```php
// Asignación de roles
$user->assignRoles('admin');
$user->removeRoles('admin');
$user->syncRoles(['editor', 'moderator']);

// Otorgar permisos
$user->givePermissionTo('edit.posts');
$user->revokePermissionTo('edit.posts');
$user->syncPermissions(['edit.posts', 'delete.posts']);
```

### Validación de Permisos

Utiliza la Gate nativa de Laravel o los métodos del modelo:

```php
// Uso de Gates nativos
if (Gate::allows('edit.posts')) {
    // ...
}

// Métodos del modelo User
if ($user->hasPermissionTo('edit.posts')) {
    // ...
}

if ($user->hasRole('admin')) {
    // ...
}
```

### Directivas Blade

```html
@can('edit.posts')
    <!-- El usuario tiene el permiso -->
@endcan

@role('admin')
    <!-- El usuario tiene el rol admin -->
@endrole

@anyrole('editor', 'moderator')
    <!-- El usuario tiene al menos uno de estos roles -->
@endanyrole

@allroles('editor', 'moderator')
    <!-- El usuario tiene todos estos roles -->
@endallroles
```

### Protección con Middlewares

Puedes proteger tus rutas con los middlewares incluidos:

```php
Route::group(['middleware' => ['role:admin']], function () {
    // Rutas protegidas por rol
});

Route::group(['middleware' => ['permission:edit.posts']], function () {
    // Rutas protegidas por permiso
});
```

---

## Pruebas

Ejecuta la suite de pruebas con PHPUnit:

```bash
composer test
```

## Licencia

Este paquete es software de código abierto con licencia [MIT](LICENSE.md).
