<?php

namespace App\Http\Controllers;

use App\Models\MealPlan;
use App\Services\MealPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MealPlanController extends Controller
{
    public function sync(Request $request, MealPlanService $service): JsonResponse
    {
        $data = $request->validate([
            'user_email' => ['nullable', 'string', 'email', 'max:255'],
            'diet' => ['nullable', 'string', 'max:255'],
            'goal' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'portion' => ['nullable', 'string', 'max:255'],
            'allergens' => ['nullable', 'array'],
            'allergens.*' => ['string', 'max:255'],
            'force_refresh' => ['sometimes', 'boolean'],
        ]);

        $userEmail = isset($data['user_email']) ? strtolower(trim((string) $data['user_email'])) : null;

        $preferences = [
            'diet' => $data['diet'] ?? 'No Preference',
            'goal' => $data['goal'] ?? 'Eat Healthier',
            'country' => $data['country'] ?? null,
            'portion' => $data['portion'] ?? 'medium',
            'allergens' => $data['allergens'] ?? [],
            'user_email' => $userEmail,
        ];

        $existing = MealPlan::query()
            ->when($userEmail, fn ($q) => $q->where('user_email', $userEmail))
            ->first();

        $result = $service->ensurePlan($existing, $preferences, (bool) ($data['force_refresh'] ?? false));
        $plan = $result['plan'];

        // Persist the user association if it was missing on the plan.
        if ($userEmail && !$plan->user_email) {
            $plan->user_email = $userEmail;
            $plan->save();
        }

        $payloadPlan = $plan->data ?? [];
        $payloadPlan['week_start'] = $payloadPlan['week_start'] ?? $plan->week_start_date?->toDateString();
        $payloadPlan['week_end'] = $payloadPlan['week_end']
            ?? optional($plan->week_start_date)?->copy()->addDays(6)->toDateString();

        return response()->json([
            'plan' => $payloadPlan,
            'meta' => $plan->meta ?? [],
            'generated_at' => $plan->generated_at?->toIso8601String(),
            'daily_refreshed_at' => $plan->daily_refreshed_at?->toIso8601String(),
            'expires_at' => $plan->expires_at?->toIso8601String(),
            'status' => $plan->status ?? null,
            'pending' => ($plan->status ?? null) === 'pending' || ($plan->status ?? null) === 'running',
            'error' => $plan->last_error ?? null,
            'refreshed' => [
                'weekly' => $result['weekly_regenerated'],
                'daily' => $result['daily_refreshed'],
            ],
        ]);
    }
}
