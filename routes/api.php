<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\MealPlanController;
use App\Http\Controllers\MealController;
use App\Http\Controllers\CravingController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\NutritionTipController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
});

Route::prefix('meals')->group(function () {
    Route::post('context', [MealController::class, 'context']);
    Route::get('search', [MealController::class, 'search']);
    Route::get('{id}', [MealController::class, 'show']);
});

Route::prefix('meal-plans')->group(function () {
    Route::post('sync', [MealPlanController::class, 'sync']);
});

Route::post('profile/preferences', [ProfileController::class, 'updatePreferences']);
Route::post('profile/avatar', [ProfileController::class, 'uploadAvatar']);
Route::post('nutrition-tip', [NutritionTipController::class, 'tip']);

Route::post('cravings/generate', [CravingController::class, 'generate']);
Route::get('cravings', [CravingController::class, 'index']);
Route::post('cravings', [CravingController::class, 'store']);
Route::delete('cravings/{id}', [CravingController::class, 'destroy']);
