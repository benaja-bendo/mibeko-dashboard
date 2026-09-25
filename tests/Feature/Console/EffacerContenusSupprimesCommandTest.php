<?php

use App\Models\AgentConversation;
use App\Models\AgentConversationMessage;
use App\Models\AgentMessageFeedback;
use App\Models\Article;
use App\Models\Dossier;
use App\Models\DossierEcheance;
use App\Models\DossierPiece;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Dossier supprimé comme avant le correctif de dashboard#205 : la sync mobile
 * posait `deleted_at` en masse, sans rien vider. Supprimé il y a N jours.
 */
function dossierSupprimeAvantCorrectif(User $user, int $jours): Dossier
{
    $dossier = Dossier::factory()->for($user)->create(['name' => 'Affaire Ngoma', 'client_updated_at' => 4000]);
    $dossier->update(['client_name' => 'Marie Ngoma', 'adverse_party' => 'SNE']);
    $dossier->articles()->attach(Article::factory()->create()->id, ['personal_note' => 'Délai de préavis', 'added_at' => 1]);
    DossierEcheance::factory()->for($dossier)->create();
    DossierPiece::factory()->for($dossier)->create();

    DB::table('dossiers')->where('id', $dossier->id)->update(['deleted_at' => now()->subDays($jours)]);

    return $dossier;
}

/** Avis dont la conversation a été supprimée avant le correctif. */
function avisOrphelin(User $user): AgentMessageFeedback
{
    return AgentMessageFeedback::create([
        'message_id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'rating' => AgentMessageFeedback::RATING_DOWN,
        'comment' => 'Ma situation de locataire à Pointe-Noire',
    ]);
}

beforeEach(function () {
    $this->user = User::factory()->create();

    $this->ancien = dossierSupprimeAvantCorrectif($this->user, 10);
    $this->recent = dossierSupprimeAvantCorrectif($this->user, 2);

    $this->vivant = Dossier::factory()->for($this->user)->create(['client_name' => 'Client actif']);
    $this->echeanceSupprimee = DossierEcheance::factory()->for($this->vivant)->create(['title' => 'Audience annulée']);
    DB::table('dossier_echeances')->where('id', $this->echeanceSupprimee->id)->update(['deleted_at' => now()]);
    $this->echeanceVivante = DossierEcheance::factory()->for($this->vivant)->create(['title' => 'Audience maintenue']);

    $this->orphelin = avisOrphelin($this->user);
    $conversation = AgentConversation::factory()->create(['user_id' => $this->user->id]);
    $message = AgentConversationMessage::factory()->assistant()->create(['conversation_id' => $conversation->id, 'user_id' => $this->user->id]);
    $this->avisVivant = AgentMessageFeedback::create(['message_id' => $message->id, 'user_id' => $this->user->id, 'rating' => AgentMessageFeedback::RATING_UP]);
});

it('announces exactly what it would erase, and writes nothing in simulation', function () {
    $this->artisan('mibeko:effacer-contenus-supprimes')
        ->expectsOutputToContain('2 dossier(s) supprimé(s) à vider, 1 échéance(s) supprimée(s) à vider, 1 avis orphelin(s)')
        ->expectsTable(['Table', 'Effet', 'Lignes'], [
            ['agent_message_feedback', 'effacées (conversation supprimée)', 1],
            // Par dossier : la ligne « created » et la ligne « updated ».
            ['audits', 'valeurs vidées (événement et date gardés)', 4],
            ['dossier_articles', 'effacées (annexes d\'un dossier supprimé)', 2],
            ['dossier_echeances', 'effacées (annexes d\'un dossier supprimé)', 2],
            ['dossier_echeances', 'vidées, tombstone gardé (supprimées seules)', 1],
            ['dossier_pieces', 'effacées (annexes d\'un dossier supprimé)', 2],
            ['dossiers', 'vidées, tombstone gardé', 2],
        ])
        ->expectsOutputToContain('SIMULATION')
        ->assertSuccessful();

    expect(DB::table('dossiers')->where('id', $this->ancien->id)->value('client_name'))->toBe('Marie Ngoma')
        ->and(AgentMessageFeedback::whereKey($this->orphelin->id)->exists())->toBeTrue();
});

it('erases the oldest of each kind first in a pilot batch, then the rest, then finds nothing', function () {
    $this->artisan('mibeko:effacer-contenus-supprimes --limit=1 --execute')
        ->expectsOutputToContain('Après passage : 1 dossier(s), 0 échéance(s), 0 avis orphelin(s) (avant : 1, 1, 1).')
        ->assertSuccessful();

    expect(DB::table('dossiers')->where('id', $this->ancien->id)->value('name'))->toBe('')
        ->and(DB::table('dossiers')->where('id', $this->recent->id)->value('name'))->toBe('Affaire Ngoma');

    $this->artisan('mibeko:effacer-contenus-supprimes --execute')->assertSuccessful();

    // Les tombstones restent, et continuent de propager la suppression.
    foreach ([$this->ancien, $this->recent] as $dossier) {
        $ligne = DB::table('dossiers')->where('id', $dossier->id)->first();
        expect($ligne->deleted_at)->not->toBeNull()
            ->and($ligne->name)->toBe('')
            ->and($ligne->client_name)->toBeNull()
            ->and((int) $ligne->client_updated_at)->toBe(4000)
            ->and(DB::table('dossier_articles')->where('dossier_id', $dossier->id)->count())->toBe(0);
    }

    $echeance = DB::table('dossier_echeances')->where('id', $this->echeanceSupprimee->id)->first();
    expect($echeance->deleted_at)->not->toBeNull()
        ->and($echeance->title)->toBe('')
        ->and(AgentMessageFeedback::whereKey($this->orphelin->id)->exists())->toBeFalse();

    // Rien de vivant n'est touché.
    expect($this->vivant->refresh()->client_name)->toBe('Client actif')
        ->and($this->echeanceVivante->refresh()->title)->toBe('Audience maintenue')
        ->and(AgentMessageFeedback::whereKey($this->avisVivant->id)->exists())->toBeTrue();

    $this->artisan('mibeko:effacer-contenus-supprimes')
        ->expectsOutputToContain('Rien à effacer.')
        ->assertSuccessful();
});

it('refuses to erase through the read-only production profile', function () {
    $this->artisan('mibeko:effacer-contenus-supprimes --connection=pgsql_prod_ro --execute')
        ->expectsOutputToContain('pgsql_prod_ro est un profil de LECTURE')
        ->assertFailed();
});
