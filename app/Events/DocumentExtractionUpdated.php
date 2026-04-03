<?php

namespace App\Events;

use App\Models\LegalDocument;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DocumentExtractionUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $document;

    /**
     * Create a new event instance.
     */
    public function __construct(LegalDocument $document)
    {
        $this->document = $document;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('curation.documents'),
        ];
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        // Reload relations needed for UI calculations
        $this->document->loadCount(['articles', 'articles as validated_articles_count' => function ($query) {
            $query->where('validation_status', 'validated');
        }]);

        $progression = $this->document->articles_count > 0
            ? round(($this->document->validated_articles_count / $this->document->articles_count) * 100)
            : 0;

        return [
            'id' => $this->document->id,
            'extraction_status' => $this->document->extraction_status,
            'progression' => $progression,
            'articles_count' => $this->document->articles_count,
        ];
    }
}
