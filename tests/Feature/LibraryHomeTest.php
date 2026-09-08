<?php

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\DocumentType;
use App\Models\Institution;
use App\Models\LegalDocument;
use App\Models\User;
use App\Observers\ArticleVersionObserver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Embeddings;
use Laravel\Sanctum\Sanctum;

/**
 * Couvre l'accueil de la Bibliothèque (`/library/home`) : textes fondamentaux,
 * derniers textes publiés, statistiques du fonds et suggestions de recherche.
 */
beforeEach(function () {
    ArticleVersionObserver::$shouldSkipEmbeddings = true;
    Embeddings::fake();
    Cache::flush();

    DocumentType::create(['code' => 'LOI', 'nom' => 'Loi']);
    DocumentType::create(['code' => 'CODE', 'nom' => 'Code']);

    Institution::factory()->create(['nom' => 'Ministère de la Justice']);

    // `statut`, `document_role` et `legal_scope` sont fixés explicitement : la
    // factory tire `statut` au hasard parmi vigueur/abroge/projet et la base
    // impose `document_role = 'FLUX'` par défaut — un texte fondamental n'est
    // ni l'un ni l'autre, et sans ces valeurs le test serait instable.
    $code = LegalDocument::factory()->create([
        'type_code' => 'CODE',
        'titre_officiel' => 'Code du Travail',
        'curation_status' => 'published',
        'statut' => 'vigueur',
        'document_role' => 'STOCK',
        'stock_code' => 'CODE_TRAVAIL',
        'consolidation_as_of' => '2024-01-01',
        'legal_scope' => 'national',
        'date_publication' => '2015-03-10',
    ]);
    $codeArticle = Article::factory()->create(['document_id' => $code->id]);
    ArticleVersion::factory()->create([
        'article_id' => $codeArticle->id,
        'contenu_texte' => 'Le contrat de travail est régi par le présent code.',
        'validity_period' => '[2020-01-01,)',
    ]);

    $loi = LegalDocument::factory()->create([
        'type_code' => 'LOI',
        'titre_officiel' => 'Loi récente sur le numérique',
        'curation_status' => 'published',
        'statut' => 'vigueur',
        'date_publication' => '2026-01-15',
    ]);
    $loiArticle = Article::factory()->create(['document_id' => $loi->id]);
    ArticleVersion::factory()->create([
        'article_id' => $loiArticle->id,
        'contenu_texte' => 'Dispositions relatives au numérique.',
        'validity_period' => '[2026-01-15,)',
    ]);

    // Document non publié : ne doit apparaître nulle part.
    LegalDocument::factory()->create([
        'type_code' => 'LOI',
        'titre_officiel' => 'Brouillon non publié',
        'curation_status' => 'draft',
    ]);

    Sanctum::actingAs(User::factory()->create());
});

it('retourne stats, textes fondamentaux, récents et suggestions', function () {
    $response = $this->getJson('/api/v1/library/home');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                'stats' => ['documents', 'articles', 'institutions'],
                'essential_documents' => [
                    '*' => ['id', 'title', 'type_code', 'type_name', 'legal_scope', 'date_publication', 'articles_count'],
                ],
                'recent_documents',
                'suggestions',
            ],
        ])
        ->assertJsonPath('data.stats.documents', 2)
        ->assertJsonPath('data.stats.articles', 2);

    // Le Code du Travail est un texte fondamental ; le brouillon est exclu.
    $essentialTitles = collect($response->json('data.essential_documents'))->pluck('title');
    expect($essentialTitles)->toContain('Code du Travail')
        ->not->toContain('Brouillon non publié');

    // Les récents sont triés par date de publication décroissante.
    expect($response->json('data.recent_documents.0.title'))->toBe('Loi récente sur le numérique');

    expect($response->json('data.suggestions'))->toBeArray()->not->toBeEmpty();
});

it('exclut les documents non publiés des récents', function () {
    $titles = collect($this->getJson('/api/v1/library/home')->json('data.recent_documents'))
        ->pluck('title');

    expect($titles)->not->toContain('Brouillon non publié');
});

/**
 * mibeko-dashboard#114 — les trois défauts constatés en production le
 * 07/09/2026. Chacun se rejoue ici avec la donnée qui l'a produit.
 */
