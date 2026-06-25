<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContactMessage extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'email',
        'profile',
        'message',
        'ip_address',
        'user_agent',
        'handled',
    ];

    protected function casts(): array
    {
        return [
            'handled' => 'boolean',
        ];
    }
}
