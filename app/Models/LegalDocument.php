<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OwenIt\Auditing\Contracts\Auditable;

class LegalDocument extends Model implements Auditable
{
    use HasFactory, HasUuids, \OwenIt\Auditing\Auditable, SoftDeletes;

    /**
     * Contexte de transition NON persisté (audit
     * docs/audit-ingestion-2026-08-02.md phase 3b) : motif d'une
     * dépublication, à renseigner par le contrôleur avant `save()`/`update()`.
     * Propriété PHP déclarée (pas un attribut Eloquent) — un simple
     * `$document->transitionMotif = …` sur un nom non déclaré serait capté
     * par les magic methods d'Eloquent et tenterait d'écrire une colonne
     * `transition_motif` inexistante.
     */
    public ?string $transitionMotif = null;

    protected $auditExclude = [
        'created_at',
        'updated_at',
    ];

    protected $fillable = [
        'type_code',
        'institution_id',
        'official_journal_id',
        'document_key',
        'document_role',
        'consolidation_as_of',
        'stock_code',
        'titre_officiel',
        'slug',
        'reference_nor',
        'date_signature',
        'date_publication',
        'date_entree_vigueur',
        'date_entree_vigueur_inconnue',
        'statut',
        'statut_verifie_le',
        'statut_verifie_par',
        'curation_status',
        'extraction_status',
        'metadata',
        'legal_scope',
    ];

    const SCOPE_NATIONAL = 'national';

    const SCOPE_OHADA = 'ohada';

    const SCOPE_COMMUNAUTAIRE = 'communautaire';

    /** @var array<int, string> Périmètres juridiques autorisés. */
    const LEGAL_SCOPES = [self::SCOPE_NATIONAL, self::SCOPE_OHADA, self::SCOPE_COMMUNAUTAIRE];

    const STATUS_DRAFT = 'draft';

    const STATUS_REVIEW = 'review';

    const STATUS_VALIDATED = 'validated';

    const STATUS_PUBLISHED = 'published';

    /**
     * Machine à états maison de `curation_status` (audit
     * docs/audit-ingestion-2026-08-02.md, phase 3b — implémentation maison,
     * aucun package de state machine). Deux listes de transitions autorisées
     * SANS condition particulière ; toute sortie de PUBLISHED est traitée à
     * part (cf. `guardUnpublishing`, admin + motif obligatoires).
     *
     * `review` → `published` (sans passer par `validated`) est autorisé :
     * constaté à l'exécution réelle des tests existants (LegalWatchNotificationTest
     * et consorts, 25 cas) que ce raccourci est un usage établi et voulu de
     * l'application — `validated` est une étape optionnelle, pas un palier
     * obligatoire. `draft` → `validated` et `draft` → `published` restent en
     * revanche refusés (aucun usage existant ne s'appuie dessus, et c'est le
     * saut que l'audit visait explicitement : sauter la revue elle-même).
     *
     * @var array<string, string[]>
     */
    const CURATION_TRANSITIONS_AVANT = [
        self::STATUS_DRAFT => [self::STATUS_REVIEW],
        self::STATUS_REVIEW => [self::STATUS_VALIDATED, self::STATUS_PUBLISHED],
        self::STATUS_VALIDATED => [self::STATUS_PUBLISHED],
    ];

    /**
     * Retours arrière explicitement autorisés (mission phase 3b) —
     * PUBLISHED en est volontairement absent : cf. `guardUnpublishing`.
     *
     * @var array<string, string[]>
     */
    const CURATION_TRANSITIONS_ARRIERE = [
        self::STATUS_REVIEW => [self::STATUS_DRAFT],
        self::STATUS_VALIDATED => [self::STATUS_REVIEW, self::STATUS_DRAFT],
    ];

