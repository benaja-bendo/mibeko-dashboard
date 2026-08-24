<?php

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\DocumentType;
use App\Models\LegalDocument;
use App\Observers\ArticleVersionObserver;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;

/**
 * `mibeko:restituer-article` : remet en place un article qu'un titre perdu au
 * parsing MinerU a fait disparaître (« ## Articie 22 », ou pas d'en-tête du
 * tout). Simulation par défaut, garde-fous bloquants avant tout appel réseau.
 */
beforeEach(function () {
    ArticleVersionObserver::$shouldSkipEmbeddings = true;
    Embeddings::fake();

    DocumentType::firstOrCreate(['code' => 'AU'], ['nom' => 'Acte uniforme']);
});

/**
 * Un document avec un article porteur d'un texte donné, à un rang donné.
 *
 * @return array{0: LegalDocument, 1: Article}
 */
function articleAvecTexte(string $texte, int $rang = 10, string $numero = '21'): array
{
    $document = LegalDocument::factory()->create([
        'type_code' => 'AU',
        'curation_status' => 'draft',
    ]);

    $article = Article::factory()->create([
        'document_id' => $document->id,
        'numero_article' => $numero,
        'ordre_affichage' => $rang,
        'parent_node_id' => null,
    ]);

    ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'contenu_texte' => $texte,
        'validity_period' => ArticleVersion::makeValidityPeriod(now()->toDateString()),
    ]);

    return [$document, $article];
}

function ecrireLot(array $entrees, string $nom): string
{
    $chemin = storage_path("app/{$nom}.json");
    file_put_contents($chemin, json_encode($entrees));

    return $chemin;
}

it('calcule la coupe sans rien écrire en simulation', function () {
    [, $article] = articleAvecTexte(
        "La caution peut, elle-même, se faire cautionner par un certificateur.\n\n"
        .'La caution peut garantir son engagement en consentant une sûreté réelle.'
    );

    $lot = ecrireLot([[
        'source' => 'scission',
        'document' => 'AU sûretés',
        'article_id' => $article->id,
        'numero_nouveau' => '22',
        'marqueur' => 'La caution peut garantir son engagement',
        'motif' => 'titre océrisé « Articie 22 »',
    ]], 'lot-scission-simulation');

    Http::fake();

    $this->artisan('mibeko:restituer-article', ['--lot' => $lot, '--connection' => 'pgsql'])
        ->expectsOutputToContain('SIMULATION')
        ->assertExitCode(0);

    Http::assertNothingSent();
    expect(Article::where('document_id', $article->document_id)->count())->toBe(1);
    expect($article->fresh()->activeVersion->contenu_texte)->toContain('sûreté réelle');

    unlink($lot);
});

it('crée le nouvel article puis tronque son voisin, dans cet ordre', function () {
    [$document, $article] = articleAvecTexte(
        "Texte de l'article 21.\n\nLa caution peut garantir son engagement en consentant une sûreté réelle."
    );

    $lot = ecrireLot([[
        'source' => 'scission',
        'document' => 'AU sûretés',
        'article_id' => $article->id,
        'numero_nouveau' => '22',
        'marqueur' => 'La caution peut garantir son engagement',
    ]], 'lot-scission-execute');

    putenv('MIBEKO_API_TOKEN=jeton-de-test');
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    $this->artisan('mibeko:restituer-article', [
        '--lot' => $lot, '--connection' => 'pgsql', '--rythme' => 0, '--execute' => true,
    ])->assertExitCode(0);

    $appels = Http::recorded();
    expect($appels)->toHaveCount(2);

    // 1er appel : création. Un échec après coup laisse le texte en double,
    // jamais perdu — c'est la raison de cet ordre.
    [$creation] = $appels[0];
    expect($creation->method())->toBe('POST');
    expect($creation->url())->toEndWith('/articles');
    expect($creation->data()['numero_article'])->toBe('22');
    expect($creation->data()['document_id'])->toBe($document->id);
    expect($creation->data()['ordre_affichage'])->toBe(11);
    expect($creation->data()['content'])->toBe('La caution peut garantir son engagement en consentant une sûreté réelle.');

    // 2e appel : troncature du voisin, débarrassé du texte rendu.
    [$troncature] = $appels[1];
    expect($troncature->method())->toBe('PATCH');
    expect($troncature->url())->toEndWith('/articles/'.$article->id);
    expect($troncature->data()['content'])->toBe("Texte de l'article 21.");

    putenv('MIBEKO_API_TOKEN');
    unlink($lot);
});

