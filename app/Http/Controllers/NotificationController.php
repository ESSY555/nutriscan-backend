<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class NotificationController extends Controller
{
    /**
     * Register or update a user's Expo push token.
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'push_token' => ['required', 'string', 'max:255'],
        ]);

        $user = User::where('email', strtolower($data['email']))->first();
        if (!$user) {
            throw ValidationException::withMessages([
                'email' => ['User not found.'],
            ]);
        }

        $user->push_token = $data['push_token'];
        $user->save();

        Log::info('Expo push token registered', [
            'email' => $user->email,
            'push_token' => $user->push_token,
        ]);

        return response()->json([
            'message' => 'Push token registered.',
        ]);
    }

    /**
     * Send a test push to the user's registered device, honoring notification flags.
     */
    public function test(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'title' => ['nullable', 'string', 'max:120'],
            'body' => ['nullable', 'string', 'max:240'],
        ]);

        $user = User::where('email', strtolower($data['email']))->first();
        if (!$user) {
            throw ValidationException::withMessages([
                'email' => ['User not found.'],
            ]);
        }

        if (!$user->push_token) {
            throw ValidationException::withMessages([
                'push_token' => ['No device registered for this user.'],
            ]);
        }

        // Respect notification settings (any toggle enabled allows this generic test)
        $settings = $user->notification_settings ?? [];
        $anyEnabled = ($settings['scanReminders'] ?? false) || ($settings['nutritionTips'] ?? false) || ($settings['weeklyDigest'] ?? false);
        if (!$anyEnabled) {
            throw ValidationException::withMessages([
                'notifications' => ['Notifications are disabled for this user.'],
            ]);
        }

        $result = $this->sendExpoPush(
            $user->push_token,
            $data['title'] ?? 'NutriScan',
            $data['body'] ?? 'Notifications are set up successfully!'
        );

        return response()->json([
            'message' => 'Test notification sent.',
            'expo' => $result,
        ]);
    }

    /**
     * Send a push via Expo.
     */
    private function sendExpoPush(string $token, string $title, string $body): array
    {
        $payload = [
            'to' => $token,
            'sound' => 'default',
            'title' => $title,
            'body' => $body,
            'data' => ['type' => 'test'],
        ];

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        $expoToken = env('EXPO_ACCESS_TOKEN');
        if ($expoToken) {
            $headers['Authorization'] = 'Bearer ' . $expoToken;
        }

        try {
            $res = Http::withHeaders($headers)
                ->post('https://exp.host/--/api/v2/push/send', [$payload]);

            $json = $res->json();
            if ($res->failed()) {
                Log::warning('Expo push failed', ['status' => $res->status(), 'body' => $json]);
                return ['success' => false, 'error' => $json];
            }

            Log::info('Expo push sent', ['response' => $json]);
            return ['success' => true, 'response' => $json];
        } catch (\Throwable $e) {
            Log::warning('Expo push error', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
