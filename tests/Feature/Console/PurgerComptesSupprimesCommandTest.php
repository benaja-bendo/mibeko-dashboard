<?php

use App\Console\Commands\PurgerComptesSupprimesCommand;
use App\Models\AgentConversation;
use App\Models\AgentConversationMessage;
use App\Models\AiUsageLog;
use App\Models\DocumentRelecturePreuve;
use App\Models\Dossier;
use App\Models\DossierPiece;
use App\Models\ManualPaymentOrder;
use App\Models\MobileProfile;
use App\Models\Notification;
use App\Models\PlanGrant;
use App\Models\SearchLog;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OwenIt\Auditing\Contracts\Auditable;
use Spatie\Permission\Models\Role;

/** Supprime le compte comme `DELETE /v1/profile` (soft delete audité), puis le vieillit de N jours. */
function supprimerDepuis(User $compte, int $jours): User
{
    $compte->delete();
    DB::table('users')->where('id', $compte->id)->update(['deleted_at' => now()->subDays($jours)]);

    return $compte;
}

/**
 * Une ligne dans chaque table qu'un usager remplit : de quoi vérifier que
 * rien ne survit au compte, sauf ce que la décision du 24/09/2026 conserve.
 *
 * @return array{octroi: PlanGrant, commande: ManualPaymentOrder, recherche: SearchLog, usage: AiUsageLog, invitation_envoyee: string}
 */
function garnirCompte(User $compte): array
{
    $compte->assignRole(Role::findOrCreate('mobile_user'));
    $compte->createToken('mobile');
    $compte->settingsOrCreate();
    $compte->tags()->attach(Tag::create(['name' => 'Travail', 'slug' => 'travail-'.Str::lower(Str::random(6))]));
    MobileProfile::create(['user_id' => $compte->id, 'phone' => '+242060000000']);

    $conversation = AgentConversation::factory()->create(['user_id' => $compte->id]);
    AgentConversationMessage::factory()->count(2)->create(['conversation_id' => $conversation->id, 'user_id' => $compte->id]);
    DossierPiece::factory()->create(['dossier_id' => Dossier::factory()->create(['user_id' => $compte->id])->id]);
    Notification::create(['user_id' => $compte->id, 'title' => 'Veille', 'message' => 'Nouveau texte publié', 'type' => 'legal_watch']);

    DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => $compte->id, 'payload' => '', 'last_activity' => now()->getTimestamp()]);
    DB::table('password_reset_tokens')->insert(['email' => $compte->email, 'token' => 'jeton', 'created_at' => now()]);
    // Invitation reçue (porte son adresse) et invitation envoyée (porte son identifiant).
    DB::table('user_invitations')->insert(['id' => (string) Str::uuid(), 'email' => Str::upper($compte->email), 'token' => Str::random(40), 'roles' => '[]', 'expires_at' => now()->addWeek()]);
    $invitationEnvoyee = (string) Str::uuid();
    DB::table('user_invitations')->insert(['id' => $invitationEnvoyee, 'email' => 'recrue@example.test', 'token' => Str::random(40), 'roles' => '[]', 'expires_at' => now()->addWeek(), 'invited_by' => $compte->id]);

    return [
        'octroi' => PlanGrant::factory()->create(['user_id' => $compte->id]),
        'commande' => ManualPaymentOrder::factory()->create(['user_id' => $compte->id]),
        'recherche' => SearchLog::create(['user_id' => $compte->id, 'query' => 'licenciement abusif', 'results_count' => 3, 'surface' => 'library/search']),
        'usage' => AiUsageLog::create(['user_id' => $compte->id, 'route' => 'assistant/chat', 'status' => 'success']),
        'invitation_envoyee' => $invitationEnvoyee,
    ];
}

/** Toutes les lignes de la base, toutes tables confondues. */
function lignesEnBase(): int
{
    return collect(DB::select('select tablename from pg_tables where schemaname = current_schema()'))
        ->sum(fn (object $table) => (int) DB::selectOne("select count(*) as n from \"{$table->tablename}\"")->n);
}

it('simule par défaut : annonce le compte à effacer sans rien toucher', function () {
    $compte = supprimerDepuis(User::factory()->create(), 40);
    garnirCompte($compte);
    $avant = lignesEnBase();

    $this->artisan('mibeko:purger-comptes-supprimes')
        ->expectsOutputToContain('Comptes supprimés depuis plus de 30 j')
        ->expectsOutputToContain('SIMULATION')
        ->assertSuccessful();

    expect(lignesEnBase())->toBe($avant)
        ->and(User::withTrashed()->find($compte->id))->not->toBeNull();
});

