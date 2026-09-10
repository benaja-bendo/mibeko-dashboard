<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ManualPaymentOrder;
use App\Models\PlanGrant;
use App\Models\User;
use App\Notifications\PlanGrantActivatedNotification;
use App\Services\PlanGrantLedger;
use App\Traits\HttpResponses;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ManualPaymentOrderController extends Controller
{
    use HttpResponses;

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(ManualPaymentOrder::STATUSES)],
            'user_id' => ['sometimes', 'uuid', 'exists:users,id'],
        ]);
        $query = ManualPaymentOrder::query()->with([
            'user:id,name,email', 'creator:id,name', 'verificationStarter:id,name', 'resolver:id,name',
        ]);
        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (isset($validated['user_id'])) {
            $query->where('user_id', $validated['user_id']);
        }
        $orders = $query->latest()->orderByDesc('id')->paginate(20);
        $orders->through(fn (ManualPaymentOrder $order) => $order->adminPayload());

        return $this->paginatedSuccess($orders);
    }

    public function store(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'amount_fcfa' => ['required', 'integer', 'between:1,2147483647'],
            'duration_months' => ['required', 'integer', 'between:1,24'],
            'channel' => ['required', Rule::in(ManualPaymentOrder::CHANNELS)],
            'payment_instructions' => ['required', 'string', 'max:2000'],
            'internal_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return DB::transaction(function () use ($request, $user, $validated) {
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['payment-order:'.$validated['idempotency_key']]);
            $existing = ManualPaymentOrder::query()->where('idempotency_key', $validated['idempotency_key'])->first();
            if ($existing) {
                $same = $existing->user_id === $user->id
                    && $existing->amount_fcfa === (int) $validated['amount_fcfa']
                    && $existing->duration_months === (int) $validated['duration_months']
                    && $existing->channel === $validated['channel']
                    && $existing->payment_instructions === trim($validated['payment_instructions'])
                    && $existing->internal_notes === ($validated['internal_notes'] ?? null);
                if (! $same) {
                    throw ValidationException::withMessages(['idempotency_key' => 'Cette clé est déjà utilisée pour une autre commande.']);
                }

                return $this->success($existing->load(['user', 'creator'])->adminPayload(), 'Commande déjà enregistrée.');
            }

            $order = $user->manualPaymentOrders()->create([
                ...$validated,
                'payment_instructions' => trim($validated['payment_instructions']),
                'reference' => 'MBK-'.now()->format('Ym').'-'.Str::upper((string) Str::ulid()),
                'offer_code' => PlanGrant::PLAN_PRO,
                'status' => ManualPaymentOrder::STATUS_AWAITING_PAYMENT,
                'created_by' => $request->user()->id,
            ]);

            return $this->success($order->load(['user', 'creator'])->adminPayload(), 'Commande transmise au client.');
        });
    }

    public function startVerification(Request $request, ManualPaymentOrder $paymentOrder): JsonResponse
    {
        return DB::transaction(function () use ($request, $paymentOrder) {
            $order = ManualPaymentOrder::query()->lockForUpdate()->findOrFail($paymentOrder->id);
            if ($order->status === ManualPaymentOrder::STATUS_VERIFYING) {
                return $this->success($order->adminPayload(), 'Vérification déjà en cours.');
            }
            if ($order->status !== ManualPaymentOrder::STATUS_PAYMENT_DECLARED) {
                throw ValidationException::withMessages(['status' => 'Seul un paiement déclaré peut passer en vérification.']);
            }
            $order->update([
                'status' => ManualPaymentOrder::STATUS_VERIFYING,
                'verification_started_at' => now(),
                'verification_started_by' => $request->user()->id,
            ]);

            return $this->success($order->fresh()->adminPayload(), 'Vérification commencée.');
        });
    }

    public function activate(Request $request, ManualPaymentOrder $paymentOrder, PlanGrantLedger $ledger): JsonResponse
    {
        // Montant réellement encaissé, distinct du montant saisi à la
        // commande (mibeko-dashboard#122) : pré-rempli côté front avec
        // amount_fcfa, mais modifiable pour un paiement partiel ou en trop —
        // le geste d'activation reste à un clic dans le cas courant, sans
        // empêcher de corriger avant de confirmer.
        $validated = $request->validate([
            'collected_amount_fcfa' => ['sometimes', 'integer', 'between:1,2147483647'],
            'collected_at' => ['sometimes', 'date'],
        ]);

        return DB::transaction(function () use ($request, $paymentOrder, $ledger, $validated) {
            $order = ManualPaymentOrder::query()->lockForUpdate()->findOrFail($paymentOrder->id);
            if ($order->status === ManualPaymentOrder::STATUS_ACTIVATED && $order->plan_grant_id) {
                return $this->success($order->adminPayload(), 'Commande déjà activée.');
            }
            if ($order->status !== ManualPaymentOrder::STATUS_VERIFYING || ! $order->payment_reference) {
                throw ValidationException::withMessages(['status' => 'La commande doit être en vérification avant activation.']);
            }
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['plan-user:'.$order->user_id]);
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['plan-grant:'.$order->channel.':'.$order->payment_reference]);
            if (PlanGrant::query()->where('channel', $order->channel)->where('reference', $order->payment_reference)->exists()) {
                throw ValidationException::withMessages(['payment_reference' => 'Cette référence a déjà activé un autre abonnement.']);
            }

            $startsAt = CarbonImmutable::now();
            $grant = PlanGrant::query()->create([
                'user_id' => $order->user_id,
                'plan' => PlanGrant::PLAN_PRO,
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->addMonthsNoOverflow($order->duration_months),
                'amount_fcfa' => $order->amount_fcfa,
                'channel' => $order->channel,
                'reference' => $order->payment_reference,
                'notes' => 'Commande '.$order->reference,
                'created_by' => $request->user()->id,
            ]);
            $order->update([
                'status' => ManualPaymentOrder::STATUS_ACTIVATED,
                'activated_at' => now(),
                'resolved_by' => $request->user()->id,
                'plan_grant_id' => $grant->id,
            ]);
            $ledger->collect(
                $grant,
                $validated['collected_amount_fcfa'] ?? $order->amount_fcfa,
                $order,
                isset($validated['collected_at']) ? CarbonImmutable::parse($validated['collected_at']) : null,
                $request->user(),
            );

            // Confirmation + accès au justificatif (mibeko-dashboard#121) :
            // toujours envoyée, jamais gatée par les préférences — c'est la
            // preuve d'une transaction déjà réalisée, pas du contenu
            // optionnel (cf. docblock de la notification).
            $order->user?->notify(new PlanGrantActivatedNotification($grant));

            return $this->success($order->fresh()->adminPayload(), 'Paiement vérifié et abonnement activé.');
        });
    }

    public function reject(Request $request, ManualPaymentOrder $paymentOrder): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return DB::transaction(function () use ($request, $paymentOrder, $validated) {
            $order = ManualPaymentOrder::query()->lockForUpdate()->findOrFail($paymentOrder->id);
            if ($order->status === ManualPaymentOrder::STATUS_REJECTED && $order->rejection_reason === trim($validated['reason'])) {
                return $this->success($order->adminPayload(), 'Commande déjà refusée.');
            }
            if (in_array($order->status, [ManualPaymentOrder::STATUS_ACTIVATED, ManualPaymentOrder::STATUS_REJECTED], true)) {
                throw ValidationException::withMessages(['status' => 'Cette commande est déjà terminée.']);
            }
            $order->update([
                'status' => ManualPaymentOrder::STATUS_REJECTED,
                'rejected_at' => now(),
                'rejection_reason' => trim($validated['reason']),
                'resolved_by' => $request->user()->id,
            ]);

            return $this->success($order->fresh()->adminPayload(), 'Commande refusée avec un motif transmis au client.');
        });
    }
}
