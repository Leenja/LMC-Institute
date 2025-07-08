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


    //lana
    public function getAllRoles()
    {
        return Role::all();
    }

    public function getUsersByRoleId($roleId)
    {
        // This will automatically throw 404 if not found
        $role = Role::with('users')->findOrFail($roleId);

        return [
            'role' => $role,
            'users' => $role->users
        ];
    }
    //new
    public function getRoleIdsByNames(array $roleNames): array
    {
        return Role::whereIn('name', $roleNames)->pluck('id')->toArray();
    }

    public function getUsersByRoleIdsWithStaffInfo(array $roleIds, ?string $filter = 'active')
    {
        $query = User::query();

        // حسب الفلتر نعدل الاستعلام
        if ($filter === 'only_deleted') {
            $query = User::onlyTrashed();
        } elseif ($filter === 'all') {
            $query = User::withTrashed();
        } // إذا 'active' أو أي شيء آخر نترك الاستعلام كما هو (المستخدمين غير المحذوفين)

        return $query->whereIn('role_id', $roleIds)
            ->with(['staffInfo' => function ($query) {
                $query->withTrashed();
            }])
            ->get();
    }

    public function getUserById(int $id, bool $withTrashed = false): ?User
    {
        $query = User::query();

        if ($withTrashed) {
            $query = $query->withTrashed();
        }

        return $query->with(['staffInfo' => function ($q) {
            $q->withTrashed();
        }])->find($id);
    }
}
