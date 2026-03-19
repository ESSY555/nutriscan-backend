<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendDailyScanReminders extends Command
{
    protected $signature = 'notifications:scan-reminders';

    protected $description = 'Send daily scan reminder push notifications via Expo';

    public function handle(): int
    {
        $users = User::whereNotNull('push_token')->get();
        $count = 0;

        foreach ($users as $user) {
            $settings = $user->notification_settings ?? [];
            if (!($settings['scanReminders'] ?? false)) {
                continue;
            }

            $this->sendExpoPush(
                $user->push_token,
                'Time to scan your meal',
                'Log your ingredients to keep your nutrition on track.'
            );
            $count++;
        }

        Log::info('Daily scan reminders sent', ['count' => $count]);
        $this->info("Sent reminders to {$count} users.");

        return Command::SUCCESS;
    }

    private function sendExpoPush(string $token, string $title, string $body): void
    {
        $payload = [
            'to' => $token,
            'sound' => 'default',
            'title' => $title,
            'body' => $body,
            'data' => ['type' => 'scanReminder'],
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

            if ($res->failed()) {
                Log::warning('Expo push failed (scan reminder)', [
                    'status' => $res->status(),
                    'body' => $res->json(),
                ]);
            } else {
                Log::info('Expo push sent (scan reminder)', ['response' => $res->json()]);
            }
        } catch (\Throwable $e) {
            Log::warning('Expo push error (scan reminder)', ['error' => $e->getMessage()]);
        }
    }
}
