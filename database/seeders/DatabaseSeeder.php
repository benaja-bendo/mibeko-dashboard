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
        // $types = [
        //     ['code' => 'CONST', 'nom' => 'Constitution', 'niveau_hierarchique' => 0],
        //     ['code' => 'TRAITE', 'nom' => 'Traité & Accord International', 'niveau_hierarchique' => 10],
        //     ['code' => 'LOI_ORG', 'nom' => 'Loi Organique', 'niveau_hierarchique' => 20],
        //     ['code' => 'CODE', 'nom' => 'Code', 'niveau_hierarchique' => 30],
        //     ['code' => 'LOI', 'nom' => 'Loi', 'niveau_hierarchique' => 40],
        //     ['code' => 'DEC_LOI', 'nom' => 'Décret-Loi', 'niveau_hierarchique' => 50],
        //     ['code' => 'ORD', 'nom' => 'Ordonnance', 'niveau_hierarchique' => 60],
        //     ['code' => 'DEC', 'nom' => 'Décret', 'niveau_hierarchique' => 70],
        //     ['code' => 'ARR', 'nom' => 'Arrêté', 'niveau_hierarchique' => 80],
        //     ['code' => 'DECIS', 'nom' => 'Décision', 'niveau_hierarchique' => 90],
        //     ['code' => 'CIRC', 'nom' => 'Circulaire', 'niveau_hierarchique' => 100],
        //     ['code' => 'JUR', 'nom' => 'Jurisprudence', 'niveau_hierarchique' => 110],
        // ];

        // foreach ($types as $type) {
        //     DocumentType::firstOrCreate(['code' => $type['code']], $type);
        // }

        // Create Institutions
        //$institutions = Institution::factory(5)->create();

        // Create Legal Documents
        // LegalDocument::factory(10)
        //     ->recycle($institutions)
        //     ->recycle(DocumentType::all())
        //     ->create()
        //     ->each(function ($doc) {
        //         // Create Structure Nodes for each document
        //         $nodes = StructureNode::factory(3)->create([
        //             'document_id' => $doc->id,
        //         ]);

        //         // Create Articles for each node
        //         foreach ($nodes as $node) {
        //             Article::factory(5)->create([
        //                 'document_id' => $doc->id,
        //                 'parent_node_id' => $node->id,
        //             ])->each(function ($article) {
        //                 // Create Versions for each article
        //                 ArticleVersion::factory()->create([
        //                     'article_id' => $article->id,
        //                 ]);
        //             });
        //         }
        //     });
        // Call other seeders
        $this->call([
            RealisticLegalSeeder::class,
            CongoJournalOfficielSeeder::class,
        ]);


    }
}