it('insère un texte relu quand l\'article est absent de la base (source « texte »)', function () {
    [$document, $article] = articleAvecTexte('Pour tout litige auquel donne lieu un transport.', 27, '27');

    $lot = ecrireLot([[
        'source' => 'texte',
        'document' => 'AU transport',
        'apres_article_id' => $article->id,
        'numero_nouveau' => '28',
        'content' => 'Est nulle et de nul effet toute stipulation qui dérogerait au présent Acte uniforme.',
        'motif' => 'en-tête « Article 28 » absent du markdown MinerU',
    ]], 'lot-texte-execute');

    putenv('MIBEKO_API_TOKEN=jeton-de-test');
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    $this->artisan('mibeko:restituer-article', [
        '--lot' => $lot, '--connection' => 'pgsql', '--rythme' => 0, '--execute' => true,
    ])->assertExitCode(0);

    $appels = Http::recorded();

    // Le voisin n'est PAS touché : son texte ne contenait pas celui de l'article
    // perdu, il n'y a rien à lui retirer.
    expect($appels)->toHaveCount(1);
    [$creation] = $appels[0];
    expect($creation->method())->toBe('POST');
    expect($creation->data()['numero_article'])->toBe('28');
    expect($creation->data()['document_id'])->toBe($document->id);
    expect($creation->data()['ordre_affichage'])->toBe(28);

    putenv('MIBEKO_API_TOKEN');
    unlink($lot);
});

it('relit le rang avant chaque écriture : deux restitutions dans un même document ne s\'inversent pas', function () {
    // Régression du 20/08/2026, trouvée sur l'AU sûretés : le rang calculé au
    // moment du plan est périmé dès la première création, qui décale la
    // fratrie. Les articles 181 et 182 étaient ressortis dans le désordre.
    $document = LegalDocument::factory()->create(['type_code' => 'AU', 'curation_status' => 'draft']);

    $premier = Article::factory()->create([
        'document_id' => $document->id, 'numero_article' => '30', 'ordre_affichage' => 10, 'parent_node_id' => null,
    ]);
    $second = Article::factory()->create([
        'document_id' => $document->id, 'numero_article' => '40', 'ordre_affichage' => 11, 'parent_node_id' => null,
    ]);

    foreach ([$premier, $second] as $article) {
        ArticleVersion::factory()->create([
            'article_id' => $article->id,
            'contenu_texte' => 'Texte porteur.',
            'validity_period' => ArticleVersion::makeValidityPeriod(now()->toDateString()),
        ]);
    }

    $lot = ecrireLot([
        ['source' => 'texte', 'document' => 'AU', 'apres_article_id' => $premier->id, 'numero_nouveau' => '31', 'content' => 'Texte de 31.'],
        ['source' => 'texte', 'document' => 'AU', 'apres_article_id' => $second->id, 'numero_nouveau' => '41', 'content' => 'Texte de 41.'],
    ], 'lot-rang-relu');

    putenv('MIBEKO_API_TOKEN=jeton-de-test');

    // Le faux serveur reproduit le décalage de fratrie que fait réellement
    // ArticleController::store — sans lui, la régression reste invisible.
    Http::fake(function ($requete) {
        if ($requete->method() === 'POST') {
            $charge = $requete->data();
            Article::where('document_id', $charge['document_id'])
                ->where('ordre_affichage', '>=', $charge['ordre_affichage'])
                ->increment('ordre_affichage');
        }

        return Http::response(['data' => []], 200);
    });

    $this->artisan('mibeko:restituer-article', [
        '--lot' => $lot, '--connection' => 'pgsql', '--rythme' => 0, '--execute' => true,
    ])->assertExitCode(0);

    $appels = Http::recorded();
    expect($appels[0][0]->data()['ordre_affichage'])->toBe(11);

    // Le premier POST a poussé l'article 40 de 11 à 12 : le second doit viser
    // 13, pas le 12 qu'aurait donné le plan initial.
    expect($appels[1][0]->data()['ordre_affichage'])->toBe(13);

    putenv('MIBEKO_API_TOKEN');
    unlink($lot);
});

