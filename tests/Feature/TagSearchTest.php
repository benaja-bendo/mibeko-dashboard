<?php

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\LegalDocument;
use App\Models\Tag;
use Illuminate\Support\Facades\DB;

it('includes tags in the article full-text search index', function () {
    $document = LegalDocument::factory()->create();
    $article = Article::factory()->create(['document_id' => $document->id]);
    $version = ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'contenu_texte' => 'Un texte juridique sur la santé.'
    ]);
    
    // Refresh to get initial TSV
    $version->refresh();
    
    // Initial search should find it by content
    $results = DB::table('article_versions')
        ->whereRaw("search_tsv @@ to_tsquery('french', 'santé')")
        ->get();
    expect($results)->toHaveCount(1);
    
    // Search by tag should fail now
    $results = DB::table('article_versions')
        ->whereRaw("search_tsv @@ to_tsquery('french', 'vacances')")
        ->get();
    expect($results)->toHaveCount(0);
    
    // Attach a tag
    $tag = Tag::create(['name' => 'Vacances', 'slug' => 'vacances']);
    $article->tags()->attach($tag->id);
    
    // Check if TSV updated
    $version->refresh();
    
    // Search by tag should now succeed
    $results = DB::table('article_versions')
        ->whereRaw("search_tsv @@ to_tsquery('french', 'vacances')")
        ->get();
    expect($results)->toHaveCount(1);
});
