<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use App\Models\NewsletterSubscription;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContactInboxController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['handled' => ['sometimes', 'boolean']]);
        $query = ContactMessage::query()->select(['id', 'name', 'email', 'profile', 'message', 'handled', 'created_at']);
        if (array_key_exists('handled', $validated)) {
            $query->where('handled', $request->boolean('handled'));
        }
        $messages = $query->latest()->orderByDesc('id')->paginate(20);
        $emails = $messages->getCollection()->pluck('email')->map(fn (string $email) => mb_strtolower($email))->unique()->values()->all();
        $accounts = User::query()->select(['id', 'name', 'email'])
            ->whereIn(DB::raw('LOWER(email)'), $emails)->get()
            ->keyBy(fn (User $user) => mb_strtolower($user->email));
        $messages->through(fn (ContactMessage $message) => [
            ...$message->toArray(),
            'account' => $accounts->get(mb_strtolower($message->email)),
        ]);

        return $this->paginatedSuccess($messages);
    }

    public function update(Request $request, ContactMessage $message): JsonResponse
    {
        $validated = $request->validate(['handled' => ['required', 'boolean']]);
        $message->update($validated);
        Cache::forget('admin:overview');

        return $this->success(['id' => $message->id, 'handled' => $message->handled]);
    }

    public function newsletter(): JsonResponse
    {
        return $this->paginatedSuccess(NewsletterSubscription::query()
            ->select(['id', 'email', 'source', 'created_at'])->latest()->orderByDesc('id')->paginate(20));
    }

    public function exportNewsletter(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['E-mail', 'Source', 'Date d’inscription'], ';', '"', '');
            foreach (NewsletterSubscription::query()->orderBy('id')->lazyById(500) as $subscription) {
                $cells = [$subscription->email, $subscription->source ?? '', $subscription->created_at->toIso8601String()];
                // Les champs publics restent du texte à l'ouverture dans un tableur.
                $cells = array_map(fn (string $value) => preg_match('/^[\s]*[=+@-]|^[\t\r\n]/u', $value) ? "'".$value : $value, $cells);
                fputcsv($stream, $cells, ';', '"', '');
            }
            fclose($stream);
        }, 'abonnes-newsletter-'.now()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
