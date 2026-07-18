<?php

declare(strict_types=1);

namespace Laravel\Ronin\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Contracts\Auth\Guard;
use Laravel\Ronin\Events\AccessDenied;
use Illuminate\Support\Facades\Event;

class UserHasAllPermissions
{
    protected Guard $auth;

    /**
     * Create a new UserHasAllPermissions instance.
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
     * @param string                   $permissions
     *
     * @return mixed
     */
    public function handle($request, Closure $next, string $permissions): mixed
    {
        $user = $this->auth->user();
        $permissionsArray = explode('|', $permissions);

        if (! $user || ! method_exists($user, 'hasAllPermissions') || ! $user->hasAllPermissions($permissionsArray)) {
            Event::dispatch(new AccessDenied(
                user: $user,
                requiredAccess: $permissions,
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
