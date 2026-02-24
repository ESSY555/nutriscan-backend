<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
    * Handle user registration.
    */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
            'allergens' => ['sometimes', 'array'],
            'allergens.*' => ['string', 'max:255'],
        ]);

        $allergens = array_values(array_filter(array_map(
            fn ($a) => trim((string) $a),
            $data['allergens'] ?? []
        )));

        $user = User::create([
            'name' => $data['name'],
            'email' => strtolower($data['email']),
            'password' => Hash::make($data['password']),
            'allergens' => $allergens,
        ]);

        $token = $this->issueToken($user);

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'allergens' => $user->allergens ?? [],
            ],
            'token' => $token,
        ], 201);
    }

    /**
    * Handle user login.
    */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', strtolower($data['email']))->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $token = $this->issueToken($user);

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'allergens' => $user->allergens ?? [],
            ],
            'token' => $token,
        ]);
    }

    private function issueToken(User $user): string
    {
        $plainTextToken = Str::random(60);
        $user->forceFill([
            'api_token' => hash('sha256', $plainTextToken),
        ])->save();

        return $plainTextToken;
    }
}
