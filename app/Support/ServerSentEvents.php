<?php

namespace App\Support;

/**
 * Petit utilitaire d'écriture de trames Server-Sent Events.
 *
 * Centralise le boilerplate (event nommé optionnel, encodage JSON, vidage des
 * tampons) répété à chaque émission dans les flux SSE de l'assistant.
 */
class ServerSentEvents
{
    /**
     * Émet une trame. `$event` nomme l'événement (sources, status, error, meta…) ;
     * omis, c'est l'événement « message » par défaut (fragments de texte).
     * Une charge non sérialisable en JSON est ignorée pour ne pas casser le flux.
     */
    public static function send(mixed $data, ?string $event = null): void
    {
        $payload = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE);

        if ($payload === false) {
            return;
        }

        if ($event !== null) {
            echo "event: {$event}\n";
        }

        echo 'data: '.$payload."\n\n";

        static::flush();
    }

    /**
     * Émet le marqueur de fin de flux attendu par le client.
     */
    public static function done(): void
    {
        echo "data: [DONE]\n\n";

        static::flush();
    }

    /**
     * Pousse immédiatement la trame au client (au travers des tampons éventuels).
     */
    protected static function flush(): void
    {
        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }
}
