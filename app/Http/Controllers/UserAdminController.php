<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class UserAdminController extends Controller
{
    public function index()
    {
        $users = DB::table('users')
            ->orderBy('id', 'asc')
            ->get(['id', 'name', 'username', 'email', 'role', 'created_at']);

        return response()->json(['data' => $users]);
    }

    public function store(Request $request)
    {
        $data = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string', 'min:6', 'max:255'],
            'role' => ['required', 'in:user,admin'],
            'email' => ['nullable', 'email', 'max:255'],
        ])->validate();

        $username = trim($data['username']);

        $exists = DB::table('users')->where('username', $username)->exists();
        if ($exists) {
            return response()->json(['message' => 'Username already exists.'], 409);
        }

        $id = DB::table('users')->insertGetId([
            'name' => $data['name'],
            'username' => $username,
            'email' => $data['email'] ?? null,
            'password' => Hash::make($data['password']),
            'role' => $data['role'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = DB::table('users')->where('id', $id)->first(['id', 'name', 'username', 'email', 'role', 'created_at']);

        return response()->json(['data' => $user], 201);
    }
}

