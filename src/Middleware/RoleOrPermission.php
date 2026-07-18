<?php

declare(strict_types=1);

namespace Laravel\Ronin\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Contracts\Auth\Guard;
use Laravel\Ronin\Events\AccessDenied;
use Illuminate\Support\Facades\Event;

class RoleOrPermission
{
    protected Guard $auth;

    /**
     * Create a new RoleOrPermission instance.
     *
     * @param Guard $auth
     */
    public function __construct(Guard $auth)
    {
        $this->auth = $auth;
    }

    /**
     * Run the request filter.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure                 $next
     * @param string                   $roleOrPermission
     *
     * @return mixed
     */
    public function handle($request, Closure $next, string $roleOrPermission): mixed
    {
        $user = $this->auth->user();

        // Support pipe-separated items
        $items = explode('|', $roleOrPermission);

        $hasAccess = false;

        if ($user) {
            foreach ($items as $item) {
                // Check if user has the role
                if (method_exists($user, 'hasRole') && $user->hasRole($item)) {
                    $hasAccess = true;
                    break;
                }
                // Check if user has the permission
                if (method_exists($user, 'hasPermissionTo') && $user->hasPermissionTo($item)) {
                    $hasAccess = true;
                    break;
                }
            }
        }

        if (! $hasAccess) {
            Event::dispatch(new AccessDenied(
                user: $user,
                requiredAccess: $roleOrPermission,
                method: $request->method(),
                uri: $request->path(),
                ip: $request->ip(),
            ));

            $statusCode = $user ? 403 : 401;

            if ($request->ajax()) {
                return response('Unauthorized.', $statusCode);
            }

            return abort($statusCode);
        }

        return $next($request);
    }
}
