<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Repositories\UserRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Handles account registration, token login, profile access, and current-token logout.
 */
class AuthController extends Controller
{
    public function register(RegisterRequest $request, UserRepository $users): JsonResponse
    {
        $user = $users->create($request->validated());

        return ApiResponse::success($this->tokenResponse($user), 'Account created successfully.', 201);
    }

    public function login(LoginRequest $request, UserRepository $users): JsonResponse
    {
        $credentials = $request->validated();
        $user = $users->findByEmail($credentials['email']);

        if (! $user instanceof User || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        return ApiResponse::success($this->tokenResponse($user), 'Authenticated successfully.');
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success(UserResource::make($request->user()), 'Authenticated user retrieved successfully.');
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return ApiResponse::success(null, 'Logged out successfully.');
    }

    /** @return array{user: array{id: int, name: string, email: string}, token: string} */
    private function tokenResponse(User $user): array
    {
        return [
            'user' => UserResource::make($user),
            'token' => $user->createToken('api-token')->plainTextToken,
        ];
    }
}
