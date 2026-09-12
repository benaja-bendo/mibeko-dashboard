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

    /**
     * Codes stables non traduits — mibeko-dashboard#135. Successeur de
     * `PROFESSIONS` en tant que catégorie, mais partagé à l'identique par le
     * web et le mobile (les libellés de présentation vivent côté client).
     *
     * @var list<string>
     */
    public const USAGE_CONTEXTS = ['personal', 'studies', 'professional', 'other'];

    /**
     * Mapping catégorie ↔ catégorie UNIQUEMENT — ne jamais l'étendre à
     * `job_title` : « Professionnel du droit » ne prouve pas « Avocat ».
     *
     * @var array<string, string>
     */
    private const PROFESSION_BY_USAGE_CONTEXT = [
        'personal' => 'Citoyen',
        'studies' => 'Étudiant',
        'professional' => 'Professionnel du droit',
        'other' => 'Autre',
    ];

    protected $fillable = [
        'user_id',
        'phone',
        'dob',
        'gender',
        'profession',
        'usage_context',
        'job_title',
        'company',
        'legal_interests',
        'app_preferences',
    ];

    /**
     * Dérive le code `usage_context` depuis un `profession` historique.
     * Jamais l'inverse d'un métier — seule la catégorie se déduit.
     */
    public static function deriveUsageContext(?string $profession): ?string
    {
        return array_flip(self::PROFESSION_BY_USAGE_CONTEXT)[$profession] ?? null;
    }

    /**
     * Dérive le libellé `profession` historique depuis un `usage_context`,
     * pour que les anciennes apps mobiles (qui ne connaissent que
     * `profession`) restent cohérentes après une écriture faite via le
     * nouveau champ.
     */
    public static function deriveProfession(?string $usageContext): ?string
    {
        return self::PROFESSION_BY_USAGE_CONTEXT[$usageContext] ?? null;
    }

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