describe('textes fondamentaux', function () {
    /** Crée un document publié doté d'un article, donc éligible à `published()`. */
    function texte(array $attributs): LegalDocument
    {
        // La contrainte `chk_legal_documents_role_logic` impose à un STOCK un
        // `stock_code` et une date de consolidation, et lui interdit tout JO.
        $defauts = [
            'curation_status' => 'published',
            'statut' => 'vigueur',
            'document_role' => 'STOCK',
            'legal_scope' => 'national',
        ];

        if (($attributs['document_role'] ?? 'STOCK') === 'STOCK') {
            $defauts['stock_code'] = 'STOCK_'.str()->random(8);
            $defauts['consolidation_as_of'] = '2024-01-01';
        }

        $document = LegalDocument::factory()->create(array_merge($defauts, $attributs));

        $article = Article::factory()->create(['document_id' => $document->id]);
        ArticleVersion::factory()->create([
            'article_id' => $article->id,
            'contenu_texte' => 'Contenu de test.',
            'validity_period' => '[2020-01-01,)',
        ]);

        return $document;
    }

    /** @return Collection<int, string> */
    function titresEssentiels($test): Collection
    {
        return collect($test->getJson('/api/v1/library/home')->json('data.essential_documents'))
            ->pluck('title');
    }

    it("n'affiche jamais un texte abrogé, même s'il est de nature constitutionnelle", function () {
        DocumentType::create(['code' => 'CONST', 'nom' => 'Constitution']);

        // Le défaut exact de la production : un acte fondamental abrogé servi
        // en tête, pendant que la Constitution en vigueur restait invisible.
        texte([
            'type_code' => 'CONST',
            'titre_officiel' => 'Acte fondamental de 1997',
            'statut' => 'abroge',
        ]);
        texte([
            'type_code' => 'CONST',
            'titre_officiel' => 'Republique du Congo Constitution 2015',
        ]);

        expect(titresEssentiels($this))
            ->not->toContain('Acte fondamental de 1997')
            ->toContain('Republique du Congo Constitution 2015');
    });

    it('écarte le droit hors du périmètre annoncé (Congo et OHADA)', function () {
        texte([
            'type_code' => 'CODE',
            'titre_officiel' => 'Code Communautaire CEMAC',
            'legal_scope' => 'communautaire',
        ]);

        expect(titresEssentiels($this))->not->toContain('Code Communautaire CEMAC');
    });

    it("écarte un acte unitaire issu d'un Journal officiel, même mal typé", function () {
        DocumentType::create(['code' => 'AU', 'nom' => 'Acte uniforme OHADA']);

        // Cas réel : en production, un « Journal officiel n° 1-2011 » porte le
        // type `AU`. Seul le rôle FLUX l'écarte — le type ne suffit pas.
        texte([
            'type_code' => 'AU',
            'titre_officiel' => 'Journal officiel n° 1-2011 — spécial',
            'document_role' => 'FLUX',
            'legal_scope' => 'ohada',
        ]);

        expect(titresEssentiels($this))->not->toContain('Journal officiel n° 1-2011 — spécial');
    });

    it('ne retient pas un texte au seul motif que son intitulé commence par « code »', function () {
        // L'ancienne sélection reposait sur `titre_officiel ILIKE 'code %'`.
        texte([
            'type_code' => 'LOI',
            'titre_officiel' => 'Code de bonne conduite des agents (loi ordinaire)',
        ]);

        expect(titresEssentiels($this))
            ->not->toContain('Code de bonne conduite des agents (loi ordinaire)');
    });

    it("réserve des places à l'OHADA que le volume des codes nationaux aurait prises", function () {
        DocumentType::create(['code' => 'AU', 'nom' => 'Acte uniforme OHADA']);

        // Cinq codes nationaux volumineux : classés au seul nombre d'articles,
        // ils rempliraient les six places et l'OHADA disparaîtrait.
        foreach (range(1, 5) as $rang) {
            $document = texte(['type_code' => 'CODE', 'titre_officiel' => "Code national {$rang}"]);
            Article::factory()->count(20)->create(['document_id' => $document->id]);
        }

        texte([
            'type_code' => 'AU',
            'titre_officiel' => 'Acte uniforme portant organisation des sûretés',
            'legal_scope' => 'ohada',
        ]);

        expect(titresEssentiels($this))
            ->toContain('Acte uniforme portant organisation des sûretés');
    });

    it('rend au reste du corpus les places qu\'un périmètre vide laisse libres', function () {
        // Aucun texte OHADA : les deux places qui lui sont réservées ne doivent
        // pas être perdues, sinon une base incomplète sert une page à trous.
        foreach (range(1, 7) as $rang) {
            texte(['type_code' => 'CODE', 'titre_officiel' => "Code national {$rang}"]);
        }

        expect(titresEssentiels($this))->toHaveCount(6);
    });
});

it('est accessible sans authentification (lecture publique partagée web/mobile)', function () {
    auth()->forgetGuards();

    $this->getJson('/api/v1/library/home')->assertOk();
});
