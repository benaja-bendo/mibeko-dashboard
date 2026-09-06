<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MobileProfile extends Model
{
    /**
     * Liste fermée — mibeko-dashboard#98. Voir la migration de normalisation
     * pour le détail des orthographes regroupées derrière chaque valeur.
     *
     * @var list<string>
     */
    public const PROFESSIONS = ['Citoyen', 'Étudiant', 'Professionnel du droit', 'Autre'];

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