it('efface exactement ce que la simulation annonce, sans rien écrire d\'autre en base', function () {
    $compte = User::factory()->create();
    garnirCompte($compte);
    supprimerDepuis($compte, 40);

    Artisan::call('mibeko:purger-comptes-supprimes');
    expect(preg_match('/(\d+) ligne\(s\) effacée\(s\)/', Artisan::output(), $annonce))->toBe(1);

    $avant = lignesEnBase();
    $this->artisan('mibeko:purger-comptes-supprimes', ['--execute' => true])->assertSuccessful();

    // Aucune ligne ajoutée (pas même une ligne d'audit de l'effacement) :
    // l'écart est exactement ce qui était annoncé.
    expect($avant - lignesEnBase())->toBe((int) $annonce[1]);

    expect(User::withTrashed()->find($compte->id))->toBeNull();
    foreach (['agent_conversations', 'agent_conversation_messages', 'dossiers', 'notifications', 'user_settings', 'mobile_profiles', 'sessions'] as $table) {
        expect(DB::table($table)->where('user_id', $compte->id)->count())->toBe(0, $table);
    }
    expect(DB::table('dossier_pieces')->count())->toBe(0)
        ->and(DB::table('model_has_roles')->where('model_id', $compte->id)->count())->toBe(0)
        ->and(DB::table('personal_access_tokens')->where('tokenable_id', $compte->id)->count())->toBe(0)
        ->and(DB::table('taggables')->where('taggable_id', $compte->id)->count())->toBe(0)
        ->and(DB::table('password_reset_tokens')->where('email', $compte->email)->count())->toBe(0)
        ->and(DB::table('user_invitations')->whereRaw('lower(email) = ?', [$compte->email])->count())->toBe(0);

    // L'adresse est libérée : l'ancien usager peut rouvrir un compte.
    expect(User::factory()->create(['email' => $compte->email])->exists)->toBeTrue();
});

it('garde détaché ce qui survit au compte : paiements, consommation IA, recherches, invitation envoyée', function () {
    $compte = User::factory()->create();
    $survivants = garnirCompte($compte);
    supprimerDepuis($compte, 40);

    $this->artisan('mibeko:purger-comptes-supprimes', ['--execute' => true])->assertSuccessful();

    expect($survivants['octroi']->fresh()?->user_id)->toBeNull()
        ->and($survivants['octroi']->fresh()?->amount_fcfa)->toBe(15_000)
        ->and($survivants['commande']->fresh()?->user_id)->toBeNull()
        ->and($survivants['commande']->fresh()?->reference)->toBe($survivants['commande']->reference)
        ->and($survivants['recherche']->fresh()?->user_id)->toBeNull()
        ->and($survivants['usage']->fresh()?->user_id)->toBeNull()
        ->and(DB::table('user_invitations')->where('id', $survivants['invitation_envoyee'])->value('invited_by'))->toBeNull();
});

