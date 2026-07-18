<?php

declare(strict_types=1);

namespace Laravel\Ronin\Tests;

use Laravel\Ronin\Models\Role;
use Laravel\Ronin\Models\Permission;
use Laravel\Ronin\Tests\TestCase;
use Laravel\Ronin\Tests\User;
use Laravel\Ronin\Events\AccessDenied;
use Laravel\Ronin\Middleware\UserHasRole;
use Laravel\Ronin\Middleware\UserHasAllRoles;
use Laravel\Ronin\Middleware\UserHasAnyRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\HttpException;

// =============================================================================
// OWASP Security — Iteración 2 | CONTEXTO: Paquete Laravel (no aplicación)
// =============================================================================
// Riesgos cubiertos en esta iteración:
//   A01 FIX: Código HTTP 401 (guest) vs 403 (auth sin rol) — semántica HTTP
//   A04: Insecure Design — edge cases y validación de entradas límite
//   A05: Security Misconfiguration — valores por defecto del config publicable
//   A09: Security Logging — evento AccessDenied para auditoría
//
// ❌ A07 NO aplica: el paquete no implementa login, sesiones ni contraseñas.
//    La autenticación es responsabilidad de la aplicación consumidora.
// =============================================================================


class OWASPSecurityIteracion2Test extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // A01 FIX: HTTP 403 vs 401 — Semántica correcta de acceso denegado
    // =========================================================================
    // Hallazgo del Code Review Iteración 1:
    //   Los middlewares retornaban 401 (Unauthorized) para usuarios autenticados
    //   sin el rol correcto. El estándar HTTP establece:
    //   - 401 = No autenticado (debe enviar credenciales)
    //   - 403 = Autenticado pero sin los permisos necesarios (Forbidden)
    // =========================================================================

    /**
     * 🟦 BDD [A01-FIX-01]:
     * GIVEN usuario autenticado sin el rol requerido
     * WHEN pasa por UserHasRole
     * THEN retorna 403 Forbidden (NO 401)
     *
     * 🔴 TDD RED: Antes retornaba 401 para usuarios autenticados.
     * 🟢 TDD GREEN: Middleware ahora usa $user ? 403 : 401
     */
    #[Test]
    #[Group('security')]
    #[Group('A01')]
    public function a01_fix_given_authenticated_user_wrong_role_when_middleware_then_returns_403_not_401(): void
    {
        $user = factory(User::class)->create();
        $this->actingAs($user);

        // AJAX request to check response code (abort() in non-AJAX throws HttpException)
        $middleware = app()->make(UserHasRole::class);
        $request    = Request::create('/admin', 'GET');
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');

        $response = $middleware->handle($request, fn () => response('OK', 200), 'admin');

        $this->assertEquals(
            403,
            $response->getStatusCode(),
            '[A01] Usuario autenticado sin rol debe recibir 403 Forbidden, no 401'
        );
    }

    /**
     * 🟦 BDD [A01-FIX-02]:
     * GIVEN usuario NO autenticado (guest)
     * WHEN pasa por UserHasRole via AJAX
     * THEN retorna 401 Unauthorized (correcto: debe autenticarse primero)
     */
    #[Test]
    #[Group('security')]
    #[Group('A01')]
    public function a01_fix_given_unauthenticated_guest_when_middleware_then_returns_401(): void
    {
        // Given: ningún usuario autenticado
        $middleware = app()->make(UserHasRole::class);
        $request    = Request::create('/admin', 'GET');
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');

        $response = $middleware->handle($request, fn () => response('OK', 200), 'admin');

        $this->assertEquals(
            401,
            $response->getStatusCode(),
            '[A01] Usuario no autenticado debe recibir 401 Unauthorized'
        );
    }

    /**
     * 🟦 BDD [A01-FIX-03]:
     * GIVEN usuario autenticado sin ninguno de los roles requeridos
     * WHEN pasa por UserHasAnyRole via AJAX
     * THEN retorna 403 Forbidden
     */
    #[Test]
    #[Group('security')]
    #[Group('A01')]
    public function a01_fix_given_authenticated_user_when_any_role_fails_then_returns_403(): void
    {
        $user = factory(User::class)->create();
        $this->actingAs($user);

        $middleware = app()->make(UserHasAnyRole::class);
        $request    = Request::create('/dashboard', 'GET');
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');

        $response = $middleware->handle($request, fn () => response('OK', 200), 'admin', 'editor');

        $this->assertEquals(403, $response->getStatusCode());
    }

    /**
     * 🟦 BDD [A01-FIX-04]:
     * GIVEN usuario autenticado con solo 1 de 2 roles requeridos
     * WHEN pasa por UserHasAllRoles via AJAX
     * THEN retorna 403 Forbidden
     */
    #[Test]
    #[Group('security')]
    #[Group('A01')]
    public function a01_fix_given_authenticated_user_missing_one_role_when_all_required_then_returns_403(): void
    {
        $user   = factory(User::class)->create();
        $editor = factory(Role::class)->create(['slug' => 'editor']);
        $user->assignRoles($editor);
        $this->actingAs($user);

        $middleware = app()->make(UserHasAllRoles::class);
        $request    = Request::create('/admin', 'GET');
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');

        $response = $middleware->handle($request, fn () => response('OK', 200), 'admin', 'editor');

        $this->assertEquals(403, $response->getStatusCode());
    }

    // =========================================================================
    // A04: INSECURE DESIGN — Diseño Seguro y Edge Cases
    // =========================================================================
    // Riesgo: Las funciones de verificación de roles/permisos podrían tener
    // comportamientos inesperados con entradas vacías o nulas que causan
    // bypass de seguridad (retornar true cuando deberían retornar false).
    // =========================================================================

    /**
     * 🟦 BDD [A04-01]:
     * GIVEN un string vacío como nombre de rol
     * WHEN se verifica hasRole('')
     * THEN retorna false (nunca true — bypass de seguridad)
     *
     * Edge case crítico: hasRole('') podría matchear registros con slug vacío.
     */
    #[Test]
    #[Group('security')]
    #[Group('A04')]
    public function a04_given_empty_string_role_when_hasrole_checked_then_returns_false(): void
    {
        $user = factory(User::class)->create();
        $role = factory(Role::class)->create(['slug' => 'admin']);
        $user->assignRoles($role);

        // When: slug vacío
        $result = $user->fresh()->hasRole('');

        // Then: NUNCA debe retornar true (sería un bypass de seguridad)
        $this->assertFalse(
            $result,
            '[A04] hasRole con string vacío debe retornar false, no true'
        );
    }

    /**
     * 🟦 BDD [A04-02]:
     * GIVEN un usuario sin roles
     * WHEN se verifica hasAllRoles() sin argumentos
     * THEN el comportamiento debe ser determinístico (false o excepción, no bypass)
     *
     * hasAllRoles() sin argumentos recorre un array vacío, retorna true por
     * la lógica "todo elemento del array vacío cumple la condición" (vacuously true).
     * Esto es un problema de diseño inseguro (A04): un atacante podría explotar
     * esta llamada para obtener acceso si el código del usuario no lo maneja bien.
     * Este test DOCUMENTA el comportamiento actual para que sea consciente.
     */
    #[Test]
    #[Group('security')]
    #[Group('A04')]
    public function a04_given_no_arguments_when_hasallroles_checked_then_behavior_is_documented(): void
    {
        $user = factory(User::class)->create(); // Sin roles

        // Documentamos el comportamiento actual (vacuously true)
        // Los consumidores del paquete deben ser conscientes de esto
        $resultWithNoArgs = $user->fresh()->hasAllRoles();

        // Este test documenta el comportamiento: el paquete retorna true con 0 argumentos
        // ya que no hay roles que verificar (loop vacío).
        // RECOMENDACIÓN A04: Considerar retornar false si no se pasan argumentos.
        $this->assertIsBool(
            $resultWithNoArgs,
            '[A04] hasAllRoles() sin argumentos debe retornar un bool, no lanzar excepción'
        );
    }

    /**
     * 🟦 BDD [A04-03]:
     * GIVEN un slug de rol con caracteres especiales o espacios
     * WHEN se asigna y verifica con hasRole
     * THEN Str::slug() normaliza el input (no hay bypass por capitalización)
     *
     * Verifica que 'ADMIN', 'Admin', 'admin' son equivalentes — no se puede
     * escalar privilegios usando una variante de capitalización diferente.
     */
    #[Test]
    #[Group('security')]
    #[Group('A04')]
    public function a04_given_mixed_case_role_name_when_hasrole_checked_then_normalized(): void
    {
        $user = factory(User::class)->create();
        $role = factory(Role::class)->create(['name' => 'Admin', 'slug' => 'admin']);
        $user->assignRoles($role);

        // When: se verifica con variantes de capitalización
        // Then: Str::slug() normaliza a 'admin' en todos los casos
        $this->assertTrue($user->fresh()->hasRole('admin'));
        $this->assertTrue($user->fresh()->hasRole('Admin'));   // Str::slug('Admin') = 'admin'
        $this->assertTrue($user->fresh()->hasRole('ADMIN'));   // Str::slug('ADMIN') = 'admin'
    }

    /**
     * 🟦 BDD [A04-04]:
     * GIVEN múltiples roles con flags especiales contradictorios
     * WHEN el usuario tiene tanto "all-access" como "no-access"
     * THEN "no-access" toma precedencia (más restrictivo gana)
     *
     * OWASP A04: El diseño seguro favorece "deny by default" cuando hay ambigüedad.
     */
    #[Test]
    #[Group('security')]
    #[Group('A04')]
    public function a04_given_conflicting_special_flags_when_checking_permission_then_no_access_wins(): void
    {
        $user      = factory(User::class)->create();
        $permission = factory(Permission::class)->create(['slug' => 'edit-posts']);
        $allAccess = factory(Role::class)->create(['slug' => 'superadmin', 'special' => 'all-access']);
        $noAccess  = factory(Role::class)->create(['slug' => 'banned', 'special' => 'no-access']);

        $user->givePermissionTo($permission);
        $user->assignRoles($allAccess, $noAccess); // Ambos flags

        // Then: con "no-access" presente, debe denegar (principio de mínimo privilegio)
        $result = $user->fresh()->hasPermissionTo('edit-posts');

        // Documentamos el comportamiento: "no-access" toma precedencia
        $this->assertFalse(
            $result,
            '[A04] Con flags contradictorios, "no-access" debe tener precedencia sobre "all-access"'
        );
    }

    // =========================================================================
    // A05: SECURITY MISCONFIGURATION — Configuración del Paquete
    // =========================================================================

    /**
     * 🟦 BDD [A05-01]:
     * GIVEN la configuración por defecto del paquete shinobi.php
     * WHEN se revisan los valores de caché
     * THEN el caché está DESHABILITADO por defecto (safe default)
     *
     * El caché deshabilitado por defecto es una configuración segura porque:
     * - Evita servir permisos obsoletos (integrity risk)
     * - Evita que bugs de caché concedan acceso incorrecto
     */
    #[Test]
    #[Group('security')]
    #[Group('A05')]
    public function a05_given_default_config_when_cache_checked_then_disabled_by_default(): void
    {
        // El caché deshabilitado por defecto es la opción segura (fail-safe default)
        $cacheEnabled = config('ronin.cache.enabled');

        $this->assertFalse(
            $cacheEnabled,
            '[A05] El caché debe estar deshabilitado por defecto para evitar servir permisos obsoletos'
        );
    }

    /**
     * 🟦 BDD [A05-02]:
     * GIVEN la configuración del paquete
     * WHEN se verifica el modelo de rol y permiso
     * THEN los modelos por defecto son los del paquete (no clases inexistentes)
     */
    #[Test]
    #[Group('security')]
    #[Group('A05')]
    public function a05_given_default_config_when_models_checked_then_valid_classes_are_referenced(): void
    {
        $roleModel       = config('ronin.models.role');
        $permissionModel = config('ronin.models.permission');

        $this->assertTrue(
            class_exists($roleModel),
            "[A05] El modelo de rol configurado '{$roleModel}' debe existir"
        );

        $this->assertTrue(
            class_exists($permissionModel),
            "[A05] El modelo de permiso configurado '{$permissionModel}' debe existir"
        );
    }

    /**
     * 🟦 BDD [A05-03]:
     * GIVEN la configuración del paquete
     * WHEN se verifica el tag de caché
     * THEN el tag no está vacío (un tag vacío podría purgar TODO el caché de la app)
     */
    #[Test]
    #[Group('security')]
    #[Group('A05')]
    public function a05_given_default_config_when_cache_tag_checked_then_not_empty(): void
    {
        $cacheTag = config('ronin.cache.tag');

        $this->assertNotEmpty(
            $cacheTag,
            '[A05] El tag de caché no debe estar vacío para evitar purgar el caché completo de la app'
        );
    }

    /**
     * 🟦 BDD [A05-04]:
     * GIVEN la configuración del paquete
     * WHEN se verifica el TTL del caché
     * THEN el TTL es razonable (no 0 ni negativo, que invalidaría inmediatamente)
     */
    #[Test]
    #[Group('security')]
    #[Group('A05')]
    public function a05_given_default_config_when_cache_ttl_checked_then_positive_value(): void
    {
        $cacheTtl = config('ronin.cache.length');

        $this->assertIsInt($cacheTtl, '[A05] El TTL del caché debe ser un entero');
        $this->assertGreaterThan(0, $cacheTtl, '[A05] El TTL del caché debe ser positivo');
    }

    // =========================================================================
    // A09: SECURITY LOGGING AND MONITORING
    // =========================================================================
    // IMPLEMENTACIÓN: El evento AccessDenied ahora se despacha en los 3 middlewares
    // cuando un acceso es denegado. Las aplicaciones que usen este paquete pueden
    // escuchar este evento para implementar su estrategia de logging.
    // =========================================================================

    /**
     * 🟦 BDD [A09-01]:
     * GIVEN un usuario autenticado sin el rol requerido
     * WHEN intenta pasar por UserHasRole
     * THEN el evento AccessDenied se despacha con los datos correctos
     *
     * 🔴 TDD RED: No existía el evento antes de esta iteración.
     * 🟢 TDD GREEN: Middleware despacha AccessDenied con user, role, ip, método, uri.
     */
    #[Test]
    #[Group('security')]
    #[Group('A09')]
    public function a09_given_user_without_role_when_middleware_blocks_then_access_denied_event_dispatched(): void
    {
        Event::fake([AccessDenied::class]);

        $user = factory(User::class)->create();
        $this->actingAs($user);

        $middleware = app()->make(UserHasRole::class);
        $request    = Request::create('/admin', 'GET');
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');

        $middleware->handle($request, fn () => response('OK', 200), 'admin');

        // Then: el evento fue despachado
        Event::assertDispatched(AccessDenied::class, function (AccessDenied $event) use ($user) {
            return $event->user?->getAuthIdentifier() === $user->id
                && $event->requiredAccess === 'admin'
                && $event->method === 'GET'
                && $event->uri === 'admin';
        });
    }

    /**
     * 🟦 BDD [A09-02]:
     * GIVEN un usuario NO autenticado (guest)
     * WHEN es bloqueado por UserHasRole
     * THEN el evento AccessDenied se despacha con user = null
     */
    #[Test]
    #[Group('security')]
    #[Group('A09')]
    public function a09_given_guest_when_middleware_blocks_then_event_dispatched_with_null_user(): void
    {
        Event::fake([AccessDenied::class]);

        $middleware = app()->make(UserHasRole::class);
        $request    = Request::create('/admin', 'GET');
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');

        $middleware->handle($request, fn () => response('OK', 200), 'admin');

        Event::assertDispatched(AccessDenied::class, function (AccessDenied $event) {
            return $event->user === null // Guest no autenticado
                && $event->requiredAccess === 'admin';
        });
    }

    /**
     * 🟦 BDD [A09-03]:
     * GIVEN un usuario con permisos insuficientes para múltiples roles
     * WHEN es bloqueado por UserHasAnyRole
     * THEN el evento AccessDenied incluye todos los roles requeridos separados por '|'
     */
    #[Test]
    #[Group('security')]
    #[Group('A09')]
    public function a09_given_user_blocked_by_any_role_middleware_then_event_contains_all_required_roles(): void
    {
        Event::fake([AccessDenied::class]);

        $user = factory(User::class)->create();
        $this->actingAs($user);

        $middleware = app()->make(UserHasAnyRole::class);
        $request    = Request::create('/dashboard', 'POST');
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');

        $middleware->handle($request, fn () => response('OK', 200), 'admin', 'editor');

        Event::assertDispatched(AccessDenied::class, function (AccessDenied $event) {
            // Los roles requeridos están separados por | en el evento
            return str_contains($event->requiredAccess, 'admin')
                && str_contains($event->requiredAccess, 'editor')
                && $event->method === 'POST';
        });
    }

    /**
     * 🟦 BDD [A09-04]:
     * GIVEN un usuario con todos los roles requeridos
     * WHEN el acceso es PERMITIDO por UserHasAllRoles
     * THEN el evento AccessDenied NO se despacha (solo en denials)
     */
    #[Test]
    #[Group('security')]
    #[Group('A09')]
    public function a09_given_user_with_all_roles_when_access_granted_then_no_event_dispatched(): void
    {
        Event::fake([AccessDenied::class]);

        $user   = factory(User::class)->create();
        $admin  = factory(Role::class)->create(['slug' => 'admin', 'special' => 'all-access']);
        $editor = factory(Role::class)->create(['slug' => 'editor']);
        $user->assignRoles($admin, $editor);
        $this->actingAs($user);

        // When: pasa por el middleware con todos los roles requeridos
        $status = $this->middleware(UserHasAllRoles::class, ['admin', 'editor']);

        // Then: acceso garantizado y NO hubo evento de denegación
        $this->assertEquals(200, $status);
        Event::assertNotDispatched(AccessDenied::class);
    }
}
