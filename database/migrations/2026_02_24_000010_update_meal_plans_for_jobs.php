<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('meal_plans', function (Blueprint $table) {
            $table->string('fingerprint')->nullable()->index();
            $table->string('status')->default('pending')->index();
            $table->text('last_error')->nullable();
            $table->timestamp('last_started_at')->nullable();
            $table->timestamp('last_finished_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('meal_plans', function (Blueprint $table) {
            $table->dropColumn([
                'fingerprint',
                'status',
                'last_error',
                'last_started_at',
                'last_finished_at',
                'attempts',
            ]);
        });
    }
};
