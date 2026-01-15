<?php

namespace Database\Seeders;

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\DocumentType;
use App\Models\Institution;
use App\Models\LegalDocument;
use App\Models\StructureNode;
use App\Models\Tag;
use App\Observers\ArticleVersionObserver;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class RealisticLegalSeeder extends Seeder
{
    public function run(): void
    {
        // Disable automatic embedding generation during seeding
        ArticleVersionObserver::$shouldSkipEmbeddings = true;

        // 0. Create Life Themes Tags
        $tagFamille = Tag::firstOrCreate(['slug' => 'famille'], ['name' => 'Famille & Personnes', 'slug' => 'famille']);
        $tagTravail = Tag::firstOrCreate(['slug' => 'travail'], ['name' => 'Travail & Entreprise', 'slug' => 'travail']);
        $tagLogement = Tag::firstOrCreate(['slug' => 'logement'], ['name' => 'Logement & Foncier', 'slug' => 'logement']);
        $tagJustice = Tag::firstOrCreate(['slug' => 'justice'], ['name' => 'Justice & Droits', 'slug' => 'justice']);

        $presidence = Institution::firstOrCreate(['sigle' => 'PR'], [
            'nom' => 'Présidence de la République',
            'sigle' => 'PR',
        ]);

        $ministereTravail = Institution::firstOrCreate(['sigle' => 'METP'], [
            'nom' => 'Ministère du Travail et de la Prévoyance Sociale',
            'sigle' => 'METP',
        ]);

        $ministereSante = Institution::firstOrCreate(['sigle' => 'MSP'], [
            'nom' => 'Ministère de la Santé Publique',
            'sigle' => 'MSP',
        ]);

        $ministereBudget = Institution::firstOrCreate(['sigle' => 'MB'], [
            'nom' => 'Ministère du Budget',
            'sigle' => 'MB',
        ]);

        // Ensure types exist
        $constType = DocumentType::firstOrCreate(['code' => 'CONST'], ['code' => 'CONST', 'nom' => 'Constitution', 'niveau_hierarchique' => 0]);
        $traiteType = DocumentType::firstOrCreate(['code' => 'TRAITE'], ['code' => 'TRAITE', 'nom' => 'Traité & Accord International', 'niveau_hierarchique' => 10]);
        $loiOrgType = DocumentType::firstOrCreate(['code' => 'LOI_ORG'], ['code' => 'LOI_ORG', 'nom' => 'Loi Organique', 'niveau_hierarchique' => 20]);
        $codeType = DocumentType::firstOrCreate(['code' => 'CODE'], ['code' => 'CODE', 'nom' => 'Code', 'niveau_hierarchique' => 30]);
        $loiType = DocumentType::firstOrCreate(['code' => 'LOI'], ['code' => 'LOI', 'nom' => 'Loi', 'niveau_hierarchique' => 40]);
        $decLoiType = DocumentType::firstOrCreate(['code' => 'DEC_LOI'], ['code' => 'DEC_LOI', 'nom' => 'Décret-Loi', 'niveau_hierarchique' => 50]);
        $ordType = DocumentType::firstOrCreate(['code' => 'ORD'], ['code' => 'ORD', 'nom' => 'Ordonnance', 'niveau_hierarchique' => 60]);
        $decType = DocumentType::firstOrCreate(['code' => 'DEC'], ['code' => 'DEC', 'nom' => 'Décret', 'niveau_hierarchique' => 70]);
        $arrType = DocumentType::firstOrCreate(['code' => 'ARR'], ['code' => 'ARR', 'nom' => 'Arrêté', 'niveau_hierarchique' => 80]);
        $decisType = DocumentType::firstOrCreate(['code' => 'DECIS'], ['code' => 'DECIS', 'nom' => 'Décision', 'niveau_hierarchique' => 90]);
        $circType = DocumentType::firstOrCreate(['code' => 'CIRC'], ['code' => 'CIRC', 'nom' => 'Circulaire', 'niveau_hierarchique' => 100]);
        $jurType = DocumentType::firstOrCreate(['code' => 'JUR'], ['code' => 'JUR', 'nom' => 'Jurisprudence', 'niveau_hierarchique' => 110]);

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
            'sort_order' => 1,
        ]);

        $art1 = Article::create([
            'document_id' => $constitution->id,
            'parent_node_id' => $titre1->id,
            'numero_article' => '1er',
            'ordre_affichage' => 1,
        ]);

        $art1->versions()->create([
            'contenu_texte' => 'La République du Congo est un État de droit, souverain, unitaire, indivisible, décentralisé, laïc et démocratique. Sa capitale est Brazzaville.',
            'validity_period' => ArticleVersion::makeValidityPeriod('2015-10-25'),
            'validation_status' => 'validated',
        ]);

        $art1->tags()->sync([$tagJustice->id]);

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
            'sort_order' => 1,
        ]);

        $artLicenciement = Article::create([
            'document_id' => $codeTravail->id,
            'parent_node_id' => $chapContrat->id,
            'numero_article' => '32',
            'ordre_affichage' => 1,
        ]);

        $artLicenciement->versions()->create([
            'contenu_texte' => 'Le licenciement pour motif économique est tout licenciement effectué par un employeur pour un ou plusieurs motifs non inhérents à la personne du travailleur et résultant d\'une suppression ou transformation d\'emploi ou d\'une modification substantielle du contrat de travail.',
            'validity_period' => ArticleVersion::makeValidityPeriod('1975-03-15'),
            'validation_status' => 'validated',
        ]);

        $artLicenciement->tags()->sync([$tagTravail->id]);

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
            'sort_order' => 1,
        ]);

        $artMariage = Article::create([
            'document_id' => $codeCivil->id,
            'parent_node_id' => $titreFamille->id,
            'numero_article' => '144',
            'ordre_affichage' => 1,
        ]);

        $artMariage->versions()->create([
            'contenu_texte' => 'Le mariage est l\'union légitime d\'un homme et d\'une femme. La famille est la cellule de base de la société. Elle est placée sous la protection de l\'État.',
            'validity_period' => ArticleVersion::makeValidityPeriod('1960-08-15'),
            'validation_status' => 'validated',
        ]);

        $artMariage->tags()->sync([$tagFamille->id]);

        // Add a simple Law for diversity
        $loiDonnees = LegalDocument::create([
            'id' => Str::uuid(),
            'titre_officiel' => 'Loi n° 5-2023 du 11 mai 2023 portant protection des données à caractère personnel',
            'reference_nor' => 'LOI-2023-005',
            'statut' => 'vigueur',
            'type_code' => $loiType->code,
            'institution_id' => $presidence->id,
        ]);

        $titreLoi = $loiDonnees->structureNodes()->create([
            'type_unite' => 'Titre',
            'numero' => 'I',
            'titre' => 'DISPOSITIONS GENERALES',
            'tree_path' => 'TITRE_I',
            'sort_order' => 1,
        ]);

        $artLoi = $loiDonnees->articles()->create([
            'parent_node_id' => $titreLoi->id,
            'numero_article' => '1er',
            'ordre_affichage' => 1,
        ]);

        $artLoi->versions()->create([
            'contenu_texte' => 'La présente loi fixe le cadre juridique de la protection des données à caractère personnel en République du Congo.',
            'validity_period' => ArticleVersion::makeValidityPeriod('2023-05-11'),
            'validation_status' => 'validated',
        ]);

        $artLoi->tags()->sync([$tagJustice->id]);
    }
}
