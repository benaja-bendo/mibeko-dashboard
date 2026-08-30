<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;
use Pgvector\Laravel\Vector;

class ArticleVersion extends Model implements Auditable
{
    use HasFactory, HasUuids, \OwenIt\Auditing\Auditable;

    protected $touches = ['article'];

    protected $auditExclude = [
        'search_tsv',
        'embedding',
        'created_at',
        'updated_at',
    ];

    protected $fillable = [
        'article_id',
        'validity_period',
        'contenu_texte',
        'embedding_context',
        'embedding',
        'modifie_par_document_id',
        'source_run_id',
        'source_media_file_id',
        'source_locator',
        'validation_status',
        'is_verified',
        'reviewed_by',
        'reviewed_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'embedding' => Vector::class,
            'is_verified' => 'boolean',
            'source_locator' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /**
     * Tableaux structurés de cette version, prêts à être servis à un client.
     *
     * Le pipeline linéarise chaque tableau dans `contenu_texte` (une ligne par
     * rangée, cellules séparées par « | ») et conserve sa forme canonique dans
     * `source_locator`, avec les bornes de lignes qu'il occupe. Un client qui
     * ignore ce champ affiche donc un texte lisible ; celui qui le lit remplace
     * ces lignes par un vrai tableau. Aucun balisage ne transite par
     * `contenu_texte` — c'est l'invariant, cf. `docs/decisions.md` (09/08/2026).
     *
     * `html_source` est délibérément retiré : c'est de la provenance destinée à
     * un retraitement côté pipeline, inutile à un client et coûteuse à
     * transporter jusqu'à un corpus mobile hors-ligne.
     *
     * Défensif par construction : le locator est du JSONB écrit par un autre
     * service, une entrée mal formée est ignorée plutôt que servie.
     *
     * @return array<int, array{caption: string|null, headers: array<int, string>, rows: array<int, array<int, string>>, line_start: int|null, line_end: int|null}>
     */
    public function publicTables(): array
    {
        $tables = $this->source_locator['tables'] ?? null;

        if (! is_array($tables)) {
            return [];
        }

        $toStringList = fn (mixed $cells): array => is_array($cells)
            ? array_values(array_map(fn (mixed $cell): string => is_scalar($cell) ? (string) $cell : '', $cells))
            : [];

        return array_values(array_filter(array_map(function (mixed $table) use ($toStringList): ?array {
            if (! is_array($table)) {
                return null;
            }

            $rows = array_values(array_map($toStringList, is_array($table['rows'] ?? null) ? $table['rows'] : []));
            $headers = $toStringList($table['headers'] ?? null);

            if ($headers === [] && $rows === []) {
                return null;
            }

            return [
                'caption' => is_string($table['caption'] ?? null) ? $table['caption'] : null,
                'headers' => $headers,
                'rows' => $rows,
                'line_start' => is_int($table['line_start'] ?? null) ? $table['line_start'] : null,
                'line_end' => is_int($table['line_end'] ?? null) ? $table['line_end'] : null,
            ];
        }, $tables)));
    }

    /**
     * Nature de la feuille posée à l'ingestion : `preamble`, `signature`,
     * `table`. Null pour un article ordinaire.
     */
    public function contentFormat(): ?string
    {
        $format = $this->source_locator['content_format'] ?? null;

        return is_string($format) ? $format : null;
    }

    /**
     * Page du PDF source où cet article a été trouvé, posée par l'ingestion à
     * partir des marqueurs `[[MIBEKO_PAGE:N]]` de la sortie MinerU.
     *
     * C'est la SEULE donnée de provenance dont on dispose au niveau de
     * l'article — et elle est vraie, contrairement à `validity_period` dont la
     * borne basse vaut la date d'ingestion (voir plus bas). Elle rend chaque
     * article vérifiable un par un contre le document d'origine.
     */
    public function sourcePage(): ?int
    {
        if (! is_array($this->source_locator)) {
            return null;
        }

        $page = $this->source_locator['page'] ?? null;

        return is_int($page) && $page > 0 ? $page : null;
    }

    /**
     * Juriste ayant relu ce contenu (null tant qu'aucune relecture n'a eu lieu).
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Borne basse (date de début) de la période de validité enregistrée.
     *
     * Le daterange Postgres revient sous forme de chaîne « [2020-01-01,) » ; on
     * en extrait la date de début. Renvoie null si la borne est ouverte/absente.
     *
     * Cette date reflète la période de validité ENREGISTRÉE, qui peut avoir été
     * initialisée à la date d'ingestion faute de mieux (cf. commentaire de la
     * colonne). Elle est donc exposée telle quelle, sans être présentée comme une
     * date d'entrée en vigueur garantie : l'honnêteté du signal l'exige.
     */
    public function getValidityStartAttribute(): ?string
    {
        if (! is_string($this->validity_period) || $this->validity_period === '') {
            return null;
        }

        // Capture la première date ISO après le crochet ouvrant « [ » ou « ( ».
        if (preg_match('/^[\[(]\s*(\d{4}-\d{2}-\d{2})/', $this->validity_period, $matches)) {
            return $matches[1];
        }

        return null;
    }

    public function sourceRun(): BelongsTo
    {
        return $this->belongsTo(ExtractionRun::class, 'source_run_id');
    }

    public function sourceMediaFile(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'source_media_file_id');
    }

    public function modifiedByDocument(): BelongsTo
    {
        return $this->belongsTo(LegalDocument::class, 'modifie_par_document_id');
    }

    /**
     * Helper to create a validity_period daterange string for PostgreSQL.
     * Format: [start_date, end_date) - inclusive start, exclusive end
     */
    public static function makeValidityPeriod(string $startDate, ?string $endDate = null): string
    {
        if ($endDate === null || $endDate === 'infinity') {
            return "[{$startDate},)";
        }

        return "[{$startDate}, {$endDate})";
    }
}
