<?php

namespace App\Repositories;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
//use Tymon\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;


class UserRepository
{
    public function createUser(array $data)
    {
        return User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role_id' => $data['role_id'],
            'email_verified_at' => now(),
            'remember_token' => Str::random(10),
        ]);
    }

    public function findUserById($id)
    {
        return User::findOrFail($id);
    }

    public function getUserRolesAndPermissions(User $user)
    {
        return [
            'roles' => $user->getRoleNames(), // This returns a collection
            'permissions' => $user->getAllPermissions()->pluck('name') // This returns a collection
        ];
    }

    /*public function attemptLogin(array $credentials)
    {
        return JWTAuth::attempt($credentials);
    }*/

    public function attemptLogin(array $credentials)
    {
        $user = User::where('email', $credentials['email'])->first();
    
        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            return false;
        }
    
        return $user->createToken('API Token')->plainTextToken;
    }


    /*public function invalidateToken($token)
    {
        JWTAuth::invalidate($token);
    }*/

    public function createGuestUser(array $data)
    {
        return User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role_id' => $data['role_id'],
            'email_verified_at' => now(),
        ]);
    }

    public function find(int $id, array $columns = ['*'])
    {
        return User::find($id, $columns);
    }

    public function getByRole(string $role, int $excludeUserId): Collection
    {
        return User::where('role', $role)
                 ->where('id', '!=', $excludeUserId)
                 ->get();
    }

    public function getByRoleId(int $roleId, int $excludeUserId = null): Collection
    {
        $query = User::where('role_id', $roleId);

        if ($excludeUserId) {
            $query->where('id', '!=', $excludeUserId);
        }

        return $query->get();
    }
}
