<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    /**
     * Affiche la liste des utilisateurs avec recherche et filtres.
     */
    public function index(Request $request)
    {
        // Seul l'admin a accès à cette page (on peut aussi utiliser un middleware)
        if (! $request->user() || ! $request->user()->hasRole('admin')) {
            return redirect()->route('dashboard');
        }

        $query = User::query()->with('roles');

        // Recherche par nom ou email
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Filtrage par statut
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $users = $query->latest()->paginate(15)->through(function ($user) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'created_at' => $user->created_at->format('Y-m-d H:i:s'),
                'status' => $user->status,
                'last_seen_at' => $user->last_seen_at ? $user->last_seen_at->format('Y-m-d H:i:s') : null,
                'is_online' => $user->last_seen_at && $user->last_seen_at->diffInMinutes(now()) < 5,
                'roles' => $user->roles->pluck('name'),
            ];
        });

        $roles = Role::pluck('name');

        return Inertia::render('Users/Index', [
            'users' => $users,
            'availableRoles' => $roles,
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    /**
     * Met à jour le statut et/ou le rôle d'un utilisateur.
     */
    public function update(Request $request, User $user)
    {
        if (! $request->user() || ! $request->user()->hasRole('admin')) {
            abort(403, 'Non autorisé.');
        }

        $request->validate([
            'status' => 'sometimes|string|in:active,suspended,pending',
            'role' => 'sometimes|string|exists:roles,name',
        ]);

        if ($request->has('status')) {
            $user->update(['status' => $request->input('status')]);
        }

        if ($request->has('role')) {
            // Remplacer tous les rôles par le nouveau rôle (un seul rôle par utilisateur dans ce contexte)
            $user->syncRoles([$request->input('role')]);
        }

        return back()->with('success', 'Utilisateur mis à jour avec succès.');
    }
}
