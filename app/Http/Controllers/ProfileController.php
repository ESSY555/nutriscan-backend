<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    public function updatePreferences(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'name' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'min:6'],
            'allergens' => ['nullable', 'array'],
            'allergens.*' => ['string', 'max:255'],
            'country' => ['nullable', 'string', 'max:120'],
            'diet' => ['nullable', 'string', 'max:120'],
            'goal' => ['nullable', 'string', 'max:120'],
            'onboarding_completed' => ['nullable', 'boolean'],
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
        if (array_key_exists('name', $data) && $data['name'] !== null) {
            $user->name = trim($data['name']);
        }
        if (array_key_exists('password', $data) && $data['password']) {
            $user->password = Hash::make($data['password']);
        }
        if (array_key_exists('country', $data)) {
            $user->country = $data['country'] ?: null;
        }
        if (array_key_exists('diet', $data)) {
            $user->diet_preference = $data['diet'] ?: 'No Preference';
        }
        if (array_key_exists('goal', $data)) {
            $user->health_goal = $data['goal'] ?: 'Eat Healthier';
        }
        if (array_key_exists('onboarding_completed', $data)) {
            $user->onboarding_completed = (bool) $data['onboarding_completed'];
        }
        $user->save();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'allergens' => $user->allergens ?? [],
                'avatar_url' => $user->avatar_url ?? null,
                'country' => $user->country ?? null,
                'diet_preference' => $user->diet_preference ?? 'No Preference',
                'health_goal' => $user->health_goal ?? 'Eat Healthier',
                'onboarding_completed' => (bool) ($user->onboarding_completed ?? false),
            ],
            'message' => 'Preferences updated.',
        ]);
    }

    public function getPreferences(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        $user = User::where('email', strtolower($data['email']))->first();
        if (! $user) {
            throw ValidationException::withMessages([
                'email' => ['User not found.'],
            ]);
        }

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'allergens' => $user->allergens ?? [],
                'avatar_url' => $user->avatar_url ?? null,
                'country' => $user->country ?? null,
                'diet_preference' => $user->diet_preference ?? 'No Preference',
                'health_goal' => $user->health_goal ?? 'Eat Healthier',
                'onboarding_completed' => (bool) ($user->onboarding_completed ?? false),
            ],
            'message' => 'Preferences fetched.',
        ]);
    }

    public function uploadAvatar(Request $request): JsonResponse
    {
        $token = $request->bearerToken();
        if (! $token) {
            throw ValidationException::withMessages([
                'token' => ['Missing bearer token.'],
            ]);
        }

        $user = User::where('api_token', hash('sha256', $token))->first();
        if (! $user) {
            throw ValidationException::withMessages([
                'token' => ['Invalid or expired token. Please log in again.'],
            ]);
        }

        $data = $request->validate([
            'avatar' => ['required', 'file', 'image', 'max:5120', 'mimes:jpeg,png,jpg,webp'],
        ]);

        $file = $data['avatar'];
        $filename = Str::uuid()->toString() . '.' . $file->getClientOriginalExtension();

        // Ensure public/avatars exists and move the file there (no storage:link required)
        $publicDir = public_path('avatars');
        if (! is_dir($publicDir)) {
            mkdir($publicDir, 0755, true);
        }
        $file->move($publicDir, $filename);
        $publicUrl = url('avatars/' . $filename);

        // Optionally clean up previous avatar if it was stored under /avatars
        if ($user->avatar_url && str_contains($user->avatar_url, '/avatars/')) {
            $previousPath = public_path(parse_url($user->avatar_url, PHP_URL_PATH));
            if ($previousPath && str_starts_with($previousPath, public_path()) && file_exists($previousPath)) {
                @unlink($previousPath);
            }
        }

        $user->avatar_url = $publicUrl;
        $user->save();

        return response()->json([
            'message' => 'Avatar updated.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'allergens' => $user->allergens ?? [],
                'avatar_url' => $user->avatar_url,
            ],
            'avatar_url' => $publicUrl,
        ]);
    }
}
