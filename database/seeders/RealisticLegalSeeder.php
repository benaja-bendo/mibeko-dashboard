<?php

namespace Database\Seeders;

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\DocumentType;
use App\Models\Institution;
use App\Models\LegalDocument;
use App\Models\StructureNode;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class RealisticLegalSeeder extends Seeder
{
    public function run(): void
    {
        $presidence = Institution::firstOrCreate(['sigle' => 'PR'], [
            'nom' => 'Présidence de la République',
            'sigle' => 'PR'
        ]);

        $ministereTravail = Institution::firstOrCreate(['sigle' => 'METP'], [
            'nom' => 'Ministère du Travail et de la Prévoyance Sociale',
            'sigle' => 'METP'
        ]);

        $ministereSante = Institution::firstOrCreate(['sigle' => 'MSP'], [
            'nom' => 'Ministère de la Santé Publique',
            'sigle' => 'MSP'
        ]);

        $ministereBudget = Institution::firstOrCreate(['sigle' => 'MB'], [
            'nom' => 'Ministère du Budget',
            'sigle' => 'MB'
        ]);

        $ministereBudget = Institution::firstOrCreate(['sigle' => 'MB'], [
            'nom' => 'Ministère du Budget',
            'sigle' => 'MB'
        ]);

        // Ensure types exist
        $constType = DocumentType::firstOrCreate(['code' => 'CONST'], ['code' => 'CONST', 'nom' => 'Constitution', 'niveau_hierarchique' => 0]);
        $codeType = DocumentType::firstOrCreate(['code' => 'CODE'], ['code' => 'CODE', 'nom' => 'Code', 'niveau_hierarchique' => 1]);
        $loiType = DocumentType::firstOrCreate(['code' => 'LOI'], ['code' => 'LOI', 'nom' => 'Loi', 'niveau_hierarchique' => 2]);
        $decType = DocumentType::firstOrCreate(['code' => 'DEC'], ['code' => 'DEC', 'nom' => 'Décret', 'niveau_hierarchique' => 3]);
        $ordType = DocumentType::firstOrCreate(['code' => 'ORD'], ['code' => 'ORD', 'nom' => 'Ordonnance', 'niveau_hierarchique' => 3]);

        // 1. Constitution
        $constitution = LegalDocument::create([
            'id' => Str::uuid(),
            'titre_officiel' => 'Constitution de la République du Congo',
            'reference_nor' => 'CONST-2015',
            'statut' => 'vigueur',
            'type_code' => $constType->code,
            'institution_id' => $presidence->id,
            'date_signature' => '2015-10-25',
        ]);

        $titre1 = StructureNode::create([
            'document_id' => $constitution->id,
            'type_unite' => 'Titre',
            'numero' => 'I',
            'titre' => 'DE L\'ETAT ET DE LA SOUVERAINETE',
            'tree_path' => 'TITRE_I',
            'sort_order' => 1
        ]);

        $art1 = Article::create([
            'document_id' => $constitution->id,
            'parent_node_id' => $titre1->id,
            'numero_article' => '1er',
            'ordre_affichage' => 1
        ]);

        ArticleVersion::create([
            'article_id' => $art1->id,
            'contenu_texte' => 'La République du Congo est un État de droit, souverain, unitaire, indivisible, décentralisé, laïc et démocratique. Sa capitale est Brazzaville.',
            'validity_period' => ArticleVersion::makeValidityPeriod('2015-10-25'),
        ]);

        // 2. Code du Travail
        $codeTravail = LegalDocument::create([
            'id' => Str::uuid(),
            'titre_officiel' => 'Code du Travail de la République du Congo',
            'reference_nor' => 'CODE-TRAVAIL-LAB',
            'statut' => 'vigueur',
            'type_code' => $codeType->code,
            'institution_id' => $ministereTravail->id,
            'date_signature' => '1975-03-15',
        ]);

        $chapContrat = StructureNode::create([
            'document_id' => $codeTravail->id,
            'type_unite' => 'Chapitre',
            'numero' => 'II',
            'titre' => 'DU CONTRAT DE TRAVAIL',
            'tree_path' => 'CHAPITRE_II',
            'sort_order' => 1
        ]);

        $artLicenciement = Article::create([
            'document_id' => $codeTravail->id,
            'parent_node_id' => $chapContrat->id,
            'numero_article' => '32',
            'ordre_affichage' => 1
        ]);

        ArticleVersion::create([
            'article_id' => $artLicenciement->id,
            'contenu_texte' => 'Le licenciement pour motif économique est tout licenciement effectué par un employeur pour un ou plusieurs motifs non inhérents à la personne du travailleur et résultant d\'une suppression ou transformation d\'emploi ou d\'une modification substantielle du contrat de travail.',
            'validity_period' => ArticleVersion::makeValidityPeriod('1975-03-15'),
        ]);

        // 3. Code Civil
        $codeCivil = LegalDocument::create([
            'id' => Str::uuid(),
            'titre_officiel' => 'Code Civil Congolais',
            'reference_nor' => 'CODE-CIVIL',
            'statut' => 'vigueur',
            'type_code' => $codeType->code,
            'institution_id' => $presidence->id,
        ]);

        $titreFamille = StructureNode::create([
            'document_id' => $codeCivil->id,
            'type_unite' => 'Titre',
            'numero' => 'V',
            'titre' => 'DU MARIAGE ET DE LA FAMILLE',
            'tree_path' => 'TITRE_V',
            'sort_order' => 1
        ]);

        $artMariage = Article::create([
            'document_id' => $codeCivil->id,
            'parent_node_id' => $titreFamille->id,
            'numero_article' => '144',
            'ordre_affichage' => 1
        ]);

        ArticleVersion::create([
            'article_id' => $artMariage->id,
            'contenu_texte' => 'Le mariage est l\'union légitime d\'un homme et d\'une femme. La famille est la cellule de base de la société. Elle est placée sous la protection de l\'État.',
            'validity_period' => ArticleVersion::makeValidityPeriod('1960-08-15'),
        ]);
        
        // Add a simple Law for diversity
        LegalDocument::create([
            'id' => Str::uuid(),
            'titre_officiel' => 'Loi n° 5-2023 du 11 mai 2023 portant protection des données à caractère personnel',
            'reference_nor' => 'LOI-2023-005',
            'statut' => 'vigueur',
            'type_code' => $loiType->code,
            'institution_id' => $presidence->id,
        ]);
    }
}