it('refuse le lot entier si un marqueur est absent', function () {
    [, $article] = articleAvecTexte('Un texte qui ne contient pas ce qu\'on y cherche.');

    $lot = ecrireLot([[
        'source' => 'scission',
        'document' => 'AU sûretés',
        'article_id' => $article->id,
        'numero_nouveau' => '22',
        'marqueur' => 'marqueur introuvable',
    ]], 'lot-marqueur-absent');

    Http::fake();

    $this->artisan('mibeko:restituer-article', ['--lot' => $lot, '--connection' => 'pgsql'])
        ->expectsOutputToContain('lot non exécuté')
        ->assertExitCode(1);

    Http::assertNothingSent();

    unlink($lot);
});

it('refuse une coupe ambiguë quand le marqueur apparaît deux fois', function () {
    [, $article] = articleAvecTexte('La caution peut. Suite du texte. La caution peut. Fin.');

    $lot = ecrireLot([[
        'source' => 'scission',
        'document' => 'AU sûretés',
        'article_id' => $article->id,
        'numero_nouveau' => '22',
        'marqueur' => 'La caution peut',
    ]], 'lot-marqueur-double');

    Http::fake();

    $this->artisan('mibeko:restituer-article', ['--lot' => $lot, '--connection' => 'pgsql'])
        ->expectsOutputToContain('coupe ambiguë')
        ->assertExitCode(1);

    Http::assertNothingSent();

    unlink($lot);
});

it('refuse de créer un numéro d\'article qui existe déjà — rejeu sans effet', function () {
    [$document, $article] = articleAvecTexte("Début.\n\nLa caution peut garantir son engagement.");

    Article::factory()->create([
        'document_id' => $document->id,
        'numero_article' => '22',
        'ordre_affichage' => 11,
    ]);

    $lot = ecrireLot([[
        'source' => 'scission',
        'document' => 'AU sûretés',
        'article_id' => $article->id,
        'numero_nouveau' => '22',
        'marqueur' => 'La caution peut garantir son engagement',
    ]], 'lot-numero-existant');

    Http::fake();

    $this->artisan('mibeko:restituer-article', ['--lot' => $lot, '--connection' => 'pgsql'])
        ->expectsOutputToContain('existe déjà')
        ->assertExitCode(1);

    Http::assertNothingSent();

    unlink($lot);
});

it('refuse une coupe qui viderait l\'article de référence', function () {
    [, $article] = articleAvecTexte('La caution peut garantir son engagement, et rien avant.');

    $lot = ecrireLot([[
        'source' => 'scission',
        'document' => 'AU sûretés',
        'article_id' => $article->id,
        'numero_nouveau' => '22',
        'marqueur' => 'La caution peut garantir',
    ]], 'lot-coupe-vide');

    Http::fake();

    $this->artisan('mibeko:restituer-article', ['--lot' => $lot, '--connection' => 'pgsql'])
        ->expectsOutputToContain('viderait')
        ->assertExitCode(1);

    Http::assertNothingSent();

    unlink($lot);
});

it('exige MIBEKO_API_TOKEN dans le shell pour écrire', function () {
    [, $article] = articleAvecTexte("Début.\n\nLa caution peut garantir son engagement.");

    $lot = ecrireLot([[
        'source' => 'scission',
        'document' => 'AU sûretés',
        'article_id' => $article->id,
        'numero_nouveau' => '22',
        'marqueur' => 'La caution peut garantir son engagement',
    ]], 'lot-sans-jeton');

    putenv('MIBEKO_API_TOKEN');
    Http::fake();

    $this->artisan('mibeko:restituer-article', [
        '--lot' => $lot, '--connection' => 'pgsql', '--execute' => true,
    ])->assertExitCode(1);

    Http::assertNothingSent();

    unlink($lot);
});