    protected function casts(): array
    {
        return [
            'date_signature' => 'date',
            'date_publication' => 'date',
            'date_entree_vigueur' => 'date',
            'date_entree_vigueur_inconnue' => 'boolean',
            'statut_verifie_le' => 'datetime',
            'consolidation_as_of' => 'date',
            'watch_notified_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * Cascade le soft-delete vers les articles.
     *
     * Les articles ne sont pas supprimés en base avec leur document : sans ce
     * relai, ils « fuitent » dans les recherches d'articles, le sélecteur de
     * relations et toute requête directe sur Article. On restaure de même.
     */
    protected static function booted(): void
    {
        // Garantit le slug à *chaque* écriture Eloquent (création comme mise à
        // jour), et pas seulement à la création : le pipeline Python insère les
        // documents directement en base (sans slug), puis la publication passe
        // par Eloquent (`update()`) — c'est ce `saving` qui répare alors le slug
        // manquant, sans quoi le texte publié resterait invisible du site
        // vitrine (filtré sur la présence d'un slug). Le backfill planifié
        // (`mibeko:backfill-document-slugs`) couvre les chemins hors-Eloquent
        // (mise à jour de masse SQL, insertions brutes).
        static::saving(function (LegalDocument $document) {
            if (empty($document->slug)) {
                $document->slug = static::generateUniqueSlug(
                    $document->titre_officiel ?: $document->id ?: 'document',
                    $document->id,
                );
            }
        });

        // Garde de transition (audit docs/audit-ingestion-2026-08-02.md,
        // phase 3b) : ne s'applique qu'aux écritures Eloquent d'un document
        // DÉJÀ existant dont `curation_status` change réellement — jamais à
        // la création, ni aux mises à jour qui ne touchent pas ce champ.
        static::saving(function (LegalDocument $document) {
            if ($document->exists && $document->isDirty('curation_status')) {
                static::guardCurationStatusTransition($document);
            }
        });

        static::deleting(function (LegalDocument $document) {
            if ($document->isForceDeleting()) {
                return;
            }

            $document->articles()->delete();
        });

        static::restoring(function (LegalDocument $document) {
            $document->articles()->onlyTrashed()->restore();
        });
    }

    /**
     * Vérifie qu'une transition de `curation_status` est autorisée. Lève une
     * `ValidationException` (422) sinon — jamais un simple `return false`
     * silencieux, cohérent avec les autres garde-fous de publication du
     * contrôleur.
     */
    protected static function guardCurationStatusTransition(LegalDocument $document): void
    {
        $from = $document->getOriginal('curation_status');
        $to = $document->curation_status;

        if ($from === null || $from === $to) {
            return;
        }

        if ($from === self::STATUS_PUBLISHED) {
            static::guardUnpublishing($document, $from, $to);

            return;
        }

        $autorisee = in_array($to, self::CURATION_TRANSITIONS_AVANT[$from] ?? [], true)
            || in_array($to, self::CURATION_TRANSITIONS_ARRIERE[$from] ?? [], true);

        if (! $autorisee) {
            throw ValidationException::withMessages([
                'curation_status' => ["Transition de statut « {$from} » → « {$to} » non autorisée."],
            ]);
        }
    }

    /**
     * Sortie de PUBLISHED : réservée aux administrateurs, motif obligatoire,
     * décision tracée (même esprit que la publication forcée,
     * `LegalDocumentController::update`) — un document déjà publié est
     * visible du public, en sortir n'est jamais anodin.
     */
    protected static function guardUnpublishing(LegalDocument $document, string $from, string $to): void
    {
        $user = auth()->user();
        $estAdmin = $user && method_exists($user, 'hasRole') && $user->hasRole('admin');
        $motif = trim((string) ($document->transitionMotif ?? ''));

        if (! $estAdmin || $motif === '') {
            throw ValidationException::withMessages([
                'curation_status' => [
                    "Dépublication (« {$from} » → « {$to} ») réservée aux administrateurs, avec un motif obligatoire.",
                ],
            ]);
        }

        Log::warning('Dépublication d\'un document juridique.', [
            'document_id' => $document->id,
            'user_id' => $user->id,
            'from' => $from,
            'to' => $to,
            'motif' => $motif,
        ]);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'type_code', 'code');
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    /**
     * Récupère le journal officiel dans lequel ce document a été publié.
     */
    public function officialJournal(): BelongsTo
    {
        return $this->belongsTo(OfficialJournal::class, 'official_journal_id');
    }

    public function structureNodes(): HasMany
    {
        return $this->hasMany(StructureNode::class, 'document_id');
    }

    public function articles(): HasMany
    {
        return $this->hasMany(Article::class, 'document_id');
    }

    public function relations(): HasMany
    {
        return $this->hasMany(DocumentRelation::class, 'source_doc_id');
    }

    /**
     * Récupère les anomalies de curation (trous/doublons de numérotation, etc.)
     * détectées à l'ingestion. Servent de garde-fou : un document conservant des
     * anomalies non résolues ne doit pas être publié au catalogue.
     */
    public function curationFlags(): HasMany
    {
        return $this->hasMany(CurationFlag::class, 'document_id');
    }

    /**
     * Récupère les fichiers médias associés au document.
     */
    public function mediaFiles(): HasMany
    {
        return $this->hasMany(MediaFile::class, 'document_id');
    }

    /**
     * Récupère tous les tags du document juridique.
     */
    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    /**
     * Scope a query to only include published documents that have articles.
     */
    public function scopePublished($query)
    {
        return $query->where('curation_status', self::STATUS_PUBLISHED)
            ->whereHas('articles');
    }

    /**
     * Génère un slug unique et stable à partir d'un titre.
     *
     * Le slug est tronqué à 80 caractères pour rester lisible dans une URL, et
     * suffixé (`-2`, `-3`, …) en cas de collision avec un document existant
     * (corbeille incluse, pour ne pas réutiliser le slug d'un texte restauré).
     */
    public static function generateUniqueSlug(string $source, ?string $ignoreId = null): string
    {
        $base = trim(Str::limit(Str::slug($source), 80, ''), '-');

        if ($base === '') {
            $base = 'document';
        }

        $slug = $base;
        $suffix = 2;

        while (static::withTrashed()
            ->where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    /**
     * Répare le slug des documents qui n'en ont pas.
     *
     * Point unique de réparation, partagé par la commande planifiée
     * (`mibeko:backfill-document-slugs`, filet de sécurité horaire) et par la
     * veille légale, qui doit garantir un slug AVANT d'annoncer un texte : le
     * slug est la cible du deep link, une alerte sans slug retombe sur
     * l'accueil de l'app et n'est plus rattrapable une fois le document marqué
     * comme annoncé.
     *
     * Idempotent (ne touche que les slugs vides) et sûr en production.
     * `chunkById` ordonne par clé primaire : ne PAS ajouter d'orderBy, le
     * curseur sauterait des lignes au-delà du premier lot. `saveQuietly` écrit
     * sans déclencher d'événement (l'audit n'a pas à journaliser ce backfill
     * technique) ; chaque slug posé est visible des itérations suivantes, ce qui
     * garantit l'unicité au fil de l'eau.
     *
     * @param  array<int, string>|null  $ids  Restreint la réparation à ces documents (null = tous).
     * @return int Nombre de slugs générés.
     */
    public static function backfillMissingSlugs(?array $ids = null): int
    {
        $backfilled = 0;

        static::missingSlug($ids)->chunkById(200, function ($documents) use (&$backfilled): void {
            foreach ($documents as $document) {
                $document->slug = static::generateUniqueSlug(
                    $document->titre_officiel ?: $document->id,
                    $document->id,
                );
                $document->saveQuietly();
                $backfilled++;
            }
        });

        return $backfilled;
    }

    /**
     * Documents dépourvus de slug (corbeille incluse : un texte restauré doit
     * lui aussi retrouver son URL).
     *
     * @param  array<int, string>|null  $ids
     * @return Builder<static>
     */
    public static function missingSlug(?array $ids = null)
    {
        return static::withTrashed()
            ->when($ids !== null, fn ($query) => $query->whereIn('id', $ids))
            ->where(function ($query) {
                $query->whereNull('slug')->orWhere('slug', '');
            });
    }
}
