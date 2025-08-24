<?php

namespace App\Services;

use App\Models\StaffInfo;
use App\Models\User;
use App\Repositories\UserRepository;
use App\Repositories\RoleRepository;
use Illuminate\Support\Facades\Auth;


class AuthService
{
    protected $userRepository;
    protected $roleRepository;

    public function __construct(UserRepository $userRepository, RoleRepository $roleRepository)
    {
        $this->userRepository = $userRepository;
        $this->roleRepository = $roleRepository;
    }

    public function register(array $data)
    {
        $user = $this->userRepository->createUser($data);

        $role = $this->roleRepository->findRoleById($data['role_id']);

        if (!$role) {
            throw new \Exception('Role not found or guard mismatch', 422);
        }

        if (in_array($role->name, ['SuperAdmin', 'Secretarya', 'Teacher', 'Logistic'])) {
            StaffInfo::create([
                'UserId' => $user->id,
                'Photo' => null,
                'Description' => null,
            ]);
        }
        /*
        تستخدم role_id لتحديد الدور.

       ثم تعين نفس الدور للمستخدم باستخدام Spatie.
       */

        $permissions = $this->roleRepository->assignRoleToUser($user, $role);
        // $token = JWTAuth::fromUser($user);

        $token = $user->createToken('auth_token')->plainTextToken;
        return [
            'user' => $user,
            'token' => $token,
            'role' => $role->name,
            'permissions' => $permissions
        ];
    }

    public function login(array $credentials)
    {
        $token = $this->userRepository->attemptLogin($credentials);

        if (!$token) {
            throw new \Exception('Invalid credintials', 401);
        }

        $user = User::where('email', $credentials['email'])->first();
        $userData = $this->userRepository->getUserRolesAndPermissions($user);

        return [
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at,
                'role' => $userData['roles']->first(),
                'permissions' => $userData['permissions'],
                'Other Info' => $user->staffInfo ? [
                    'Photo' => $user->staffInfo->Photo,
                    'Description' => $user->staffInfo->Description,
                ] : null
            ]
        ];
    }


    /*  public function login(array $credentials)
    {
        $token = $this->userRepository->attemptLogin($credentials);

        if (!$token) {
            throw new \Exception('Invalid credentials', 401);
        }

        $user = Auth::user();
        $userData = $this->userRepository->getUserRolesAndPermissions($user);

        return [
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at,
                'role' => $userData['roles']->first(),
                'permissions' => $userData['permissions'],
                'Other Info' =>$user->staffInfo ? [
                    'Photo' => $user->staffInfo->Photo,
                    'Description' => $user->staffInfo->Description,
                ] : null
            ]
        ];
    }*/

    /* public function getMyProfile($userId)
    {
        $user = User::with(['roles.permissions'])->findOrFail($userId);
        $userInfo = User::with('staffInfo')->findOrFail($userId);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->roles->pluck('name'),
            'permissions' => $user->roles->flatMap(function($role) {
                return $role->permissions->pluck('name');
            })->unique()->values(),
        ];
    }*/


    public function getMyProfile($userId)
    {
        $user = User::with(['roles.permissions', 'staffInfo'])->findOrFail($userId);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->roles->pluck('name'),
            'permissions' => $user->roles->flatMap(function ($role) {
                return $role->permissions->pluck('name');
            })->unique()->values(),
            //value()يعيد فهرسة العناصر
            'Other Info' => $user->staffInfo ? [
                'Photo' => $user->staffInfo->Photo,
                'Description' => $user->staffInfo->Description,
            ] : null
        ];
    }


    public function getUserProfile($id)
    {
        $user = $this->userRepository->findUserById($id);
        $userData = $this->userRepository->getUserRolesAndPermissions($user);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $userData['roles'],
            'permissions' => $userData['permissions'],
        ];
    }

    public function getStaff(array $roleIds)
    {
        return User::whereIn('role_id', $roleIds)->with(['roles', 'staffInfo'])->get();
    }

    /* public function logout()
    {
        $this->userRepository->invalidateToken(JWTAuth::getToken());
    }*/

    public function logout()
    {
        Auth::user()->currentAccessToken()->delete();
    }

    public function registerGuest(array $data)
    {
        $guestRole = $this->roleRepository->getGuestRole();
        $data['role_id'] = $guestRole->id;

        $user = $this->userRepository->createGuestUser($data);
        $this->roleRepository->assignGuestRole($user);

        //$token = JWTAuth::fromUser($user);

        $token = $user->createToken('auth_token')->plainTextToken;
        //هذه دالة موجودة في HasApiTokens trait (الموجود في موديل User)
        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at,
                'role' => 'Guest',
                'permissions' => $user->getAllPermissions()->pluck('name'),
                'role_id' => $guestRole->id
            ],
            'token' => $token
        ];
    }
}

//بعد إنشاء التوكن، createToken() ترجع كائن من نوع NewAccessToken يحتوي على معلومات التوكن.

//plainTextToken هو السلسلة النصية الحقيقية للتوكن التي سترسل للعميل (frontend أو Postman).
/*
بدلاً من استخدام علاقات معقدة أو الانضمام إلى جداول pivot

سهولة الاستعلامات البسيطة والأداء عند وجود علاقة “دور واحد فقط”
إذا كان تطبيقك يفرض أن كل مستخدم له دور واحد فقط، وأنت تريد تنفيذ استعلامات سريعة مثل:
"أعطني كل المستخدمين الذين لديهم هذا الدور"
بدون الحاجة إلى جلب العلاقات المعقدة عبر جداول pivot التي تستخدمها Spatie.

وجود عمود role_id في جدول المستخدم يسمح لك بعمل استعلام بسيط وواضح، مثل:

Spatie ممتازة لإدارة أدوار وصلاحيات معقدة، خاصة إذا يمكن للمستخدم أن يملك عدة أدوار أو صلاحيات متعددة.

لكن إذا كانت متطلبات النظام بسيطة جداً (دور واحد لكل مستخدم)، فوجود عمود role_id يمكن أن يبسط الكود ويزيد من سرعة الاستعلامات.

في هذه الحالة يمكنك استخدام Spatie فقط لإدارة الصلاحيات داخل كل دور، بينما تخزن الدور الأساسي مباشرة في جدول المستخدم.

وجود عمود role_id مع Spatie قد يكون مفيداً في الحالات التي يكون فيها للمستخدم دور واحد فقط
، وتريد تبسيط الاستعلامات وسرعتها، مع الاستفادة من إدارة الصلاحيات المتقدمة عبر Spatie

*/