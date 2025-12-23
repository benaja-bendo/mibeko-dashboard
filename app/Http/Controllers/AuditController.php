<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use OwenIt\Auditing\Models\Audit;

class AuditController extends Controller
{
    /**
     * Display a listing of the audits.
     */
    public function index(Request $request): Response
    {
        $audits = Audit::with(['user'])
            ->latest()
            ->paginate(20)
            ->through(fn ($audit) => [
                'id' => $audit->id,
                'user' => $audit->user ? [
                    'name' => $audit->user->name,
                    'email' => $audit->user->email,
                ] : null,
                'event' => $audit->event,
                'auditable_type' => str_replace('App\\Models\\', '', $audit->auditable_type),
                'auditable_id' => $audit->auditable_id,
                'old_values' => $audit->old_values,
                'new_values' => $audit->new_values,
                'url' => $audit->url,
                'ip_address' => $audit->ip_address,
                'user_agent' => $audit->user_agent,
                'created_at' => $audit->created_at->format('Y-m-d H:i:s'),
            ]);

        return Inertia::render('Auditing/Index', [
            'audits' => $audits,
        ]);
    }
}
