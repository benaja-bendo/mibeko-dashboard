<?php

namespace App\Services;

use App\Models\MobileProfile;
use App\Models\Tag;
use App\Models\User;

/**
 * Point d'écriture unique du profil étendu (`mobile_profiles`/tags) —
 * mibeko-dashboard#136. Extrait de `ProfileController::update()` (#135)
 * pour être réutilisé à l'identique par le moteur d'onboarding
 * (`OnboardingStepWriter`) quand une étape est liée à un champ de profil :
 * un seul endroit sait upserter `mobile_profiles`/synchroniser les tags,
 * jamais deux implémentations qui pourraient diverger.
 */
class ProfileAttributeWriter
{
    /**
     * Upsert atomique sur `mobile_profiles` pour un sous-ensemble de champs,
     * avec dérivation mutuelle profession ↔ usage_context (#135).
     *
     * @param  array<string, mixed>  $fields  sous-ensemble de phone|profession|usage_context|job_title|company
     */
    public function applyProfileFields(User $user, array $fields): void
    {
        if (array_key_exists('profession', $fields) && ! array_key_exists('usage_context', $fields)) {
            $fields['usage_context'] = MobileProfile::deriveUsageContext($fields['profession']);
        } elseif (array_key_exists('usage_context', $fields) && ! array_key_exists('profession', $fields)) {
            $fields['profession'] = MobileProfile::deriveProfession($fields['usage_context']);
        }

        if ($fields === []) {
            return;
        }

        $now = now();

        MobileProfile::query()->upsert(
            [[...$fields, 'user_id' => $user->id, 'created_at' => $now, 'updated_at' => $now]],
            ['user_id'],
            [...array_keys($fields), 'updated_at']
        );
    }

    /**
     * Remplace intégralement les tags "intérêts" de l'utilisateur (taxonomie
     * "Thèmes de vie" réutilisée telle quelle).
     *
     * @param  list<string>  $slugs
     */
    public function applyInterests(User $user, array $slugs): void
    {
        $tagIds = Tag::query()->whereIn('slug', $slugs)->pluck('id');
        $user->tags()->sync($tagIds);
    }
}
