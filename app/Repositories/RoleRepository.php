<?php

namespace App\Repositories;

use App\Models\User;
use Spatie\Permission\Models\Role;

class RoleRepository
{
    public function findRoleById($id)
    {
        return Role::where('id', $id)
                 ->where('guard_name', 'sanctum')
                 ->first();
    }

    public function assignRoleToUser($user, $role)
    {
        $user->assignRole($role->name);
        $permissions = $role->permissions()->pluck('name')->toArray();
        $user->givePermissionTo($permissions);
        return $permissions;
    }

    public function getGuestRole()
    {
        return Role::where('name', 'Guest')->firstOrFail();
    }

    public function assignGuestRole(User $user)
    {
        $guestRole = $this->getGuestRole();
        $user->assignRole($guestRole);
        
        // Assign default guest permissions if needed
        $permissions = $guestRole->permissions()->pluck('name')->toArray();
        $user->givePermissionTo($permissions);
        
        return $user;
    }
}