<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Inscription à la newsletter (opt-in du site vitrine).
 *
 * @property string $email
 * @property string|null $source
 */
class NewsletterSubscription extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'email',
        'source',
    ];
}
