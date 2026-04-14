<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = Validator::make($request->all(), [
            'username' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string', 'max:255'],
        ])->validate();

        $username = trim($data['username']);

        $user = DB::table('users')
            ->where('username', $username)
            ->select(['id', 'name', 'username', 'email', 'role', 'password'])
            ->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Invalid credentials.'], 422);
        }

        $plain = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $hash = hash('sha256', $plain);

        DB::table('api_tokens')->insert([
            'user_id' => $user->id,
            'name' => 'web',
            'token_hash' => $hash,
            'last_used_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'token' => $plain,
            'user' => [
                'id' => (int) $user->id,
                'name' => (string) ($user->name ?? ''),
                'username' => (string) ($user->username ?? ''),
                'email' => (string) ($user->email ?? ''),
                'role' => (string) ($user->role ?? 'user'),
            ],
        ]);
    }

    public function me(Request $request)
    {
        $u = $request->attributes->get('auth_user');
        return response()->json(['user' => $u]);
    }

    public function logout(Request $request)
    {
        $tokenId = (int) $request->attributes->get('auth_token_id', 0);
        if ($tokenId > 0) {
            DB::table('api_tokens')->where('id', $tokenId)->delete();
        }
        return response()->noContent();
    }
}

