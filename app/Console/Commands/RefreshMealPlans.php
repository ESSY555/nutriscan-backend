<?php

namespace App\Console\Commands;

use App\Models\MealPlan;
use App\Services\MealPlanService;
use Illuminate\Console\Command;

class RefreshMealPlans extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'meal-plans:refresh {--force-weekly : Regenerate weekly plans even if not expired}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Refresh weekly and daily meal plans according to their schedules.';

    public function handle(MealPlanService $service): int
    {
        $forceWeekly = (bool) $this->option('force-weekly');
        $updated = 0;
        $refreshedDaily = 0;

        MealPlan::query()->chunkById(50, function ($plans) use ($service, $forceWeekly, &$updated, &$refreshedDaily) {
            foreach ($plans as $plan) {
                $prefs = $plan->meta ?? [];
                $result = $service->ensurePlan($plan, $prefs, $forceWeekly);

                if ($result['weekly_regenerated']) {
                    $updated++;
                    $this->line("Weekly plan regenerated for ID {$plan->id}");
                }

                if ($result['daily_refreshed']) {
                    $refreshedDaily++;
                }
            }
        });

        $this->info("Meal plans refreshed. Weekly regenerated: {$updated}, daily refreshed: {$refreshedDaily}.");

        return Command::SUCCESS;
    }
}
