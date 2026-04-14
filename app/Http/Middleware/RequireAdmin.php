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
        if (!$u || (($u->role ?? 'user') !== 'admin')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return $next($request);
    }
}

