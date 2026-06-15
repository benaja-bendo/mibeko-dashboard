<?php

namespace App\Http\Resources\V1\Admin;

use App\Models\ArticleVersion;
use App\Models\DocumentType;
use App\Models\Dossier;
use App\Models\Institution;
use App\Models\LegalDocument;
use App\Models\OfficialJournal;
use App\Models\Tag;
use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OwenIt\Auditing\Models\Audit;

/**
 * Transforme une entrée d'audit owen-it en payload lisible pour l'admin.
 *
 * Le front ne manipule jamais de JSON brut : la resource produit une phrase
 * (`summary`), un libellé d'objet lisible (reconstruit depuis `old_values` même
 * si l'objet a été supprimé), un lien profond, et un diff structuré.
 *
 * @mixin Audit
 */
class AuditResource extends JsonResource
{
    /**
     * Métadonnées par type audité : libellé FR, attributs candidats pour le
     * titre lisible, et base de lien profond vers l'objet côté front.
     *
     * @var array<class-string, array{label: string, title: array<int, string>, link: ?string}>
     */
    private const TYPE_META = [
        User::class => ['label' => 'Utilisateur', 'title' => ['name', 'email'], 'link' => '/admin/utilisateurs'],
        UserSetting::class => ['label' => 'Préférences', 'title' => [], 'link' => '/admin/utilisateurs'],
        LegalDocument::class => ['label' => 'Document', 'title' => ['titre_officiel', 'title'], 'link' => '/editor/viewer/'],
        ArticleVersion::class => ['label' => 'Version d’article', 'title' => ['numero_article'], 'link' => null],
        Dossier::class => ['label' => 'Dossier', 'title' => ['name'], 'link' => '/app/dossiers'],
        Institution::class => ['label' => 'Institution', 'title' => ['nom', 'sigle'], 'link' => '/admin/referentiels'],
        Tag::class => ['label' => 'Tag', 'title' => ['name'], 'link' => '/admin/referentiels'],
        OfficialJournal::class => ['label' => 'Journal officiel', 'title' => ['title', 'number'], 'link' => '/editor/journals/'],
        DocumentType::class => ['label' => 'Type de loi', 'title' => ['nom', 'code'], 'link' => '/admin/referentiels'],
    ];

    /**
     * Libellés FR des événements.
     *
     * @var array<string, string>
     */
    private const EVENT_LABELS = [
        'created' => 'Création',
        'updated' => 'Modification',
        'deleted' => 'Suppression',
        'restored' => 'Restauration',
        'impersonation_started' => 'Impersonation',
        'roles_updated' => 'Changement de rôles',
    ];

