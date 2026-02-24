<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('craving_ideas', function (Blueprint $table) {
            $table->id();
            $table->string('user_email')->nullable()->index();
            $table->string('title');
            $table->text('note')->nullable();
            $table->string('craving_prompt')->nullable();
            $table->string('country')->nullable();
            $table->string('diet')->nullable();
            $table->string('goal')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('craving_ideas');
    }
};
