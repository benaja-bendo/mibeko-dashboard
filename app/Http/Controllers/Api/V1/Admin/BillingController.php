<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CreditLedgerEntry;
use App\Models\PlanGrant;
use App\Models\PlanGrantMovement;
use App\Models\User;
use App\Services\CreditLedger;
use App\Services\PlanGrantLedger;
use App\Services\PlanGrantReceiptPdfService;
use App\Traits\HttpResponses;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class BillingController extends Controller
{
    use HttpResponses;

    public function summary(Request $request): JsonResponse
    {
        $validated = $request->validate(['month' => ['sometimes', 'date_format:Y-m']]);
        $month = CarbonImmutable::createFromFormat('!Y-m', $validated['month'] ?? now()->format('Y-m'));
        $active = PlanGrant::query()->where('plan', PlanGrant::PLAN_PRO)->active();
        $recorded = PlanGrant::query()->where('created_at', '>=', $month)
            ->where('created_at', '<', $month->addMonth());

        // Brut/remboursements/net (mibeko-dashboard#122) : dérivés à chaque
        // appel depuis plan_grant_movements, jamais mis en cache — un
        // remboursement rejoué ne peut donc jamais fausser deux fois le
        // même total, et une période close n'est jamais réécrite (chaque
        // mouvement compte dans le mois de son `occurred_at`, pas dans celui
        // qu'il corrige).
        $movements = PlanGrantMovement::query()
            ->where('occurred_at', '>=', $month)->where('occurred_at', '<', $month->addMonth());
        $collected = (int) (clone $movements)->where('type', PlanGrantMovement::TYPE_COLLECTED)->sum('amount_fcfa');
        $refunded = (int) (clone $movements)->where('type', PlanGrantMovement::TYPE_REFUND)->sum('amount_fcfa');
        $corrections = (int) (clone $movements)->where('type', PlanGrantMovement::TYPE_CORRECTION)->sum('amount_fcfa');

        return $this->success([
            'month' => $month->format('Y-m'),
            'recorded_amount_fcfa' => (int) (clone $recorded)->sum('amount_fcfa'),
            'unpriced_grants' => (clone $recorded)->whereNull('amount_fcfa')->count(),
            'collected_amount_fcfa' => $collected,
            'refunded_amount_fcfa' => abs($refunded),
            'corrections_amount_fcfa' => $corrections,
            'net_amount_fcfa' => $collected + $refunded + $corrections,
            'discrepancy_count' => $this->discrepancyCount($month),
            'expiring_7_days' => (clone $active)->where('ends_at', '<=', now()->addDays(7))->count(),
            'expiring_30_days' => (clone $active)->where('ends_at', '<=', now()->addDays(30))->count(),
            'untracked_pro_accounts' => $this->untrackedAccounts()->count(),
        ]);
    }

    /**
     * Octrois créés dans le mois dont le montant saisi (`amount_fcfa`) ne
     * correspond pas à ce qui a été réellement encaissé — paiement partiel
     * ou en trop, pas encore corrigé. Calculé en mémoire plutôt qu'en SQL :
     * volume mensuel encore faible (~15-20 octrois), et la comparaison
     * (somme des mouvements `collected` vs `amount_fcfa`) reste plus lisible
     * ainsi qu'en agrégat conditionnel.
     */
    private function discrepancyCount(CarbonImmutable $month): int
    {
        return PlanGrant::query()
            ->where('created_at', '>=', $month)->where('created_at', '<', $month->addMonth())
            ->whereNotNull('amount_fcfa')
            ->with(['movements' => fn ($query) => $query->where('type', PlanGrantMovement::TYPE_COLLECTED)])
            ->get()
            ->filter(fn (PlanGrant $grant) => $grant->movements->sum('amount_fcfa') !== $grant->amount_fcfa)
            ->count();
    }

    public function grants(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(['active', 'ended', 'revoked', 'expiring_7', 'expiring_30'])],
            'user_id' => ['sometimes', 'uuid'],
        ]);
        $query = PlanGrant::query()->with(['user:id,name,email', 'creator:id,name']);
        if (isset($validated['user_id'])) {
            $query->where('user_id', $validated['user_id']);
        }
        $status = $validated['status'] ?? null;
        if ($status === 'ended') {
            $query->where('ends_at', '<=', now())->whereNull('revoked_at');
        } elseif ($status === 'revoked') {
            $query->whereNotNull('revoked_at');
        } elseif ($status) {
            $query->active();
            if ($status !== 'active') {
                $query->where('ends_at', '<=', now()->addDays($status === 'expiring_7' ? 7 : 30));
            }
        }
        $rows = $query->latest()->orderByDesc('id')->paginate(20);
        $rows->through(fn (PlanGrant $grant) => [
            ...$grant->customerPayload(),
            'user' => $grant->user,
            'creator' => $grant->creator,
        ]);

        return $this->paginatedSuccess($rows);
    }

    /** Même justificatif que côté client — l'admin peut le retrouver sans dépendre du titulaire. */
    public function receipt(PlanGrant $grant, PlanGrantReceiptPdfService $receipts): HttpResponse
    {
        return response($receipts->render($grant), HttpResponse::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$receipts->filenameFor($grant).'"',
        ]);
    }

    /** Grand livre FCFA d'un octroi — mibeko-dashboard#122, pendant de `userCredits()`. */
    public function grantMovements(PlanGrant $grant): JsonResponse
    {
        return $this->success([
            'collected_amount_fcfa' => (int) $grant->movements()->where('type', PlanGrantMovement::TYPE_COLLECTED)->sum('amount_fcfa'),
            'net_amount_fcfa' => (int) $grant->movements()->sum('amount_fcfa'),
            'movements' => $grant->movements()->with('author:id,name')->latest('occurred_at')->latest('id')->get(),
        ]);
    }

    /**
     * Remboursement ou correction manuelle d'un octroi — mibeko-dashboard#122.
     * Ne coupe l'accès que si `revoke_access` est explicitement vrai : un
     * mouvement financier et son effet sur les droits restent deux gestes
     * distincts, jamais un effet de bord automatique l'un de l'autre.
     */
    public function storeGrantMovement(Request $request, PlanGrant $grant, PlanGrantLedger $ledger): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in([PlanGrantMovement::TYPE_REFUND, PlanGrantMovement::TYPE_CORRECTION])],
            'amount_fcfa' => ['required', 'integer', 'between:-1000000000,1000000000', 'not_in:0'],
            'reason' => ['required', 'string', 'max:1000'],
            'reference_id' => ['required', 'string', 'max:64'],
            'revoke_access' => ['sometimes', 'boolean'],
        ]);
        if ($validated['type'] === PlanGrantMovement::TYPE_REFUND && $validated['amount_fcfa'] >= 0) {
            throw ValidationException::withMessages(['amount_fcfa' => 'Un remboursement doit retrancher un montant (négatif).']);
        }

        return DB::transaction(function () use ($validated, $request, $grant, $ledger) {
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['plan-grant-movement:'.$validated['reference_id']]);
            $existing = PlanGrantMovement::whereIn('type', [PlanGrantMovement::TYPE_REFUND, PlanGrantMovement::TYPE_CORRECTION])
                ->where('reference_id', $validated['reference_id'])->first();
            if ($existing) {
                if ($existing->plan_grant_id !== $grant->id || $existing->type !== $validated['type']
                    || $existing->amount_fcfa !== (int) $validated['amount_fcfa'] || $existing->reason !== $validated['reason']) {
                    throw ValidationException::withMessages(['reference_id' => 'Cette référence est déjà utilisée pour un autre mouvement.']);
                }

                return $this->success(['id' => $existing->id], 'Mouvement déjà enregistré.');
            }
            $movement = $validated['type'] === PlanGrantMovement::TYPE_REFUND
                ? $ledger->refund($grant, $validated['amount_fcfa'], $validated['reason'], $validated['reference_id'], $request->user())
                : $ledger->correction($grant, $validated['amount_fcfa'], $validated['reason'], $validated['reference_id'], $request->user());

            if ($validated['revoke_access'] ?? false) {
                $grant->revoke();
            }

            return $this->success(['id' => $movement->id], 'Mouvement enregistré.');
        });
    }

    public function untracked(): JsonResponse
    {
        return $this->paginatedSuccess($this->untrackedAccounts()
            ->select(['id', 'name', 'email'])->orderBy('name')->orderBy('id')->paginate(20));
    }

    private function untrackedAccounts(): Builder
    {
        return User::role('user_pro')->whereDoesntHave('planGrants', fn (Builder $query) => $query
            ->where('plan', PlanGrant::PLAN_PRO)->active());
    }

    public function credits(Request $request): JsonResponse
    {
        $validated = $request->validate(['user_id' => ['sometimes', 'uuid', 'exists:users,id']]);
        $query = CreditLedgerEntry::query()->with(['user:id,name,email', 'author:id,name']);
        $user = isset($validated['user_id']) ? User::findOrFail($validated['user_id']) : null;
        if ($user) {
            $query->where('user_id', $user->id);
        }
        $rows = $query->latest()->orderByDesc('id')->paginate(20);

        return $this->paginatedSuccess($rows);
    }

    public function userCredits(Request $request, User $user, CreditLedger $ledger): JsonResponse
    {
        return $this->success([
            'balance' => $ledger->balance($user),
            'entries' => CreditLedgerEntry::where('user_id', $user->id)
                ->with('author:id,name')->latest()->orderByDesc('id')->paginate(20),
        ]);
    }

    public function storeCredits(Request $request, User $user, CreditLedger $ledger): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(['purchase', 'correction'])],
            'amount' => ['required', 'integer', 'between:-1000000,1000000', 'not_in:0'],
            'reason' => ['required', 'string', 'max:255'],
            'reference_id' => ['required', 'string', 'max:36'],
        ]);
        if ($validated['type'] === 'purchase' && $validated['amount'] < 1) {
            throw ValidationException::withMessages(['amount' => 'Un achat doit ajouter des crédits.']);
        }

        return DB::transaction(function () use ($validated, $request, $user, $ledger) {
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['admin-credit:'.$validated['reference_id']]);
            $existing = CreditLedgerEntry::whereIn('type', ['purchase', 'correction'])
                ->where('reference_id', $validated['reference_id'])->first();
            if ($existing) {
                if ($existing->user_id !== $user->id || $existing->type !== $validated['type']
                    || $existing->amount !== (int) $validated['amount'] || $existing->reason !== $validated['reason']) {
                    throw ValidationException::withMessages(['reference_id' => 'Cette référence est déjà utilisée pour un autre mouvement.']);
                }

                return $this->success(['id' => $existing->id], 'Mouvement déjà enregistré.');
            }
            $entry = $validated['type'] === 'purchase'
                ? $ledger->purchase($user, $validated['amount'], $validated['reason'], $validated['reference_id'], $request->user())
                : $ledger->correction($user, $validated['amount'], $validated['reason'], $request->user(), $validated['reference_id']);

            return $this->success(['id' => $entry->id], 'Mouvement enregistré.');
        });
    }
}
