<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ManualPaymentOrder;
use App\Models\PlanGrant;
use App\Traits\HttpResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ManualPaymentOrderController extends Controller
{
    use HttpResponses;

    public function index(Request $request): JsonResponse
    {
        $orders = $request->user()->manualPaymentOrders()->latest()->orderByDesc('id')->paginate(20);
        $orders->through(fn (ManualPaymentOrder $order) => $order->customerPayload());

        return $this->paginatedSuccess($orders);
    }

    public function declare(Request $request, ManualPaymentOrder $paymentOrder): JsonResponse
    {
        abort_unless($paymentOrder->user_id === $request->user()->id, 404);
        $validated = $request->validate(['payment_reference' => ['required', 'string', 'min:3', 'max:255']]);
        $reference = Str::upper(trim($validated['payment_reference']));

        return DB::transaction(function () use ($paymentOrder, $reference) {
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['payment-reference:'.$reference]);
            $order = ManualPaymentOrder::query()->lockForUpdate()->findOrFail($paymentOrder->id);

            if ($order->payment_reference === $reference
                && in_array($order->status, [ManualPaymentOrder::STATUS_PAYMENT_DECLARED, ManualPaymentOrder::STATUS_VERIFYING, ManualPaymentOrder::STATUS_ACTIVATED], true)) {
                return $this->success($order->customerPayload(), 'Paiement déjà déclaré.');
            }
            if ($order->status !== ManualPaymentOrder::STATUS_AWAITING_PAYMENT) {
                throw ValidationException::withMessages(['payment_reference' => 'Cette commande ne peut plus recevoir de déclaration.']);
            }
            $usedByOrder = ManualPaymentOrder::query()->where('payment_reference', $reference)->whereKeyNot($order->id)->exists();
            $usedByGrant = PlanGrant::query()->where('channel', $order->channel)->where('reference', $reference)->exists();
            if ($usedByOrder || $usedByGrant) {
                throw ValidationException::withMessages(['payment_reference' => 'Cette référence de paiement est déjà utilisée.']);
            }

            $order->update([
                'payment_reference' => $reference,
                'payment_declared_at' => now(),
                'status' => ManualPaymentOrder::STATUS_PAYMENT_DECLARED,
            ]);

            return $this->success($order->fresh()->customerPayload(), 'Paiement déclaré. Il reste soumis à une vérification humaine.');
        });
    }
}
