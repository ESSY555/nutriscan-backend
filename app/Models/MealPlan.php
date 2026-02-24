<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MealPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_email',
        'week_start_date',
        'data',
        'meta',
        'generated_at',
        'daily_refreshed_at',
        'expires_at',
        'fingerprint',
        'status',
        'last_error',
        'last_started_at',
        'last_finished_at',
        'attempts',
    ];

    protected $casts = [
        'week_start_date' => 'date',
        'data' => 'array',
        'meta' => 'array',
        'generated_at' => 'datetime',
        'daily_refreshed_at' => 'datetime',
        'expires_at' => 'datetime',
        'last_started_at' => 'datetime',
        'last_finished_at' => 'datetime',
    ];
}
