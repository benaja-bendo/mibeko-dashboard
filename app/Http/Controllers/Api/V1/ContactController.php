<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * @group Contact
 *
 * Formulaire de contact public du site vitrine.
 */
class ContactController extends Controller
{
    /**
     * Reçoit un message de contact : on l'enregistre (source de vérité) puis on
     * tente de le relayer par e-mail. L'échec d'envoi ne fait pas échouer la
     * requête — le message reste consultable en base.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:180'],
            'profile' => ['nullable', 'string', Rule::in(['citoyen', 'professionnel', 'entreprise', 'autre'])],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
        ]);

        $contact = ContactMessage::create([
            ...$validated,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);

        $this->relayByEmail($contact);

        return $this->success(
            ['id' => $contact->id],
            'Votre message a bien été envoyé. Nous vous répondrons rapidement.',
            201
        );
    }

    /**
     * Relaie le message à l'équipe, en best-effort (échec silencieux loggé).
     */
    private function relayByEmail(ContactMessage $contact): void
    {
        $recipient = config('mail.contact_to') ?: config('mail.from.address');

        if (empty($recipient)) {
            return;
        }

        try {
            Mail::raw(
                "Nouveau message de contact\n\n".
                "Nom : {$contact->name}\n".
                "E-mail : {$contact->email}\n".
                'Profil : '.($contact->profile ?? 'non précisé')."\n\n".
                "Message :\n{$contact->message}\n",
                function ($mail) use ($contact, $recipient) {
                    $mail->to($recipient)
                        ->replyTo($contact->email, $contact->name)
                        ->subject('[Contact Mibeko] Message de '.$contact->name);
                }
            );
        } catch (\Throwable $e) {
            Log::warning('Échec du relais e-mail du message de contact : '.$e->getMessage(), ['id' => $contact->id]);
        }
    }
}
