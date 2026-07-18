<?php

declare(strict_types=1);

namespace Laravel\Ronin\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Contracts\Auth\Guard;
use Laravel\Ronin\Events\AccessDenied;
use Illuminate\Support\Facades\Event;

class UserHasPermission
{
    protected Guard $auth;

    /**
     * Create a new UserHasPermission instance.
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
     * @param string                   $permission
     *
     * @return mixed
     */
    public function handle($request, Closure $next, string $permission): mixed
    {
        $user = $this->auth->user();

        if (! $user || ! method_exists($user, 'hasPermissionTo') || ! $user->hasPermissionTo($permission)) {
            Event::dispatch(new AccessDenied(
                user: $user,
                requiredAccess: $permission,
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
