<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * `Auditable` (dashboard#119) : historique complet résolu/rouvert/requalifié,
 * individuel ET en masse (`Admin\CurationFlagController::bulk()`) — jusqu'ici
 * seul l'état courant (`resolved_by`/`resolved_at`) était fiable, sans trace
 * des aller-retours. Même mécanisme que `LegalDocument`, table `audits`
 * déjà générique.
 */
class CurationFlag extends Model implements Auditable
{
    use HasFactory, HasUuids, \OwenIt\Auditing\Auditable;

    protected $table = 'curation_flags';

    const UPDATED_AT = null;

    protected $fillable = [
        'document_id',
        'article_id',
        'node_id',
        'source',
        'created_by',
        'type_probleme',
        'severity',
        'description',
        'suggestion',
        'anchor',
        'confidence',
        'run_id',
        'resolved',
        'resolved_at',
        'resolved_by',
    ];

    protected $casts = [
        'resolved' => 'boolean',
        'resolved_at' => 'datetime',
        'suggestion' => 'array',
        'anchor' => 'array',
        'confidence' => 'float',
    ];

    /** Origines possibles d'un signalement. Les flags `human` ne sont jamais purgés. */
    const SOURCE_HEURISTIC = 'heuristic';

    const SOURCE_STRUCTURAL = 'structural';

    const SOURCE_LLM = 'llm';

    const SOURCE_HUMAN = 'human';

    /**
     * Signalement public (app mobile, sans compte). Distinct de `human`
     * (réservé aux éditeurs authentifiés) : jamais purgé par les détecteurs,
     * mais ne doit jamais bloquer une publication tant qu'un admin ne l'a
     * pas requalifié au triage.
     */
    const SOURCE_REPORT = 'report';

    /**
     * Jeu de détecteurs de contenu v3 planifié (mibeko-dashboard#141, § 3.5
     * du plan « boîte de réception »). Jamais `structural` : ce dernier
     * purge tous ses propres signalements non résolus à chaque exécution
     * (`StructuralAnomalyDetector::detect()`) — partager la valeur ferait
     * que les deux mécanismes se marcheraient dessus à chaque passage.
     */
    const SOURCE_CONFORMITE = 'conformite';

    /**
     * Demande de texte manquant (mibeko-front#34) : seul `type_probleme` sans
     * cible possible — `document_id`/`article_id` restent nuls, la demande
     * elle-même est dans `description`. Distinct des autres valeurs de
     * `type_probleme` (chaîne libre posée par les détecteurs), celle-ci a une
     * constante parce qu'elle conditionne une branche de validation dans
     * `CurationFlagController::storeMissingText()`.
     */
    const TYPE_TEXTE_MANQUANT = 'texte_manquant';

    /** Sévérités : seul `blocking` empêche la publication. */
    const SEVERITY_BLOCKING = 'blocking';

    const SEVERITY_WARNING = 'warning';

    const SEVERITY_INFO = 'info';

    /**
     * Get the document that was flagged.
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(LegalDocument::class, 'document_id');
    }

    /**
     * Get the article that was flagged.
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'article_id');
    }

    /**
     * Get the structure node (division) that was flagged, if any.
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(StructureNode::class, 'node_id');
    }

    /**
     * Get the admin who resolved the flag, if any.
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * Éditeur à l'origine d'un signalement humain (ex : demande de correction
     * transmise depuis la file de revue, mibeko-front#33). Absent pour les
     * signalements automatiques (`source` heuristic/structural/llm) et les
     * signalements publics (`source=report`, anonymes).
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
