<?php

namespace App\Http\Resources\V1;

use App\Models\OfficialJournal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class OfficialJournalResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $disk = config('filesystems.default', 'local');
        $fileSize = $this->file_path && Storage::disk($disk)->exists($this->file_path)
            ? Storage::disk($disk)->size($this->file_path)
            : null;

        /** @var OfficialJournal $this */
        return [
            'id' => $this->id,
            'title' => $this->title,
            'number' => $this->number,
            'publication_date' => $this->publication_date?->toIso8601String(),
            'transcription_status' => $this->transcription_status,
            'is_published' => $this->is_published,
            'pdf_url' => $this->pdf_url ?? null, // Appended by the controller if requested
            'file_size_bytes' => $fileSize,
            'legal_documents_count' => $this->whenCounted('legalDocuments'),
            // Vue manager uniquement : permet d'afficher « publiés / total »
            // (le compteur public reste scoppé aux documents publiés).
            'published_legal_documents_count' => $this->when(
                isset($this->published_legal_documents_count),
                fn () => (int) $this->published_legal_documents_count
            ),
            'legal_documents' => LegalDocumentResource::collection($this->whenLoaded('legalDocuments')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
