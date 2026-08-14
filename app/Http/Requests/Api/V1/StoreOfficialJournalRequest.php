<?php

namespace App\Http\Requests\Api\V1;

use App\Models\OfficialJournal;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOfficialJournalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAnyRole(['editor', 'admin']) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'number' => [
                'required',
                'string',
                'max:50',
                Rule::unique('official_journals', 'number')->where(
                    fn (Builder $query): Builder => $query
                        ->whereDate('publication_date', (string) $this->input('publication_date'))
                        ->whereNull('deleted_at')
                ),
            ],
            'publication_date' => ['required', 'date'],
            'file_path' => [
                'required',
                'string',
                'max:512',
                'regex:#^s3://[^/]+/.+\.pdf$#i',
            ],
            'transcription_status' => [
                'sometimes',
                Rule::in([
                    OfficialJournal::STATUS_PENDING,
                    OfficialJournal::STATUS_IN_PROGRESS,
                    OfficialJournal::STATUS_COMPLETED,
                    OfficialJournal::STATUS_FAILED,
                ]),
            ],
            'is_published' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'number.unique' => 'Un Journal officiel vivant porte déjà ce numéro à cette date.',
            'file_path.regex' => 'Le chemin doit désigner un PDF existant dans le stockage S3.',
        ];
    }
}