    /**
     * Libellés FR des champs les plus courants (fallback sur la clé brute).
     *
     * @var array<string, string>
     */
    private const FIELD_LABELS = [
        'name' => 'Nom',
        'nom' => 'Nom',
        'email' => 'Email',
        'status' => 'Statut',
        'suspension_reason' => 'Motif de suspension',
        'titre_officiel' => 'Titre',
        'title' => 'Titre',
        'number' => 'Numéro',
        'sigle' => 'Sigle',
        'slug' => 'Slug',
        'is_published' => 'Publié',
        'transcription_status' => 'Transcription',
        'niveau_hierarchique' => 'Niveau hiérarchique',
        'roles' => 'Rôles',
        'legal_domain' => 'Domaine juridique',
        'client_name' => 'Client',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $meta = self::TYPE_META[$this->auditable_type] ?? null;
        $values = array_merge((array) $this->old_values, (array) $this->new_values);

        return [
            'id' => $this->id,
            'event' => $this->event,
            'event_label' => self::EVENT_LABELS[$this->event] ?? ucfirst($this->event),
            'actor' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ] : null, null),
            'object' => [
                'type' => class_basename($this->auditable_type),
                'type_label' => $meta['label'] ?? class_basename($this->auditable_type),
                'id' => $this->auditable_id,
                'label' => $this->objectLabel($meta, $values),
                'link' => $this->objectLink($meta, $values),
            ],
            'summary' => $this->summary($meta, $values),
            'changes' => $this->changes(),
            'ip_address' => $this->ip_address,
            'url' => $this->url,
            'user_agent' => $this->user_agent,
            'tags' => $this->tags,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }

    /**
     * Titre lisible de l'objet : depuis l'instance chargée si dispo, sinon
     * reconstruit depuis les valeurs auditées (utile pour un objet supprimé).
     *
     * @param  array{label: string, title: array<int, string>, link: ?string}|null  $meta
     * @param  array<string, mixed>  $values
     */
    private function objectLabel(?array $meta, array $values): string
    {
        $candidates = $meta['title'] ?? [];

        $auditable = $this->resource->relationLoaded('auditable') ? $this->resource->auditable : null;

        foreach ($candidates as $attr) {
            $value = $auditable?->getAttribute($attr) ?? ($values[$attr] ?? null);
            if (is_string($value) && $value !== '') {
                return mb_strlen($value) > 80 ? mb_substr($value, 0, 80).'…' : $value;
            }
        }

        return '#'.mb_substr((string) $this->auditable_id, 0, 8);
    }

    /**
     * Lien profond vers l'objet côté front (null si non navigable).
     *
     * @param  array{label: string, title: array<int, string>, link: ?string}|null  $meta
     * @param  array<string, mixed>  $values
     */
    private function objectLink(?array $meta, array $values): ?string
    {
        $base = $meta['link'] ?? null;
        if ($base === null) {
            return null;
        }

        // Liens fiche utilisateur : focaliser sur l'utilisateur concerné.
        if ($this->auditable_type === User::class) {
            return $base.'?focus='.$this->auditable_id;
        }

        if ($this->auditable_type === UserSetting::class) {
            $userId = $values['user_id'] ?? null;

            return $userId ? $base.'?focus='.$userId : $base;
        }

        // Liens « espace » (référentiels, dossiers) : pas d'ancre par id.
        if (! str_ends_with($base, '/')) {
            return $base;
        }

        return $base.$this->auditable_id;
    }

    /**
     * Phrase d'action (le front la préfixe du nom de l'acteur).
     *
     * @param  array{label: string, title: array<int, string>, link: ?string}|null  $meta
     * @param  array<string, mixed>  $values
     */
    private function summary(?array $meta, array $values): string
    {
        $typeLabel = mb_strtolower($meta['label'] ?? 'élément');
        $label = $this->objectLabel($meta, $values);
        $new = (array) $this->new_values;

        return match ($this->event) {
            'created' => "a créé le {$typeLabel} « {$label} »",
            'deleted' => "a supprimé le {$typeLabel} « {$label} »",
            'restored' => "a restauré le {$typeLabel} « {$label} »",
            'impersonation_started' => "a incarné le compte de {$label}",
            'roles_updated' => "a changé les rôles de {$label}",
            'updated' => $this->updateSummary($typeLabel, $label, $new),
            default => "a {$this->event} le {$typeLabel} « {$label} »",
        };
    }

    /**
     * Nuance les modifications les plus parlantes (suspension, publication…).
     *
     * @param  array<string, mixed>  $new
     */
    private function updateSummary(string $typeLabel, string $label, array $new): string
    {
        if ($this->auditable_type === User::class && array_key_exists('status', $new)) {
            return match ($new['status']) {
                'suspended' => "a suspendu le compte de {$label}",
                'active' => "a réactivé le compte de {$label}",
                default => "a modifié le statut de {$label}",
            };
        }

        if (array_key_exists('is_published', $new)) {
            return $new['is_published']
                ? "a publié le {$typeLabel} « {$label} »"
                : "a dépublié le {$typeLabel} « {$label} »";
        }

        return "a modifié le {$typeLabel} « {$label} »";
    }

    /**
     * Diff structuré, robuste : union des clés de old_values et new_values.
     *
     * @return array<int, array{field: string, field_label: string, old: mixed, new: mixed}>
     */
    private function changes(): array
    {
        $old = (array) $this->old_values;
        $new = (array) $this->new_values;
        $keys = array_values(array_unique(array_merge(array_keys($old), array_keys($new))));

        return array_map(fn (string $key) => [
            'field' => $key,
            'field_label' => self::FIELD_LABELS[$key] ?? $key,
            'old' => $old[$key] ?? null,
            'new' => $new[$key] ?? null,
        ], $keys);
    }
}
