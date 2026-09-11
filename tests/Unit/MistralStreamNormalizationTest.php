<?php

use App\Ai\NormalizesContentBlockResponses;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Events\Dispatcher;
use Laravel\Ai\Gateway\Mistral\MistralGateway;
use Laravel\Ai\Providers\MistralProvider;
use Laravel\Ai\Streaming\Events\TextDelta;

it('normalizes streamed blocks through the installed Mistral parser while preserving tool calls', function () {
    $frames = [
        ['choices' => [['delta' => ['content' => null, 'tool_calls' => [['index' => 0, 'id' => 'call_1', 'function' => ['name' => 'SearchLegalDatabase', 'arguments' => '{"query":']]]]]]],
        ['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => '"mariage"}']]]]]]],
        ['choices' => [['delta' => ['content' => [['type' => 'text', 'text' => 'Texte '], ['type' => 'text', 'text' => 'cité']]]]]],
        ['choices' => [['delta' => ['content' => ' [1].'], 'finish_reason' => 'tool_calls']], 'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 7]],
    ];
    $body = implode('', array_map(fn ($frame) => 'data: '.json_encode($frame)."\r\n\r\n", $frames))."data: [DONE]\r\n\r\n";
    $response = (new NormalizesContentBlockResponses)(new Response(200, ['Content-Type' => 'text/event-stream'], $body));
    $events = new Dispatcher;
    $gateway = new MistralGateway($events);
    $provider = new MistralProvider(['name' => 'mistral', 'driver' => 'mistral'], $events);
    $stream = (new ReflectionMethod($gateway, 'processTextStream'))->invoke($gateway, 'test', $provider, 'test-model', $response->getBody());
    $text = '';
    foreach ($stream as $event) {
        if ($event instanceof TextDelta) {
            $text .= $event->delta;
        }
    }
    expect($text)->toBe('Texte cité [1].')
        ->and($stream->getReturn()->toolCalls[0]->arguments)->toBe(['query' => 'mariage'])
        ->and($stream->getReturn()->usage->promptTokens)->toBe(12);
});

it('reads only the requested SSE line and preserves finish and error events', function () {
    $first = 'data: '.json_encode(['choices' => [['delta' => ['content' => [['type' => 'text', 'text' => 'Bonjour']]]]]])."\n";
    $tail = "\ndata: {\"error\":{\"message\":\"indisponible\"}}\n\ndata: [DONE]\n\n";
    $source = Utils::streamFor($first.$tail);
    $response = (new NormalizesContentBlockResponses)(new Response(200, ['Content-Type' => 'text/event-stream', 'Content-Length' => (string) strlen($first.$tail)], $source));
    expect($source->tell())->toBe(0);
    $line = Utils::readLine($response->getBody());
    expect($line)->toContain('Bonjour')->not->toContain('"type":"text"')
        ->and($source->tell())->toBe(strlen($first))
        ->and($response->getHeaderLine('Content-Length'))->toBe('')
        ->and($response->getBody()->getContents())->toBe($tail);
});

it('rejects malformed text blocks instead of emitting Array', function () {
    $body = 'data: '.json_encode(['choices' => [['delta' => ['content' => [['type' => 'text', 'text' => ['unexpected']]]]]]])."\n\n";
    $response = (new NormalizesContentBlockResponses)(new Response(200, ['Content-Type' => 'text/event-stream'], $body));
    expect(fn () => $response->getBody()->getContents())->toThrow(UnexpectedValueException::class);
});
