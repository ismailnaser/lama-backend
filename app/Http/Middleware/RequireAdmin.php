<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAdmin
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $u = $request->attributes->get('auth_user');
        $role = (string) ($u->role ?? 'user');
        $isScopedAdmin = in_array($role, ['admin', 'doctor_admin', 'nurse_admin'], true);
        if (!$u || !$isScopedAdmin) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return $next($request);
    }
}

