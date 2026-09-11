<?php

namespace App\Services\Curation;

/**
 * Résultat immuable d'une évaluation {@see PublicationGuardrail::evaluate()} :
 * de quoi décider (bloquer ou laisser passer) ET tracer (instantané des
 * critères pour `publication_checklists`), sans recalculer deux fois.
 */
class PublicationGuardrailResult
{
    /**
     * @param  array<string, string>  $reasons  Motifs de blocage, par champ (vide si $passed).
     * @param  array<string, mixed>  $criteria  Instantané chiffré de chaque critère, y compris les zéros.
     */
    public function __construct(
        public readonly bool $passed,
        public readonly bool $forced,
        public readonly array $reasons,
        public readonly array $criteria,
    ) {}

    public function failed(): bool
    {
        return ! $this->passed;
    }

    /**
     * Premier motif de blocage, pour un message d'erreur simple.
     */
    public function firstReason(): ?string
    {
        $reasons = $this->reasons;

        return $reasons === [] ? null : reset($reasons);
    }
}
