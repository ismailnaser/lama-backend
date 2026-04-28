<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class UserAdminController extends Controller
{
    private function normalizeRole(string $role): string
    {
        $r = strtolower(trim($role));
        if ($r === 'user') return 'nurse';
        return $r;
    }

    private function actorContext(Request $request): array
    {
        $u = $request->attributes->get('auth_user');
        $role = $this->normalizeRole((string) ($u->role ?? ''));

        if ($role === 'admin') {
            return ['scope' => 'all', 'manageable' => ['admin', 'nurse', 'nurse_admin', 'doctor', 'doctor_admin']];
        }
        if ($role === 'nurse_admin') {
            return ['scope' => 'nurse', 'manageable' => ['nurse', 'nurse_admin']];
        }
        if ($role === 'doctor_admin') {
            return ['scope' => 'doctor', 'manageable' => ['doctor', 'doctor_admin']];
        }

        return ['scope' => 'none', 'manageable' => []];
    }

    private function activeAdminsByRole(string $role): int
    {
        return (int) DB::table('users')
            ->where('role', $role)
            ->where('is_active', true)
            ->count();
    }

    private function canManageRole(Request $request, string $targetRole): bool
    {
        $ctx = $this->actorContext($request);
        return in_array($targetRole, $ctx['manageable'], true);
    }

    private function actingAdminId(Request $request): int
    {
        $u = $request->attributes->get('auth_user');
        return (int) ($u->id ?? 0);
    }

    private function revokeAllTokensForUser(int $userId): void
    {
        DB::table('api_tokens')->where('user_id', $userId)->delete();
    }

    public function index(Request $request)
    {
        $ctx = $this->actorContext($request);
        if ($ctx['scope'] === 'none') {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $query = DB::table('users')
            ->orderBy('id', 'asc')
            ->select(['id', 'name', 'username', 'email', 'role', 'is_active', 'created_at']);

        if ($ctx['scope'] !== 'all') {
            $roles = $ctx['manageable'];
            if ($ctx['scope'] === 'nurse') {
                $roles[] = 'user'; // legacy nurse role
            }
            $query->whereIn('role', $roles);
        }

        $users = $query->get();

        return response()->json(['data' => $users]);
    }

    public function store(Request $request)
    {
        $data = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string', 'min:6', 'max:255'],
            'role' => ['required', 'in:user,admin,nurse,nurse_admin,doctor,doctor_admin'],
            'email' => ['nullable', 'email', 'max:255'],
        ])->validate();
        $data['role'] = $this->normalizeRole((string) $data['role']);
        if (!$this->canManageRole($request, (string) $data['role'])) {
            return response()->json(['message' => 'You can only create users in your section.'], 403);
        }

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
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = DB::table('users')->where('id', $id)->first(['id', 'name', 'username', 'email', 'role', 'is_active', 'created_at']);

        return response()->json(['data' => $user], 201);
    }

    public function update(Request $request, int $user)
    {
        $actorId = $this->actingAdminId($request);
        if ($actorId <= 0) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $target = DB::table('users')->where('id', $user)->first(['id', 'role', 'username', 'is_active']);
        if (!$target) {
            return response()->json(['message' => 'User not found.'], 404);
        }
        $targetRole = $this->normalizeRole((string) $target->role);
        if (!$this->canManageRole($request, $targetRole)) {
            return response()->json(['message' => 'You can only manage users in your section.'], 403);
        }

        $data = Validator::make($request->all(), [
            'name' => ['sometimes', 'string', 'max:255'],
            'username' => ['sometimes', 'string', 'max:50'],
            'password' => ['sometimes', 'string', 'min:6', 'max:255'],
            'role' => ['sometimes', 'in:user,admin,nurse,nurse_admin,doctor,doctor_admin'],
            'email' => ['nullable', 'email', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ])->validate();
        if (array_key_exists('role', $data)) {
            $data['role'] = $this->normalizeRole((string) $data['role']);
            if (!$this->canManageRole($request, (string) $data['role'])) {
                return response()->json(['message' => 'You can only assign roles in your section.'], 403);
            }
        }

        if (array_key_exists('username', $data)) {
            $newUsername = trim((string) $data['username']);
            $conflict = DB::table('users')
                ->where('username', $newUsername)
                ->where('id', '!=', $user)
                ->exists();
            if ($conflict) {
                return response()->json(['message' => 'Username already exists.'], 409);
            }
            $data['username'] = $newUsername;
        }

        $updates = [];
        foreach (['name', 'username', 'email', 'role', 'is_active'] as $k) {
            if (array_key_exists($k, $data)) {
                $updates[$k] = $data[$k];
            }
        }
        if (array_key_exists('password', $data)) {
            $updates['password'] = Hash::make($data['password']);
        }

        if (array_key_exists('is_active', $updates) && $updates['is_active'] === false) {
            if (in_array($targetRole, ['admin', 'nurse_admin', 'doctor_admin'], true) && $this->activeAdminsByRole($targetRole) <= 1) {
                return response()->json(['message' => 'Cannot disable the last active admin in this section.'], 422);
            }
        }

        if (array_key_exists('role', $updates) && in_array($targetRole, ['admin', 'nurse_admin', 'doctor_admin'], true)) {
            if ($updates['role'] !== $targetRole && $this->activeAdminsByRole($targetRole) <= 1) {
                return response()->json(['message' => 'Cannot demote the last active admin in this section.'], 422);
            }
        }

        if ($updates === []) {
            $fresh = DB::table('users')->where('id', $user)->first(['id', 'name', 'username', 'email', 'role', 'is_active', 'created_at']);

            return response()->json(['data' => $fresh]);
        }

        $updates['updated_at'] = now();
        DB::table('users')->where('id', $user)->update($updates);

        if (array_key_exists('password', $data) || array_key_exists('username', $data) || array_key_exists('is_active', $data)) {
            $this->revokeAllTokensForUser($user);
        }

        $fresh = DB::table('users')->where('id', $user)->first(['id', 'name', 'username', 'email', 'role', 'is_active', 'created_at']);

        return response()->json(['data' => $fresh]);
    }

    public function destroy(Request $request, int $user)
    {
        $actorId = $this->actingAdminId($request);
        if ($actorId <= 0) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($user === $actorId) {
            return response()->json(['message' => 'You cannot delete your own account.'], 422);
        }

        $target = DB::table('users')->where('id', $user)->first(['id', 'role']);
        if (!$target) {
            return response()->json(['message' => 'User not found.'], 404);
        }
        $targetRole = $this->normalizeRole((string) $target->role);
        if (!$this->canManageRole($request, $targetRole)) {
            return response()->json(['message' => 'You can only manage users in your section.'], 403);
        }

        if (in_array($targetRole, ['admin', 'nurse_admin', 'doctor_admin'], true) && $this->activeAdminsByRole($targetRole) <= 1) {
            return response()->json(['message' => 'Cannot delete the last active admin in this section.'], 422);
        }

        $this->revokeAllTokensForUser($user);
        DB::table('users')->where('id', $user)->delete();

        return response()->noContent();
    }
}
