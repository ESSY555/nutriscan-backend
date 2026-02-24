<?php

namespace App\Jobs;

use App\Models\MealPlan;
use App\Services\AiMealPlanService;
use App\Services\MealPlanService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateMealPlanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly MealPlan $plan,
        private readonly array $preferences,
        private readonly bool $dailyOnly = false
    ) {
        $this->queue = 'meal-plans';
    }

    public function handle(AiMealPlanService $ai, MealPlanService $service): void
    {
        $plan = $this->plan->fresh();
        if (!$plan) {
            return;
        }

        $plan->forceFill([
            'status' => 'running',
            'last_started_at' => Carbon::now(),
            'last_error' => null,
            'attempts' => ($plan->attempts ?? 0) + 1,
        ])->save();

        try {
            if ($this->dailyOnly && $plan->data) {
                $data = $service->refreshDailyDataWithAi($plan->data, $this->preferences);
                $service->applyDailyData($plan, $data, status: 'success');
            } else {
                $data = $ai->generateWeeklyPlan($this->preferences);
                $service->applyWeeklyData($plan, $data, $this->preferences, status: 'success');
            }
        } catch (\Throwable $e) {
            Log::error('GenerateMealPlanJob failed', [
                'plan_id' => $plan->id,
                'daily_only' => $this->dailyOnly,
                'error' => $e->getMessage(),
            ]);
            $plan->forceFill([
                'status' => 'error',
                'last_error' => $e->getMessage(),
                'last_finished_at' => Carbon::now(),
            ])->save();
            throw $e;
        }
    }
}
