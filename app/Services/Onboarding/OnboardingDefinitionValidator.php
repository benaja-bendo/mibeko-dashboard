<?php

namespace App\Services\Onboarding;

use App\Models\OnboardingJourney;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OnboardingDefinitionValidator
{
    /**
     * @param  list<array<string, mixed>>  $definition
     * @return list<array<string, mixed>>
     */
    public function validate(array $definition): array
    {
        $validator = Validator::make(['definition' => $definition], [
            'definition' => ['required', 'array', 'min:1', 'max:20'],
            'definition.*.key' => ['required', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/'],
            'definition.*.type' => ['required', 'string', Rule::in(OnboardingJourney::STEP_TYPES)],
            'definition.*.scope' => ['required', 'string', Rule::in(OnboardingJourney::SCOPES)],
            'definition.*.binding' => ['nullable', 'string', Rule::in(OnboardingJourney::BINDINGS)],
            'definition.*.config' => ['present', 'array'],
            'definition.*.conditions' => ['present', 'array', 'max:5'],
            'definition.*.conditions.*.step_key' => ['required', 'string', 'max:60'],
            'definition.*.conditions.*.operator' => ['required', 'string', Rule::in(OnboardingJourney::CONDITION_OPERATORS)],
            'definition.*.conditions.*.value' => ['present'],
        ]);

        $validator->after(function ($validator) use ($definition) {
            $keys = collect($definition)->pluck('key');

            if ($keys->duplicates()->isNotEmpty()) {
                $validator->errors()->add('definition', 'Chaque étape doit avoir un identifiant unique.');
            }

            foreach ($definition as $index => $step) {
                if (! is_array($step)) {
                    continue;
                }

                $this->validateConfig($validator, $step, $index);
                $this->validateBinding($validator, $step, $index);
                $this->validateConditions($validator, $definition, $step, $index);
            }

            foreach (OnboardingJourney::PLATFORMS as $platform) {
                $hasStep = collect($definition)->contains(
                    fn (array $step) => in_array($step['scope'] ?? null, ['common', $platform], true)
                );

                if (! $hasStep) {
                    $validator->errors()->add('definition', "Le parcours doit contenir au moins une étape compatible {$platform}.");
                }
            }
        });

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        /** @var list<array<string, mixed>> $validated */
        $validated = $validator->validated()['definition'];

        return $validated;
    }

    /** @param array<string, mixed> $step */
    private function validateConfig(mixed $validator, array $step, int $index): void
    {
        $config = is_array($step['config'] ?? null) ? $step['config'] : [];
        $allowed = ['title', 'body', 'cta', 'title_key', 'body_key', 'cta_key', 'options', 'items', 'source', 'placeholder', 'examples'];
        $unknown = array_diff(array_keys($config), $allowed);

        if ($unknown !== []) {
            $validator->errors()->add("definition.{$index}.config", 'Configuration inconnue : '.implode(', ', $unknown).'.');
        }

        foreach (['title', 'body', 'cta', 'placeholder'] as $field) {
            $value = $config[$field] ?? null;
            if ($value !== null && (! is_string($value) || mb_strlen($value) > ($field === 'body' ? 600 : 160))) {
                $validator->errors()->add("definition.{$index}.config.{$field}", 'Le texte dépasse la limite autorisée.');
            }
            if (is_string($value) && $value !== strip_tags($value)) {
                $validator->errors()->add("definition.{$index}.config.{$field}", 'Le HTML n’est pas autorisé.');
            }
        }

        foreach (['title_key', 'body_key', 'cta_key'] as $field) {
            $value = $config[$field] ?? null;
            if ($value !== null && (! is_string($value) || preg_match('/^onboarding\.[a-z0-9_.-]{1,140}$/', $value) !== 1)) {
                $validator->errors()->add("definition.{$index}.config.{$field}", 'La clé de traduction est invalide.');
            }
        }

        if (isset($config['source']) && $config['source'] !== 'tags:themes-de-vie') {
            $validator->errors()->add("definition.{$index}.config.source", 'Source de données non autorisée.');
        }

        $type = $step['type'] ?? null;
        $typeSpecific = match ($type) {
            OnboardingJourney::TYPE_SINGLE_CHOICE => ['options'],
            OnboardingJourney::TYPE_MULTI_CHOICE => ['options', 'source'],
            OnboardingJourney::TYPE_OPTIONAL_FIELD => ['placeholder'],
            OnboardingJourney::TYPE_GUIDED_ACTION => ['options'],
            OnboardingJourney::TYPE_CHECKLIST => ['items'],
            default => [],
        };
        $misplaced = array_diff(array_keys($config), [
            'title', 'body', 'cta', 'title_key', 'body_key', 'cta_key', 'examples', ...$typeSpecific,
        ]);
        if ($misplaced !== []) {
            $validator->errors()->add("definition.{$index}.config", 'Configuration incompatible avec ce composant : '.implode(', ', $misplaced).'.');
        }

        if (($config['title'] ?? '') === '' && ($config['title_key'] ?? '') === '') {
            $validator->errors()->add("definition.{$index}.config.title", 'Un titre ou une clé de titre est obligatoire.');
        }

        if ($type === OnboardingJourney::TYPE_SINGLE_CHOICE) {
            $this->validateOptions($validator, $config['options'] ?? null, $index, 'options', true);
        } elseif (in_array($type, [OnboardingJourney::TYPE_MULTI_CHOICE, OnboardingJourney::TYPE_CHECKLIST], true)
            && ! isset($config['source'])) {
            $field = $type === OnboardingJourney::TYPE_CHECKLIST ? 'items' : 'options';
            $this->validateOptions($validator, $config[$field] ?? null, $index, $field, false);
        }

        if (isset($config['examples'])) {
            if (! is_array($config['examples']) || count($config['examples']) > 5) {
                $validator->errors()->add("definition.{$index}.config.examples", 'Cinq exemples au maximum sont autorisés.');
            } else {
                foreach ($config['examples'] as $example) {
                    if (! is_string($example) || mb_strlen($example) > 160 || $example !== strip_tags($example)) {
                        $validator->errors()->add("definition.{$index}.config.examples", 'Chaque exemple doit être un texte brut de 160 caractères maximum.');
                    }
                }
            }
        }
    }

    private function validateOptions(mixed $validator, mixed $options, int $index, string $field, bool $required): void
    {
        if (! is_array($options) || ($required && $options === []) || count($options) > 12) {
            $validator->errors()->add("definition.{$index}.config.{$field}", 'La liste de choix est invalide.');

            return;
        }

        $codes = [];
        foreach ($options as $option) {
            if (! is_array($option)) {
                $validator->errors()->add("definition.{$index}.config.{$field}", 'Chaque choix doit être un objet.');

                continue;
            }

            $unknown = array_diff(array_keys($option), ['code', 'label', 'label_key']);
            $code = $option['code'] ?? null;
            $label = $option['label'] ?? $option['label_key'] ?? null;
            if ($unknown !== [] || ! is_string($code) || preg_match('/^[a-z][a-z0-9_-]{0,59}$/', $code) !== 1
                || ! is_string($label) || $label === '' || mb_strlen($label) > 120 || $label !== strip_tags($label)) {
                $validator->errors()->add("definition.{$index}.config.{$field}", 'Chaque choix exige un code stable et un libellé texte valide.');
            }
            $codes[] = $code;
        }

        if (count(array_unique($codes, SORT_REGULAR)) !== count($codes)) {
            $validator->errors()->add("definition.{$index}.config.{$field}", 'Les codes de choix doivent être uniques.');
        }
    }

    /** @param array<string, mixed> $step */
    private function validateBinding(mixed $validator, array $step, int $index): void
    {
        $binding = $step['binding'] ?? null;
        $type = $step['type'] ?? null;
        $expected = match ($binding) {
            OnboardingJourney::BINDING_USAGE_CONTEXT => OnboardingJourney::TYPE_SINGLE_CHOICE,
            OnboardingJourney::BINDING_INTERESTS => OnboardingJourney::TYPE_MULTI_CHOICE,
            OnboardingJourney::BINDING_JOB_TITLE, OnboardingJourney::BINDING_COMPANY, OnboardingJourney::BINDING_PHONE => OnboardingJourney::TYPE_OPTIONAL_FIELD,
            default => null,
        };

        if ($expected !== null && $type !== $expected) {
            $validator->errors()->add("definition.{$index}.binding", "Ce champ profil exige une étape de type {$expected}.");
        }
    }

    /**
     * @param  list<array<string, mixed>>  $definition
     * @param  array<string, mixed>  $step
     */
    private function validateConditions(mixed $validator, array $definition, array $step, int $index): void
    {
        foreach ((array) ($step['conditions'] ?? []) as $conditionIndex => $condition) {
            if (! is_array($condition)) {
                continue;
            }

            $operator = $condition['operator'] ?? null;
            $value = $condition['value'] ?? null;
            $isScalar = is_string($value) || is_bool($value) || is_int($value) || $value === null;
            $isScalarList = is_array($value) && array_is_list($value) && $value !== [] && count($value) <= 12
                && collect($value)->every(fn (mixed $item) => is_string($item) || is_bool($item) || is_int($item));
            if (($operator === 'in' && ! $isScalarList) || ($operator !== 'in' && ! $isScalar)) {
                $validator->errors()->add(
                    "definition.{$index}.conditions.{$conditionIndex}.value",
                    'La valeur de condition doit être simple ; l’opérateur in exige une liste bornée.',
                );
            }

            $sourceIndex = collect($definition)->search(fn (array $candidate) => ($candidate['key'] ?? null) === ($condition['step_key'] ?? null));
            if (! is_int($sourceIndex) || $sourceIndex >= $index) {
                $validator->errors()->add(
                    "definition.{$index}.conditions.{$conditionIndex}.step_key",
                    'Une condition doit référencer une étape antérieure, ce qui interdit les cycles.',
                );

                continue;
            }

            $sourceScope = Arr::get($definition, "{$sourceIndex}.scope");
            $targetScope = $step['scope'] ?? null;
            $sourceType = Arr::get($definition, "{$sourceIndex}.type");
            if ($sourceType !== OnboardingJourney::TYPE_SINGLE_CHOICE) {
                $validator->errors()->add(
                    "definition.{$index}.conditions.{$conditionIndex}.step_key",
                    'Une condition éditoriale doit référencer une question à choix unique.',
                );
            } else {
                $codes = collect(Arr::get($definition, "{$sourceIndex}.config.options", []))->pluck('code');
                $expected = $operator === 'in' ? (array) $value : [$value];
                if ($operator !== 'not_equals' && collect($expected)->intersect($codes)->isEmpty()) {
                    $validator->errors()->add(
                        "definition.{$index}.conditions.{$conditionIndex}.value",
                        'La condition doit viser au moins un choix disponible.',
                    );
                }
            }

            if ($targetScope === 'common' && $sourceScope !== 'common') {
                $validator->errors()->add("definition.{$index}.conditions.{$conditionIndex}.step_key", 'Une étape commune ne peut pas dépendre d’une étape propre à une plateforme.');
            }
            if (in_array($targetScope, OnboardingJourney::PLATFORMS, true)
                && ! in_array($sourceScope, ['common', $targetScope], true)) {
                $validator->errors()->add("definition.{$index}.conditions.{$conditionIndex}.step_key", 'La condition référence une étape inaccessible sur cette plateforme.');
            }
        }
    }
}