it('efface du journal d\'audit le nom, l\'e-mail et l\'IP, et garde l\'événement', function () {
    $compte = User::factory()->create(['name' => 'Nom Témoin', 'email' => 'temoin@example.test']);
    garnirCompte($compte);
    supprimerDepuis($compte, 40);
    // Une action du compte sur un autre objet : l'objet reste, l'auteur part.
    DB::table('audits')->insert([
        'user_type' => $compte->getMorphClass(), 'user_id' => $compte->id, 'event' => 'updated',
        'auditable_type' => 'App\\Models\\LegalDocument', 'auditable_id' => (string) Str::uuid(),
        'old_values' => '{"titre_officiel":"Avant"}', 'new_values' => '{"titre_officiel":"Après"}',
        'ip_address' => '198.51.100.7', 'user_agent' => 'Navigateur témoin', 'created_at' => now(), 'updated_at' => now(),
    ]);
    // Et le compte qui modifie sa propre fiche : objet ET auteur de la ligne.
    DB::table('audits')->insert([
        'user_type' => $compte->getMorphClass(), 'user_id' => $compte->id, 'event' => 'updated',
        'auditable_type' => $compte->getMorphClass(), 'auditable_id' => $compte->id,
        'old_values' => '{"name":"Ancien"}', 'new_values' => '{"name":"Nom Témoin"}',
        'ip_address' => '198.51.100.7', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $objetsDuCompte = DB::table('audits')->where('auditable_id', $compte->id)->pluck('event')->sort()->values()->all();
    expect($objetsDuCompte)->toBe(['created', 'deleted', 'updated']);

    // La simulation compte des lignes distinctes : celle qui est à la fois
    // objet et auteur n'est annoncée qu'une fois.
    $objets = collect([$compte->id])
        ->merge(DB::table('user_settings')->where('user_id', $compte->id)->pluck('id'))
        ->merge(DB::table('dossiers')->where('user_id', $compte->id)->pluck('id'))
        ->map(fn ($id) => (string) $id);
    $attendu = DB::table('audits')
        ->where(fn ($q) => $q->where('user_id', $compte->id)->orWhereIn('auditable_id', $objets))
        ->count();
    expect($attendu)->toBeGreaterThan(4);
    Artisan::call('mibeko:purger-comptes-supprimes');
    expect(Artisan::output())->toContain("  audits : {$attendu}\n");

    $this->artisan('mibeko:purger-comptes-supprimes', ['--execute' => true])->assertSuccessful();

    $trace = DB::table('audits')->where('auditable_id', $compte->id)->get();
    expect($trace->pluck('event')->sort()->values()->all())->toBe($objetsDuCompte)
        ->and($trace->whereNotNull('ip_address'))->toBeEmpty()
        ->and(json_decode($trace->firstWhere('event', 'deleted')->old_values, true))->toHaveKey('email', null);

    $partout = DB::table('audits')->where(fn ($q) => $q
        ->where('old_values', 'like', '%temoin@example.test%')->orWhere('new_values', 'like', '%temoin@example.test%')
        ->orWhere('old_values', 'like', '%Nom Témoin%')->orWhere('new_values', 'like', '%Nom Témoin%'));
    expect($partout->count())->toBe(0);

    $action = DB::table('audits')->where('auditable_type', 'App\\Models\\LegalDocument')->sole();
    expect($action->user_id)->toBeNull()
        ->and($action->ip_address)->toBeNull()
        ->and($action->new_values)->toBe('{"titre_officiel":"Après"}');
});

it('épargne un compte supprimé depuis moins de 30 jours et un compte actif', function () {
    $recent = supprimerDepuis(User::factory()->create(), 29);
    $actif = User::factory()->create();

    $this->artisan('mibeko:purger-comptes-supprimes', ['--execute' => true])
        ->expectsOutputToContain('Comptes supprimés depuis plus de 30 j sur « pgsql » : 0.')
        ->assertSuccessful();

    expect(User::withTrashed()->find($recent->id))->not->toBeNull()
        ->and(User::find($actif->id))->not->toBeNull();
});

it('saute un compte qu\'une clé RESTRICT retient et efface les autres', function () {
    $relecteur = User::factory()->create();
    DocumentRelecturePreuve::factory()->create(['actor_id' => $relecteur->id]);
    supprimerDepuis($relecteur, 60);
    $usager = supprimerDepuis(User::factory()->create(), 40);

    $this->artisan('mibeko:purger-comptes-supprimes', ['--execute' => true])
        ->expectsOutputToContain('document_relecture_preuves.actor_id')
        ->expectsOutputToContain('1 compte(s) effacé(s), 1 sauté(s), 0 en échec.')
        ->assertSuccessful();

    expect(User::withTrashed()->find($relecteur->id))->not->toBeNull()
        ->and(User::withTrashed()->find($usager->id))->toBeNull();
});

it('ne traite que les plus anciens avec --limit', function () {
    $ancien = supprimerDepuis(User::factory()->create(), 90);
    $moinsAncien = supprimerDepuis(User::factory()->create(), 40);

    $this->artisan('mibeko:purger-comptes-supprimes', ['--execute' => true, '--limit' => 1])->assertSuccessful();

    expect(User::withTrashed()->find($ancien->id))->toBeNull()
        ->and(User::withTrashed()->find($moinsAncien->id))->not->toBeNull();
});

it('refuse d\'effacer avec le profil de lecture seule', function () {
    $this->artisan('mibeko:purger-comptes-supprimes', ['--connection' => 'pgsql_prod_ro', '--execute' => true])
        ->expectsOutputToContain('pgsql_prod_ro est un profil de LECTURE')
        ->assertFailed();
});

it('fige le sort de chaque clé étrangère vers users', function () {
    // Une nouvelle table rattachée à un compte doit déclarer ce qu'elle devient
    // à l'effacement : cascade (effacée), set null (anonymisée, ou conservée
    // détachée), restrict (retient le compte). Décision du 24/09/2026.
    $attendu = [
        'agent_conversation_messages.user_id' => 'c',
        'agent_conversations.user_id' => 'c',
        'agent_message_feedback.user_id' => 'c',
        'dossiers.user_id' => 'c',
        'legal_watch_subscriptions.user_id' => 'c',
        'mobile_profiles.user_id' => 'c',
        'notifications.user_id' => 'c',
        'onboarding_enrollments.user_id' => 'c',
        'user_settings.user_id' => 'c',
        'ai_usage_logs.user_id' => 'n',
        'article_versions.reviewed_by' => 'n',
        'credit_ledger_entries.created_by' => 'n',
        'credit_ledger_entries.user_id' => 'n',
        'curation_flags.created_by' => 'n',
        'curation_flags.resolved_by' => 'n',
        'devices.user_id' => 'n',
        'document_relations.created_by' => 'n',
        'document_relations.reviewed_by' => 'n',
        'legal_documents.assigned_to' => 'n',
        'legal_documents.statut_verifie_par' => 'n',
        'manual_payment_orders.resolved_by' => 'n',
        'manual_payment_orders.user_id' => 'n',
        'manual_payment_orders.verification_started_by' => 'n',
        'plan_grant_movements.created_by' => 'n',
        'plan_grants.created_by' => 'n',
        'plan_grants.user_id' => 'n',
        'product_activation_events.user_id' => 'n',
        'publication_checklists.actor_id' => 'n',
        'search_logs.user_id' => 'n',
        'user_invitations.invited_by' => 'n',
        'document_relecture_preuves.actor_id' => 'r',
        'manual_payment_orders.created_by' => 'r',
    ];

    $reel = collect(DB::select(<<<'SQL'
        select c.conrelid::regclass::text || '.' || a.attname as cle, c.confdeltype::text as regle
        from pg_constraint c
        join pg_attribute a on a.attrelid = c.conrelid and a.attnum = c.conkey[1]
        where c.contype = 'f' and c.confrelid = 'users'::regclass
        SQL))->pluck('regle', 'cle')->sortKeys()->all();

    expect($reel)->toBe(collect($attendu)->sortKeys()->all());
});

it('recense chaque modèle audité qui part avec le compte', function () {
    // Tables que la suppression d'un compte atteint en cascade.
    $atteintes = collect(DB::select(<<<'SQL'
        with recursive atteintes(nom) as (
            select 'users'::text
            union
            select c.conrelid::regclass::text
            from pg_constraint c
            join atteintes on c.confrelid::regclass::text = atteintes.nom
            where c.contype = 'f' and c.confdeltype = 'c'
        )
        select nom from atteintes
        SQL))->pluck('nom')->all();

    $audites = collect(glob(app_path('Models/*.php')))
        ->map(fn (string $fichier) => 'App\\Models\\'.basename($fichier, '.php'))
        ->filter(fn (string $classe) => is_subclass_of($classe, Auditable::class))
        ->mapWithKeys(fn (string $classe) => [(new $classe)->getTable() => $classe])
        ->filter(fn (string $classe, string $table) => in_array($table, $atteintes, true))
        ->sortKeys()
        ->all();

    expect($audites)->toBe(collect(PurgerComptesSupprimesCommand::AUDITS_A_EFFACER)->sortKeys()->all());
});

it('tient la promesse des 90 jours : délai de purge plus âge maximal d\'une sauvegarde', function () {
    // mibeko-site, politique de confidentialité § 4 : plus aucune copie 90
    // jours après la demande. `backup:clean` (config/backup.php) garde au plus
    // tout / quotidien / hebdomadaire / mensuel / annuel ; la purge passe une
    // fois par jour, le nettoyage des sauvegardes aussi (d'où + 2).
    $strategie = config('backup.cleanup.default_strategy');
    $ageMaximal = $strategie['keep_all_backups_for_days']
        + $strategie['keep_daily_backups_for_days']
        + 7 * $strategie['keep_weekly_backups_for_weeks']
        + 31 * $strategie['keep_monthly_backups_for_months']
        + 366 * $strategie['keep_yearly_backups_for_years'];

    expect(config('account_deletion.purge_after_days') + $ageMaximal + 2)->toBeLessThanOrEqual(90);
});
