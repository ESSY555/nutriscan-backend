<?php

namespace App\Services;

use App\Models\MealPlan;
use Carbon\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class MealPlanService
{
    /**
     * Day labels expected by the mobile client.
     *
     * @var array<int, string>
     */
    private array $dayKeys = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    public function __construct(private readonly AiMealPlanService $ai)
    {
    }

    /**
     * Ensure a weekly plan exists and refresh daily portion when needed.
     *
     * @return array{plan: MealPlan, weekly_regenerated: bool, daily_refreshed: bool}
     */
    public function ensurePlan(?MealPlan $plan, array $preferences, bool $forceWeeklyRefresh = false): array
    {
        $prefs = $this->normalizePreferences($preferences);
        $fingerprint = $this->fingerprint($prefs);
        $plan ??= new MealPlan([
            'user_email' => $preferences['user_email'] ?? null,
        ]);
        $plan->fingerprint = $plan->fingerprint ?: $fingerprint;

        $weeklyExpired = $this->weeklyExpired($plan);
        $preferencesChanged = $this->preferencesChanged($plan, $prefs);
        if ($preferencesChanged) {
            $plan->fingerprint = $fingerprint;
        }
        $weeklyRegenerated = false;
        $dailyRefreshed = false;

        if ($forceWeeklyRefresh || $weeklyExpired || $preferencesChanged || empty($plan->data)) {
            $this->enqueueWeekly($plan, $prefs);
            $weeklyRegenerated = true;
            $dailyRefreshed = true; // daily will be part of weekly generation
        } elseif ($this->shouldRefreshDaily($plan)) {
            $this->enqueueDaily($plan, $prefs);
            $dailyRefreshed = true;
        }

        return [
            'plan' => $plan,
            'weekly_regenerated' => $weeklyRegenerated,
            'daily_refreshed' => $dailyRefreshed,
        ];
    }

    /**
     * Build a full weekly plan and the current daily snapshot.
     *
     * @return array<string, mixed>
     */
    private function normalizePreferences(array $input): array
    {
        $allergens = array_values(array_filter(array_map(
            fn ($a) => trim((string) $a),
            $input['allergens'] ?? []
        )));

        return [
            'diet' => $input['diet'] ?? 'No Preference',
            'goal' => $input['goal'] ?? 'Eat Healthier',
            'country' => $input['country'] ?? null,
            'allergens' => $allergens,
            'portion' => $input['portion'] ?? 'medium',
        ];
    }

    private function preferencesChanged(MealPlan $plan, array $newPrefs): bool
    {
        $current = $this->normalizePreferences($plan->meta ?? []);

        return $this->fingerprint($current) !== $this->fingerprint($newPrefs);
    }

    private function fingerprint(array $data): string
    {
        ksort($data);
        return md5(json_encode($data));
    }

    private function weeklyExpired(MealPlan $plan): bool
    {
        if (!$plan->week_start_date || !$plan->expires_at) {
            return true;
        }

        return $plan->expires_at->isPast();
    }

    private function shouldRefreshDaily(MealPlan $plan): bool
    {
        if (!$plan->daily_refreshed_at) {
            return true;
        }

        $today = Carbon::now()->toDateString();
        $dailyDate = $plan->data['daily']['date'] ?? null;
        if ($dailyDate !== $today) {
            return true;
        }

        return $plan->daily_refreshed_at->lt(Carbon::now()->subDay());
    }

    private function enqueueWeekly(MealPlan $plan, array $prefs): void
    {
        $plan->forceFill([
            'meta' => $prefs,
            'status' => 'pending',
            'last_error' => null,
            'last_started_at' => null,
            'last_finished_at' => null,
        ])->save();

        Bus::dispatch(new \App\Jobs\GenerateMealPlanJob($plan, $prefs, false));
    }

    private function enqueueDaily(MealPlan $plan, array $prefs): void
    {
        $plan->forceFill([
            'meta' => $prefs,
            'status' => 'pending',
            'last_error' => null,
            'last_started_at' => null,
            'last_finished_at' => null,
        ])->save();

        Bus::dispatch(new \App\Jobs\GenerateMealPlanJob($plan, $prefs, true));
    }

    /**
     * Used by the async job to refresh daily data with AI.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function refreshDailyDataWithAi(array $data, array $preferences): array
    {
        $prefs = $this->normalizePreferences($preferences);
        $today = Carbon::now();
        $dayKey = $this->dayKeys[$today->dayOfWeekIso - 1] ?? 'Mon';

        $dailyMeals = $this->ai->generateDailyMeals($prefs);

        $data['days'] ??= [];
        $data['days'][$dayKey] = [
            'date' => $today->toDateString(),
            'meals' => $dailyMeals,
        ];

        $data['daily'] = [
            'date' => $today->toDateString(),
            'meals' => $dailyMeals,
        ];

        // Preserve week boundaries when present.
        $data['week_start'] = $data['week_start'] ?? $today->startOfWeek(Carbon::MONDAY)->toDateString();
        $data['week_end'] = $data['week_end'] ?? $today->startOfWeek(Carbon::MONDAY)->addDays(6)->toDateString();

        return $data;
    }

    public function applyWeeklyData(MealPlan $plan, array $data, array $meta, string $status = 'success'): void
    {
        $weekStart = Carbon::parse($data['week_start'] ?? Carbon::now());
        $plan->forceFill([
            'data' => $data,
            'meta' => $meta,
            'week_start_date' => $weekStart->toDateString(),
            'generated_at' => Carbon::now(),
            'daily_refreshed_at' => Carbon::now(),
            'expires_at' => (clone $weekStart)->addDays(7),
            'status' => $status,
            'last_error' => null,
            'last_finished_at' => Carbon::now(),
        ])->save();
    }

    public function applyDailyData(MealPlan $plan, array $data, string $status = 'success'): void
    {
        $plan->forceFill([
            'data' => $data,
            'daily_refreshed_at' => Carbon::now(),
            'status' => $status,
            'last_error' => null,
            'last_finished_at' => Carbon::now(),
        ])->save();
    }
}
