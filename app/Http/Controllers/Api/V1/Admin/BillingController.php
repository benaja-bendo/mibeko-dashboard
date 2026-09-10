<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CreditLedgerEntry;
use App\Models\PlanGrant;
use App\Models\User;
use App\Services\CreditLedger;
use App\Traits\HttpResponses;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

        return $this->success([
            'month' => $month->format('Y-m'),
            'recorded_amount_fcfa' => (int) (clone $recorded)->sum('amount_fcfa'),
            'unpriced_grants' => (clone $recorded)->whereNull('amount_fcfa')->count(),
            'expiring_7_days' => (clone $active)->where('ends_at', '<=', now()->addDays(7))->count(),
            'expiring_30_days' => (clone $active)->where('ends_at', '<=', now()->addDays(30))->count(),
            'untracked_pro_accounts' => $this->untrackedAccounts()->count(),
        ]);
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
