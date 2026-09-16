<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Requests\Api\V1\RegisterRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Sanctum personal access tokens. Lifetime comes from
 * config('sanctum.expiration'), so it is configuration rather than code.
 */
class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        // $fillable on User keeps this to name, email and password; the
        // password cast hashes it with the default hasher.
        $user = User::query()->create($request->validated());

        return response()->json([
            'data' => [
                'token' => $this->issueToken($user),
                'user' => UserResource::make($user),
            ],
        ], Response::HTTP_CREATED);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::query()->where('email', $request->validated('email'))->first();

        // One message for both "no such user" and "wrong password", so the
        // endpoint cannot be used to enumerate registered addresses.
        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        return response()->json([
            'data' => [
                'token' => $this->issueToken($user),
            ],
        ]);
    }

    public function logout(Request $request): Response
    {
        // Only the token that made this call: other devices stay signed in.
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    private function issueToken(User $user): string
    {
        return $user->createToken('api')->plainTextToken;
    }
}
