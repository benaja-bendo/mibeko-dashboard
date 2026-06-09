<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\HttpResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * @group Billing
 *
 * Abonnement, moyen de paiement, factures et informations légales de facturation,
 * adossés à Laravel Cashier (Stripe). Les endpoints dégradent proprement quand
 * Stripe n'est pas configuré (`stripe_enabled = false`) : lecture possible,
 * actions de paiement désactivées.
 */
class BillingController extends Controller
{
    use HttpResponses;

    /**
     * Vue d'ensemble : abonnement, paiement, factures, infos légales et plans.
     */
    public function overview(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->success([
            'subscription' => $this->subscriptionPayload($user),
            'payment_method' => $this->paymentMethodPayload($user),
            'invoices' => $this->invoicesPayload($user),
            'billing_info' => $this->billingInfoPayload($user),
            'plans' => config('billing.plans'),
            'stripe_enabled' => $this->stripeEnabled(),
        ]);
    }

    /**
     * Met à jour les informations légales de facturation (raison sociale, RCCM, NIF, adresse).
     */
    public function updateInfo(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company' => ['sometimes', 'nullable', 'string', 'max:255'],
            'rccm' => ['sometimes', 'nullable', 'string', 'max:100'],
            'tax_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $settings = $request->user()->settingsOrCreate();
        $settings->update(['billing_info' => array_merge($this->billingInfoPayload($request->user()), $validated)]);

        return $this->overview($request);
    }

    /**
     * Démarre un abonnement via Stripe Checkout et renvoie l'URL de redirection.
     */
    public function checkout(Request $request): JsonResponse
    {
        if (! $this->stripeEnabled()) {
            return $this->error(null, 'Le paiement en ligne n\'est pas configuré.', HttpResponse::HTTP_SERVICE_UNAVAILABLE);
        }

        $validated = $request->validate([
            'plan' => ['required', 'string'],
        ]);

        $plan = collect(config('billing.plans'))->firstWhere('id', $validated['plan']);

        if (! $plan || empty($plan['stripe_price'])) {
            return $this->error(null, 'Formule inconnue ou non disponible.', HttpResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $checkout = $request->user()
            ->newSubscription('default', $plan['stripe_price'])
            ->checkout([
                'success_url' => config('app.frontend_url', config('app.url')).'/settings/billing?checkout=success',
                'cancel_url' => config('app.frontend_url', config('app.url')).'/settings/billing?checkout=cancel',
            ]);

        return $this->success(['url' => $checkout->url]);
    }

    /**
     * Génère une URL vers le portail de facturation Stripe (CB, factures, annulation).
     */
    public function portal(Request $request): JsonResponse
    {
        if (! $this->stripeEnabled() || ! $request->user()->hasStripeId()) {
            return $this->error(null, 'Portail de facturation indisponible.', HttpResponse::HTTP_SERVICE_UNAVAILABLE);
        }

        $url = $request->user()->billingPortalUrl(
            config('app.frontend_url', config('app.url')).'/settings/billing',
        );

        return $this->success(['url' => $url]);
    }

    /**
     * Télécharge le PDF d'une facture Stripe appartenant à l'utilisateur.
     */
    public function downloadInvoice(Request $request, string $invoiceId): HttpResponse
    {
        abort_unless($this->stripeEnabled() && $request->user()->hasStripeId(), HttpResponse::HTTP_NOT_FOUND);

        return $request->user()->downloadInvoice($invoiceId, [
            'vendor' => config('app.name'),
            'product' => 'Abonnement Mibeko',
        ]);
    }

    // ── Helpers de sérialisation ──────────────────────────────────────────────

    /** Stripe est-il exploitable (clé secrète présente) ? */
    private function stripeEnabled(): bool
    {
        return filled(config('cashier.secret'));
    }

    /**
     * État de l'abonnement, lu depuis la table locale (aucun appel Stripe requis).
     *
     * @return array<string, mixed>
     */
    private function subscriptionPayload(User $user): array
    {
        $subscription = $user->subscription('default');

        if (! $subscription) {
            return [
                'status' => 'none',
                'plan_name' => null,
                'renews_at' => null,
                'trial_ends_at' => null,
                'on_grace_period' => false,
            ];
        }

        return [
            'status' => $this->normalizeStatus($subscription->stripe_status),
            'plan_name' => $this->planNameForPrice($subscription->stripe_price),
            'renews_at' => $subscription->ends_at?->toIso8601String(),
            'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
            'on_grace_period' => $subscription->onGracePeriod(),
        ];
    }

    /**
     * Moyen de paiement par défaut, lu depuis les colonnes locales Cashier.
     *
     * @return array<string, string>|null
     */
    private function paymentMethodPayload(User $user): ?array
    {
        if (! $user->pm_last_four) {
            return null;
        }

        return [
            'brand' => $user->pm_type ?? 'card',
            'last_four' => $user->pm_last_four,
        ];
    }

    /**
     * Liste des factures Stripe (appel API protégé et tolérant aux erreurs).
     *
     * @return array<int, array<string, mixed>>
     */
    private function invoicesPayload(User $user): array
    {
        if (! $this->stripeEnabled() || ! $user->hasStripeId()) {
            return [];
        }

        try {
            return $user->invoices()->map(fn ($invoice) => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'total' => $invoice->total(),
                'status' => $invoice->status,
                'date' => $invoice->date()->toIso8601String(),
            ])->all();
        } catch (\Throwable) {
            // Une indisponibilité Stripe ne doit pas casser l'écran de facturation.
            return [];
        }
    }

    /**
     * Informations légales de facturation (stockées localement).
     *
     * @return array<string, string|null>
     */
    private function billingInfoPayload(User $user): array
    {
        $info = $user->settingsOrCreate()->billing_info ?? [];

        return [
            'company' => $info['company'] ?? null,
            'rccm' => $info['rccm'] ?? null,
            'tax_id' => $info['tax_id'] ?? null,
            'address' => $info['address'] ?? null,
        ];
    }

    /** Ramène un statut Stripe arbitraire vers l'ensemble exposé au front. */
    private function normalizeStatus(string $stripeStatus): string
    {
        return in_array($stripeStatus, ['active', 'trialing', 'past_due', 'canceled'], true)
            ? $stripeStatus
            : 'active';
    }

    /** Retrouve le nom lisible d'un plan à partir de son Price ID Stripe. */
    private function planNameForPrice(?string $stripePrice): ?string
    {
        if (! $stripePrice) {
            return null;
        }

        return collect(config('billing.plans'))->firstWhere('stripe_price', $stripePrice)['name'] ?? null;
    }
}
