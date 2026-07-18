<?php

declare(strict_types=1);

namespace Laravel\Ronin\Tests;

use Closure;
use Laravel\Ronin\Models\Role;
use Laravel\Ronin\Models\Permission;
use Laravel\Ronin\Tests\TestCase;
use Laravel\Ronin\Tests\User;
use Laravel\Ronin\Middleware\UserHasRole;
use Laravel\Ronin\Middleware\UserHasAllRoles;
use Laravel\Ronin\Middleware\UserHasAnyRole;
use Laravel\Ronin\Exceptions\PermissionNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\HttpException;

// =============================================================================
// OWASP Top Ten Security Test Suite — Laravel Ronin (Shinobi)
// =============================================================================
// CONTEXTO: Este es un PAQUETE LARAVEL, no una aplicación.
//
// ✅ Aplica al paquete: A01, A03, A06, A08
// ❌ NO aplica al paquete: A07 (el paquete no implementa login, sesiones
//    ni contraseñas — eso es responsabilidad de la app consumidora)
//
// Los tests que verifican el comportamiento del MIDDLEWARE ante guests vs
// usuarios autenticados pertenecen a A01 (Broken Access Control), ya que
// prueban el control de acceso del middleware del paquete — no la
// implementación de autenticación.
//
// WORKFLOW: BDD → TDD → Code Review → Verify
// =============================================================================

class OWASPSecurityTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // A01: BROKEN ACCESS CONTROL — Núcleo del paquete Shinobi
    // =========================================================================
    // Este es el riesgo principal para un paquete RBAC.
    // Cubre: middleware enforcement, flags especiales, herencia de permisos,
    // comportamiento con guests vs usuarios autenticados, escalada de privilegios.
    // =========================================================================

    /**
     * 🟦 BDD [A01-01]:
     * GIVEN un usuario autenticado sin roles asignados
     * WHEN intenta pasar por el middleware UserHasRole
     * THEN el middleware lo bloquea (el paquete hace cumplir el control de acceso)
     */
    #[Test]
    #[Group('security')]
    #[Group('A01')]
    public function a01_given_authenticated_user_with_no_roles_when_passes_middleware_then_is_blocked(): void
    {
        $this->expectException(HttpException::class);

        $user = factory(User::class)->create();
        $this->actingAs($user);

        $this->middleware(UserHasRole::class, 'admin');
    }

    /**
     * 🟦 BDD [A01-02]:
     * GIVEN un guest (no autenticado) que intenta pasar por el middleware
     * WHEN UserHasRole evalúa la petición
     * THEN retorna 401 (no puede avanzar sin autenticación previa)
     *
     * Nota de paquete: el paquete no gestiona autenticación, pero el middleware
     * sí debe responder correctamente cuando no hay usuario en la sesión.
     * Este es un test de CONTROL DE ACCESO del middleware, no de autenticación.
     */
    #[Test]
    #[Group('security')]
    #[Group('A01')]
    public function a01_given_guest_request_when_role_middleware_applied_then_returns_401(): void
    {
        $this->expectException(HttpException::class);

        // Given: NO hay usuario autenticado — la app consumidora no hizo login
        // El paquete no controla cómo se autentica el usuario, pero sí verifica
        // que el middleware bloquea cuando no hay sesión activa.
        $this->middleware(UserHasRole::class, 'admin');
    }

    /**
     * 🟦 BDD [A01-03]:
     * GIVEN una petición AJAX sin usuario autenticado
     * WHEN el middleware la procesa
     * THEN retorna response 401 (JSON-friendly, no abort que rompe AJAX)
     * THEN el closure $next NUNCA se ejecuta (acceso denegado)
     */
    #[Test]
    #[Group('security')]
    #[Group('A01')]
    public function a01_given_guest_ajax_request_when_middleware_applied_then_returns_401_and_blocks_next(): void
    {
        $middleware = app()->make(UserHasRole::class);
        $request    = Request::create('/api/admin', 'GET');
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');

        $nextCalled = false;

        $response = $middleware->handle(
            $request,
            function () use (&$nextCalled) {
                $nextCalled = true;
                return response('OK', 200);
            },
            'admin'
        );

        // El $next NUNCA debe haberse llamado — el paquete bloqueó el acceso
        $this->assertFalse($nextCalled, '[A01] El paquete debe bloquear el acceso, $next no debe ejecutarse');
        $this->assertEquals(401, $response->getStatusCode(), '[A01] Guest debe recibir 401 Unauthorized');
    }

    /**
     * 🟦 BDD [A01-04]:
     * GIVEN un usuario con el flag especial "no-access"
     * WHEN se verifica cualquier permiso
     * THEN SIEMPRE retorna false — el flag anula TODOS los permisos directos y por rol
     *
     * 🔴 TDD RED → 🟢 TDD GREEN: HasPermissions::hasPermissionTo() verifica flags primero.
     */
    #[Test]
    #[Group('security')]
    #[Group('A01')]
    public function a01_given_no_access_flag_when_checking_any_permission_then_always_false(): void
    {
        $user       = factory(User::class)->create();
        $permission = factory(Permission::class)->create();
        $role       = factory(Role::class)->create(['special' => 'no-access']);

        $user->givePermissionTo($permission);
        $user->assignRoles($role);

        $this->assertFalse(
            $user->fresh()->hasPermissionTo($permission->slug),
            '[A01] El flag no-access debe bloquear TODOS los permisos del usuario'
        );
    }

    /**
     * 🟦 BDD [A01-05]:
     * GIVEN un usuario con flag "all-access"
     * WHEN se verifica un permiso no asignado directamente
     * THEN retorna true (privilegio especial de superadmin)
     */
    #[Test]
    #[Group('security')]
    #[Group('A01')]
    public function a01_given_all_access_flag_when_permission_not_directly_assigned_then_still_authorized(): void
    {
        $user       = factory(User::class)->create();
        $permission = factory(Permission::class)->create();
        $adminRole  = factory(Role::class)->create(['special' => 'all-access']);

        $user->assignRoles($adminRole); // Solo el rol, sin permiso directo

        $this->assertTrue(
            $user->fresh()->hasPermissionTo($permission->slug),
            '[A01] El flag all-access debe otorgar acceso a cualquier permiso'
        );
    }

    /**
     * 🟦 BDD [A01-06]:
     * GIVEN un usuario con solo UNO de los roles requeridos
     * WHEN pasa por UserHasAllRoles que requiere AMBOS
     * THEN el middleware bloquea aunque tenga uno de los roles (no hay acceso parcial)
     */
    #[Test]
    #[Group('security')]
    #[Group('A01')]
    public function a01_given_user_with_only_one_role_when_all_roles_required_then_blocked(): void
    {
        $this->expectException(HttpException::class);

        $user   = factory(User::class)->create();
        $admin  = factory(Role::class)->create(['name' => 'Admin', 'slug' => 'admin']);
        $editor = factory(Role::class)->create(['name' => 'Editor', 'slug' => 'editor']);

        $user->assignRoles($editor); // Solo editor, falta admin
        $this->actingAs($user);

        $this->middleware(UserHasAllRoles::class, ['admin', 'editor']);
    }

    /**
     * 🟦 BDD [A01-07]:
     * GIVEN un usuario autenticado sin ningún rol
     * WHEN intenta acceder a una ruta protegida con UserHasAnyRole
     * THEN el middleware bloquea aunque esté autenticado
     */
    #[Test]
    #[Group('security')]
    #[Group('A01')]
    public function a01_given_authenticated_user_without_any_role_when_middleware_applied_then_blocked(): void
    {
        $this->expectException(HttpException::class);

        $user = factory(User::class)->create();
        $this->actingAs($user);

        $this->middleware(UserHasAnyRole::class, ['admin', 'moderator', 'editor']);
    }

    /**
     * 🟦 BDD [A01-08]:
     * GIVEN un usuario autenticado con el rol correcto
     * WHEN pasa por UserHasRole
     * THEN el middleware permite el acceso (retorna 200)
     */
    #[Test]
    #[Group('security')]
    #[Group('A01')]
    public function a01_given_authenticated_user_with_correct_role_when_middleware_applied_then_passes(): void
    {
        $user = factory(User::class)->create();
        $role = factory(Role::class)->create(['name' => 'Admin', 'slug' => 'admin']);
        $user->assignRoles($role);
        $this->actingAs($user);

        $status = $this->middleware(UserHasRole::class, 'admin');

        $this->assertEquals(200, $status, '[A01] Usuario con rol correcto debe tener acceso (200)');
    }

    // =========================================================================
    // A01 — PRIVILEGE ESCALATION (Escalada de Privilegios)
    // =========================================================================

    /**
     * 🟦 BDD [A01-09]:
     * GIVEN un slug de permiso inexistente
     * WHEN se usa en hasPermissionTo()
     * THEN lanza PermissionNotFoundException — NUNCA retorna true silenciosamente
     *
     * Un retorno silencioso de false sería aceptable, pero un retorno silencioso
     * de true sería un bypass de seguridad catastrófico. La excepción obliga al
     * desarrollador a manejar explícitamente los permisos inexistentes.
     */
    #[Test]
    #[Group('security')]
    #[Group('A01')]
    public function a01_given_nonexistent_permission_slug_when_checked_then_throws_not_found_exception(): void
    {
        $user = factory(User::class)->create();

        $this->expectException(PermissionNotFoundException::class);

        $user->hasPermissionTo('permiso-que-no-existe-en-bd');
    }

    /**
     * 🟦 BDD [A01-10]:
     * GIVEN un usuario con rol "editor" (permisos limitados)
     * WHEN se verifica un permiso que solo tiene "admin"
     * THEN hasPermissionTo() retorna false — sin escalada de privilegios
     */
    #[Test]
    #[Group('security')]
    #[Group('A01')]
    public function a01_given_editor_role_when_checking_admin_only_permission_then_denied(): void
    {
        $user             = factory(User::class)->create();
        $editorRole       = factory(Role::class)->create(['slug' => 'editor']);
        $editorPermission = factory(Permission::class)->create(['slug' => 'edit-posts']);
        $adminPermission  = factory(Permission::class)->create(['slug' => 'delete-all-users']);

        $editorRole->givePermissionTo($editorPermission);
        $user->assignRoles($editorRole);

        $this->assertTrue($user->fresh()->hasPermissionTo('edit-posts'));
        $this->assertFalse(
            $user->fresh()->hasPermissionTo('delete-all-users'),
            '[A01] El rol editor no debe poder escalar a permisos de admin'
        );
    }

    // =========================================================================
    // A03: INJECTION — Inputs maliciosos en slugs del paquete
    // =========================================================================
    // El paquete recibe slugs como parámetros de middleware y como identificadores
    // de permisos/roles. Estos pueden contener payloads de inyección.
    // =========================================================================

    /**
     * 🟦 BDD [A03-01]:
     * GIVEN un slug con payload SQL injection como parámetro del middleware
     * WHEN el middleware lo procesa
     * THEN el sistema lo maneja de forma segura (bloquea, no crashea)
     *
     * Eloquent ORM previene la inyección SQL. Este test verifica que el paquete
     * no expone rutas inseguras de query directa.
     */
    #[Test]
    #[Group('security')]
    #[Group('A03')]
    public function a03_given_sql_injection_slug_when_passed_to_middleware_then_handled_safely(): void
    {
        $this->expectException(HttpException::class);

        $user = factory(User::class)->create();
        $this->actingAs($user);

        // Slug con payload SQL injection — debe ser tratado como rol inexistente
        $this->middleware(UserHasRole::class, "' OR '1'='1");
    }

    /**
     * 🟦 BDD [A03-02]:
     * GIVEN un slug con payload XSS como nombre de permiso
     * WHEN se busca en hasPermissionTo()
     * THEN lanza PermissionNotFoundException (Eloquent no lo matchea, no lo ejecuta)
     */
    #[Test]
    #[Group('security')]
    #[Group('A03')]
    public function a03_given_xss_payload_as_permission_slug_when_checked_then_throws_not_found(): void
    {
        $user = factory(User::class)->create();

        $this->expectException(PermissionNotFoundException::class);

        $user->hasPermissionTo("<script>alert('xss')</script>");
    }

    // =========================================================================
    // A06: VULNERABLE AND OUTDATED COMPONENTS
    // =========================================================================

    /**
     * 🟦 BDD [A06-01]:
     * THEN no debe haber CVEs conocidos críticos o altos
     */
    #[Test]
    #[Group('security')]
    #[Group('A06')]
    public function a06_given_composer_lock_when_audited_then_no_known_cves(): void
    {
        $packageRoot = realpath(__DIR__ . '/../');

        $paths = [
            'composer',
            '/usr/local/bin/composer',
            '/usr/bin/composer',
            'php /usr/local/bin/composer',
            'php /usr/bin/composer',
        ];

        $output = null;
        foreach ($paths as $path) {
            $output = shell_exec("{$path} audit --format=json --working-dir=" . escapeshellarg($packageRoot) . " 2>/dev/null");
            if ($output) {
                break;
            }
        }

        $this->assertNotNull($output, 'composer audit no pudo ejecutarse');

        $result = json_decode($output, true);

        $this->assertIsArray($result, 'La salida de composer audit debe ser JSON válido');

        $advisories = $result['advisories'] ?? [];

        $this->assertEmpty(
            $advisories,
            sprintf('[A06] %d vulnerabilidad(es) en dependencias. Ejecuta: composer audit', count($advisories))
        );
    }

    // =========================================================================
    // A08: SOFTWARE AND DATA INTEGRITY FAILURES
    // =========================================================================
    // Riesgo en Shinobi: el caché de permisos podría servir datos obsoletos
    // (cache poisoning), otorgando permisos que ya fueron revocados.
    // =========================================================================

    /**
     * 🟦 BDD [A08-01]:
     * GIVEN un permiso asignado directamente a un usuario
     * WHEN el permiso es revocado
     * THEN el paquete refleja el cambio inmediatamente (sin cache poisoning)
     *
     * RefreshesPermissionCache::bootRefreshesPermissionCache() invalida el caché
     * automáticamente en eventos saved/deleted del modelo.
     */
    #[Test]
    #[Group('security')]
    #[Group('A08')]
    public function a08_given_permission_assigned_when_revoked_then_cache_reflects_change(): void
    {
        $user       = factory(User::class)->create();
        $permission = factory(Permission::class)->create(['slug' => 'manage-users']);

        $user->givePermissionTo($permission);

        $this->assertTrue(
            $user->fresh()->hasPermissionTo('manage-users'),
            '[A08] Precondición: el usuario debe tener el permiso'
        );

        $user->revokePermissionTo($permission);

        $this->assertFalse(
            $user->fresh()->hasPermissionTo('manage-users'),
            '[A08] Tras revocar, el usuario NO debe tener el permiso (sin cache poisoning)'
        );
    }

    /**
     * 🟦 BDD [A08-02]:
     * GIVEN un permiso heredado a través de un rol
     * WHEN el rol pierde ese permiso
     * THEN el usuario pierde el acceso inmediatamente (integridad del control de acceso)
     */
    #[Test]
    #[Group('security')]
    #[Group('A08')]
    public function a08_given_role_permission_when_revoked_from_role_then_user_loses_access(): void
    {
        $user       = factory(User::class)->create();
        $role       = factory(Role::class)->create(['slug' => 'moderator']);
        $permission = factory(Permission::class)->create(['slug' => 'delete-comments']);

        $role->givePermissionTo($permission);
        $user->assignRoles($role);

        $this->assertTrue(
            $user->fresh()->hasPermissionTo('delete-comments'),
            '[A08] Precondición: el usuario debe heredar el permiso del rol'
        );

        $role->revokePermissionTo($permission);

        $this->assertFalse(
            $user->fresh()->hasPermissionTo('delete-comments'),
            '[A08] El usuario no debe mantener acceso tras revocar el permiso del rol'
        );
    }
}
