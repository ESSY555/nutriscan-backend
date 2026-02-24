<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('craving_ideas', function (Blueprint $table) {
            $table->string('fingerprint')->nullable()->index();
            $table->text('ideas_text')->nullable();
            $table->json('allergens')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('craving_ideas', function (Blueprint $table) {
            $table->dropColumn(['fingerprint', 'ideas_text', 'allergens']);
        });
    }
};
