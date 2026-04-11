<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MobileProfile extends Model
{
    protected $fillable = [
        'user_id',
        'phone',
        'dob',
        'gender',
        'profession',
        'company',
        'legal_interests',
        'app_preferences',
    ];

    protected function casts(): array
    {
        return [
            'app_preferences' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
