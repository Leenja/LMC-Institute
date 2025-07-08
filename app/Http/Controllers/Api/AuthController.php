<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

use Illuminate\Support\Facades\Auth;


class AuthController extends Controller
{
    protected $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:6',
            'role_id' => 'required|exists:roles,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $result = $this->authService->register($request->all());

            return response()->json([
                'message' => 'User registered successfully',
                'token' => $result['token'],
                'user' => [
                    'id' => $result['user']->id,
                    'name' => $result['user']->name,
                    'email' => $result['user']->email,
                    'email_verified_at' => $result['user']->email_verified_at,
                    'role' => $result['role'],
                    'permissions' => $result['permissions'],
                ]
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage()
            ], $e->getCode() ?: 500);
        }
    }

   //this the correct version without notification
   public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|min:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $result = $this->authService->login($request->only('email', 'password'));

            return response()->json([
                'message' => 'Login successful',
                'token' => $result['token'],
                'user' => $result['user']
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage()
            ], $e->getCode() ?: 401);
        }
    }



   //this the correct version without notification
    public function logout()
    {
        try {
            $this->authService->logout();

            return response()->json([
                'message' => 'Successfully logged out'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to logout, please try again'
            ], 500);
        }
    }

    public function profile()
    {
        try {
            $user = auth()->user();

            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $userData = $this->authService->getMyProfile($user->id);

            return response()->json([
                'user' => [
                    'id' => $userData['id'],
                    'name' => $userData['name'],
                    'email' => $userData['email'],
                ],
                'roles' => $userData['roles'],
                'permissions' => $userData['permissions'],
                'Other Info' =>$user->staffInfo ? [
                    'Photo' => $user->staffInfo->Photo,
                    'Description' => $user->staffInfo->Description,
                ] : null
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage()
            ], $e->getCode() ?: 500);
        }
    }

    public function showUserInfo($id)
    {
        try {
            $userData = $this->authService->getUserProfile($id);
            $user = User::with(['staffInfo'])->findOrFail($id);

            return response()->json([
                'user' => [
                    'id' => $userData['id'],
                    'name' => $userData['name'],
                    'email' => $userData['email'],
                ],
                'roles' => $userData['roles'],
                'permissions' => $userData['permissions'],
                'Other Info' =>$user->staffInfo ? [
                    'Photo' => $user->staffInfo->Photo,
                    'Description' => $user->staffInfo->Description,
                ] : null
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage()
            ], $e->getCode() ?: 404);
        }
    }

    public function getStaff()
    {
        try {
            $users = $this->authService->getStaff([2, 3, 4]);

            $response = $users->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role_id' => $user->role_id,
                    'Other Info' => $user->staffInfo ? [
                        'Photo' => $user->staffInfo->Photo,
                        'Description' => $user->staffInfo->Description,
                    ] : null
                ];
            });

            return response()->json(['users' => $response], 200);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to retrieve users',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    public function registerGuest(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:6',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $result = $this->authService->registerGuest($request->all());

            return response()->json([
                'message' => 'Guest registered successfully',
                'token' => $result['token'],
                'user' => $result['user']
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'details' => 'Failed to register guest user'
            ], $e->getCode() ?: 500);
        }
    }
}
