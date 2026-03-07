<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'country')) {
                $table->string('country', 120)->nullable()->after('allergens');
            }
            if (!Schema::hasColumn('users', 'diet_preference')) {
                $table->string('diet_preference', 120)->nullable()->after('country');
            }
            if (!Schema::hasColumn('users', 'health_goal')) {
                $table->string('health_goal', 120)->nullable()->after('diet_preference');
            }
            if (!Schema::hasColumn('users', 'onboarding_completed')) {
                $table->boolean('onboarding_completed')->default(false)->after('health_goal');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'country')) {
                $table->dropColumn('country');
            }
            if (Schema::hasColumn('users', 'diet_preference')) {
                $table->dropColumn('diet_preference');
            }
            if (Schema::hasColumn('users', 'health_goal')) {
                $table->dropColumn('health_goal');
            }
            if (Schema::hasColumn('users', 'onboarding_completed')) {
                $table->dropColumn('onboarding_completed');
            }
        });
    }
};
