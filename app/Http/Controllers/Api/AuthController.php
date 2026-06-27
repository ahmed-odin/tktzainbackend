<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'nullable|email|required_without:username',
            'username' => 'nullable|string|required_without:email',
            'password' => 'required',
        ]);

        $loginValue = $request->input('email') ?: $request->input('username');
        $user = User::where('email', $loginValue)
            ->orWhere('name', $loginValue)
            ->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials',
            ], 401);
        }

        if (! $user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'User is inactive',
            ], 403);
        }

        $token = bin2hex(random_bytes(40));
        $user->api_token = Hash::make($token);
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'is_active' => $user->is_active,
            ],
        ]);
    }

    public function logout(Request $request)
    {
        $user = $request->user();

        if ($user) {
            $user->api_token = null;
            $user->save();
        }

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
        ]);
    }

    public function me(Request $request)
    {
        return response()->json([
            'success' => true,
            'user' => $request->user()->only([
                'id',
                'name',
                'email',
                'role',
                'is_active',
            ]),
        ]);
    }
}
