<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    public function updatePreferences(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'allergens' => ['nullable', 'array'],
            'allergens.*' => ['string', 'max:255'],
        ]);

        $user = User::where('email', strtolower($data['email']))->first();
        if (!$user) {
            throw ValidationException::withMessages([
                'email' => ['User not found.'],
            ]);
        }

        $allergens = array_values(array_filter(array_map(
            fn ($a) => trim((string) $a),
            $data['allergens'] ?? []
        )));

        $user->allergens = $allergens;
        $user->save();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'allergens' => $user->allergens ?? [],
            ],
            'message' => 'Preferences updated.',
        ]);
    }
}
