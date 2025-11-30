<?php

namespace Database\Seeders;

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\DocumentType;
use App\Models\Institution;
use App\Models\LegalDocument;
use App\Models\StructureNode;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create standard Document Types
        $types = [
            ['code' => 'LOI', 'nom' => 'Loi', 'niveau_hierarchique' => 2],
            ['code' => 'DEC', 'nom' => 'Décret', 'niveau_hierarchique' => 3],
            ['code' => 'ORD', 'nom' => 'Ordonnance', 'niveau_hierarchique' => 3],
            ['code' => 'CODE', 'nom' => 'Code', 'niveau_hierarchique' => 1],
            ['code' => 'CONST', 'nom' => 'Constitution', 'niveau_hierarchique' => 0],
        ];

        foreach ($types as $type) {
            DocumentType::firstOrCreate(['code' => $type['code']], $type);
        }

        // Create Institutions
        $institutions = Institution::factory(5)->create();

        // Create Legal Documents
        LegalDocument::factory(10)
            ->recycle($institutions)
            ->recycle(DocumentType::all())
            ->create()
            ->each(function ($doc) {
                // Create Structure Nodes for each document
                $nodes = StructureNode::factory(3)->create([
                    'document_id' => $doc->id,
                ]);

                // Create Articles for each node
                foreach ($nodes as $node) {
                    Article::factory(5)->create([
                        'document_id' => $doc->id,
                        'parent_node_id' => $node->id,
                    ])->each(function ($article) {
                        // Create Versions for each article
                        ArticleVersion::factory()->create([
                            'article_id' => $article->id,
                        ]);
                    });
                }
            });
    }
}
