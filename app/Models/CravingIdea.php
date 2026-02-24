<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CravingIdea extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_email',
        'title',
        'note',
        'craving_prompt',
        'country',
        'diet',
        'goal',
        'fingerprint',
        'ideas_text',
        'allergens',
    ];

    protected $casts = [
        'allergens' => 'array',
    ];
}
