<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Device extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'device_id',
        'push_token',
        'platform',
        'status',
        'last_registered_at',
    ];

    protected $casts = [
        'last_registered_at' => 'datetime',
    ];

    /**
     * Scope a query to only include active devices.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
