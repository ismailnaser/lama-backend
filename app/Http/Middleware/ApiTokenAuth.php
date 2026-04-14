<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ApiTokenAuth
{
    /**
     * Authenticate using `Authorization: Bearer <token>`.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) $request->header('Authorization', '');
        if (!str_starts_with($header, 'Bearer ')) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $plain = trim(substr($header, 7));
        if ($plain === '') {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $hash = hash('sha256', $plain);

        $row = DB::table('api_tokens')
            ->join('users', 'users.id', '=', 'api_tokens.user_id')
            ->where('api_tokens.token_hash', $hash)
            ->select([
                'api_tokens.id as token_id',
                'api_tokens.expires_at',
                'users.id as user_id',
                'users.name',
                'users.username',
                'users.email',
                'users.role',
            ])
            ->first();

        if (!$row) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($row->expires_at !== null && now()->greaterThan($row->expires_at)) {
            DB::table('api_tokens')->where('id', $row->token_id)->delete();
            return response()->json(['message' => 'Token expired.'], 401);
        }

        // Attach a lightweight user object for controllers.
        $request->attributes->set('auth_user', (object) [
            'id' => (int) $row->user_id,
            'name' => (string) ($row->name ?? ''),
            'username' => (string) ($row->username ?? ''),
            'email' => (string) ($row->email ?? ''),
            'role' => (string) ($row->role ?? 'user'),
        ]);
        $request->attributes->set('auth_token_id', (int) $row->token_id);

        DB::table('api_tokens')->where('id', $row->token_id)->update(['last_used_at' => now()]);

        return $next($request);
    }
}

