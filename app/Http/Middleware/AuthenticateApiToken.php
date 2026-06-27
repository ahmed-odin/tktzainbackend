<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken() ?: $request->query('api_token');

        if (! $token) {
            return response()->json([
                'success' => false,
                'message' => 'API token missing',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $user = User::whereNotNull('api_token')
            ->where('is_active', true)
            ->get()
            ->first(fn (User $user) => Hash::check($token, $user->api_token));

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
