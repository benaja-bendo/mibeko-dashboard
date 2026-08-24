<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/**
 * Remet en place un article qu'un titre perdu au parsing a fait disparaître.
 *
 * MinerU rend parfois l'en-tête d'un article illisible pour le parseur : soit
 * océrisé (« ## Articie 22 », l → i — le mot n'est plus reconnu par
 * ARTICLE_PATTERN), soit purement absent (l'acte OHADA transport n'a plus de
 * ligne « Article 28 », seule subsiste sa rubrique en paragraphe nu). Les deux
 * accidents ont la même conséquence en base : l'article n'existe pas. Mais pas
 * le même sort pour son texte, d'où les deux sources de restitution :
 *
 *  - `scission` : le texte a été absorbé par l'article précédent, qui porte
 *    donc deux articles bout à bout. On le coupe au marqueur et on rend sa
 *    seconde moitié au numéro qui lui revient. Rien n'est réécrit à la main.
 *  - `texte` : le texte n'est nulle part en base (le parseur l'a laissé sous
 *    un nœud de structure, hors de tout article). Il est relu dans le `.md`
 *    MinerU et fourni dans le lot — jamais généré, jamais deviné.
 *
 * Le canal est l'API dans les deux cas, jamais du SQL : `PATCH /articles/{id}`
 * versionne la troncature du voisin (ferme la version active, en ouvre une
 * nouvelle) et `POST /articles` décale la fratrie pour libérer le rang, ce
 * qu'un INSERT laisserait à deux articles au même `ordre_affichage`.
 *
 * Garde-fous, tous bloquants et vérifiés avant le moindre appel réseau :
 * le marqueur doit apparaître exactement une fois dans le texte du voisin
 * (zéro = lot périmé, deux = coupe ambiguë), aucune des deux moitiés ne peut
 * être vide, et le numéro à créer ne doit pas déjà exister dans le document.
 *
 *   export MIBEKO_API_TOKEN='…'
 *   php artisan mibeko:restituer-article --lot=articles-perdus.json          # simulation
 *   php artisan mibeko:restituer-article --lot=articles-perdus.json --execute
 */
class RestituerArticleCommand extends Command
{
    private const TENTATIVES_MAX = 4;

    private const ATTENTE_MAX_SECONDES = 60;

    protected $signature = 'mibeko:restituer-article
        {--lot= : Fichier JSON [{source, article_id|apres_article_id, numero_nouveau, marqueur|content, motif}, …]}
        {--connection= : Connexion lue pour le diagnostic (défaut : connexion par défaut)}
        {--base-url=http://127.0.0.1:8000/api/v1 : Racine de l\'API visée}
        {--rythme=30 : Articles par minute (quota API 60 req/min, 2 appels par scission)}
        {--execute : Écrit réellement. Sans cette option, simulation seule.}';

    protected $description = 'Restitue un article perdu au parsing, par scission du voisin ou insertion d\'un texte relu (lecture seule par défaut).';

    public function handle(): int
    {
        $chemin = (string) $this->option('lot');

        if ($chemin === '' || ! is_readable($chemin)) {
            $this->error('Option --lot obligatoire : chemin d\'un fichier JSON lisible.');

            return self::FAILURE;
        }

        $entrees = json_decode((string) file_get_contents($chemin), true);

        if (! is_array($entrees) || $entrees === []) {
            $this->error('Le lot est vide ou n\'est pas un tableau JSON.');

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        $jeton = (string) env('MIBEKO_API_TOKEN', '');

        if ($execute && $jeton === '') {
            $this->error('MIBEKO_API_TOKEN absent du shell. À exporter à la main, jamais dans un fichier.');

            return self::FAILURE;
        }

        $plans = [];
        $refus = [];

        foreach ($entrees as $rang => $entree) {
            $resultat = $this->preparer(is_array($entree) ? $entree : [], $rang);

            if (isset($resultat['refus'])) {
                $refus[] = [$resultat['label'], $resultat['refus']];

                continue;
            }

            $plans[] = $resultat;
        }

        $this->afficherLePlan($plans, $refus);

        // Un lot dont une entrée est refusée n'est jamais exécuté à moitié : le
        // refus signale que le lot ne décrit plus la base qu'il prétend corriger
        // (texte déjà réparé, marqueur déplacé), et les entrées restantes
        // reposent sur la même lecture, désormais suspecte.
        if ($refus !== []) {
            $this->newLine();
            $this->error(count($refus).' entrée(s) refusée(s) — lot non exécuté. Corriger le lot puis relancer.');

            return self::FAILURE;
        }

        if (! $execute) {
            $this->newLine();
            $this->info(count($plans).' article(s) seraient restitués sur '.$this->baseUrl().'.');
            $this->warn('SIMULATION — aucun appel réseau émis. Ajouter --execute pour écrire.');

            return self::SUCCESS;
        }

        return $this->executer($plans, $jeton);
    }

    /**
     * Vérifie une entrée du lot contre la base et calcule les deux textes.
     *
     * @param  array<string, mixed>  $entree
     * @return array<string, mixed>
     */
    private function preparer(array $entree, int|string $rang): array
    {
        $source = (string) ($entree['source'] ?? '');
        $numero = trim((string) ($entree['numero_nouveau'] ?? ''));
        $label = (string) ($entree['document'] ?? "entrée #{$rang}");
        $label = $numero === '' ? $label : "{$label} — art. {$numero}";

        if (! in_array($source, ['scission', 'texte'], true)) {
            return ['label' => $label, 'refus' => 'source inconnue (attendu : scission ou texte)'];
        }

        if ($numero === '') {
            return ['label' => $label, 'refus' => 'numero_nouveau manquant'];
        }

        $referenceId = (string) ($source === 'scission'
            ? ($entree['article_id'] ?? '')
            : ($entree['apres_article_id'] ?? ''));

        if ($referenceId === '') {
            return ['label' => $label, 'refus' => $source === 'scission'
                ? 'article_id manquant'
                : 'apres_article_id manquant'];
        }

        $reference = $this->lireArticle($referenceId);

        if ($reference === null) {
            return ['label' => $label, 'refus' => "article de référence introuvable ({$referenceId})"];
        }

        if ($this->numeroExiste($reference->document_id, $numero)) {
            return ['label' => $label, 'refus' => "l'article {$numero} existe déjà dans ce document"];
        }

        $plan = [
            'label' => $label,
            'source' => $source,
            'motif' => (string) ($entree['motif'] ?? ''),
            'numero' => $numero,
            'document_id' => $reference->document_id,
            'parent_node_id' => $reference->parent_node_id,
            'ordre_affichage' => (int) $reference->ordre_affichage + 1,
            'reference_id' => $referenceId,
            'reference_numero' => (string) $reference->numero_article,
            'reference_taille_avant' => mb_strlen((string) $reference->contenu_texte),
        ];

        if ($source === 'texte') {
            $contenu = trim((string) ($entree['content'] ?? ''));

            if ($contenu === '') {
                return ['label' => $label, 'refus' => 'content vide'];
            }

            return $plan + [
                'contenu_nouveau' => $contenu,
                'contenu_reference' => null,
                'reference_taille_apres' => $plan['reference_taille_avant'],
            ];
        }

        $marqueur = (string) ($entree['marqueur'] ?? '');

        if ($marqueur === '') {
            return ['label' => $label, 'refus' => 'marqueur manquant'];
        }

        $texte = (string) $reference->contenu_texte;
        $occurrences = mb_substr_count($texte, $marqueur);

        if ($occurrences === 0) {
            return ['label' => $label, 'refus' => 'marqueur absent du texte de l\'article de référence'];
        }

        if ($occurrences > 1) {
            return ['label' => $label, 'refus' => "marqueur trouvé {$occurrences} fois — coupe ambiguë"];
        }

        $position = mb_strpos($texte, $marqueur);
        $avant = rtrim(mb_substr($texte, 0, $position));
        $apres = trim(mb_substr($texte, $position));

        if ($avant === '') {
            return ['label' => $label, 'refus' => 'la coupe viderait l\'article de référence'];
        }

        if ($apres === '') {
            return ['label' => $label, 'refus' => 'la coupe ne laisse aucun texte au nouvel article'];
        }

        return $plan + [
            'contenu_nouveau' => $apres,
            'contenu_reference' => $avant,
            'reference_taille_apres' => mb_strlen($avant),
        ];
    }

    private function lireArticle(string $id): ?object
    {
        return $this->connexion()
            ->table('articles as a')
            ->leftJoin('article_versions as av', function ($jointure) {
                $jointure->on('av.article_id', '=', 'a.id')
                    ->whereRaw('upper_inf(av.validity_period)');
            })
            ->where('a.id', $id)
            ->whereNull('a.deleted_at')
            ->select([
                'a.document_id',
                'a.parent_node_id',
                'a.numero_article',
                'a.ordre_affichage',
                'av.contenu_texte',
            ])
            ->first();
    }

    /**
     * Rang courant de l'article de référence, relu au moment d'écrire.
     */
    private function rangActuel(string $id): ?int
    {
        $rang = $this->connexion()
            ->table('articles')
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->value('ordre_affichage');

        return $rang === null ? null : (int) $rang;
    }

    private function numeroExiste(string $documentId, string $numero): bool
    {
        return $this->connexion()
            ->table('articles')
            ->where('document_id', $documentId)
            ->where('numero_article', $numero)
            ->whereNull('deleted_at')
            ->exists();
    }

    private function connexion(): ConnectionInterface
    {
        $nom = (string) $this->option('connection');

        return DB::connection($nom === '' ? null : $nom);
    }

    private function baseUrl(): string
    {
        return rtrim((string) $this->option('base-url'), '/');
    }

    /**
     * @param  array<int, array<string, mixed>>  $plans
     * @param  array<int, array<int, string>>  $refus
     */
    private function afficherLePlan(array $plans, array $refus): void
    {
        if ($plans !== []) {
            $lignes = [];

            foreach ($plans as $plan) {
                $lignes[] = [
                    $plan['label'],
                    $plan['source'],
                    $plan['reference_numero'].' : '.$plan['reference_taille_avant'].' → '.$plan['reference_taille_apres'].' car.',
                    mb_strlen((string) $plan['contenu_nouveau']).' car.',
                    Str::limit((string) $plan['contenu_nouveau'], 60),
                ];
            }

            $this->table(
                ['Article restitué', 'Source', 'Voisin (avant → après)', 'Taille', 'Début du texte restitué'],
                $lignes
            );
        }

        if ($refus !== []) {
            $this->newLine();
            $this->table(['Entrée refusée', 'Motif'], $refus);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $plans
     */
    private function executer(array $plans, string $jeton): int
    {
        $rythme = max(0, (int) $this->option('rythme'));
        $intervalle = $rythme > 0 ? 60 / $rythme : 0.0;
        $total = count($plans);
        $restitues = 0;
        $echecs = [];
        $rang = 0;

        foreach ($plans as $plan) {
            $rang++;
            $debut = microtime(true);
            $avancement = sprintf('[%d/%d]', $rang, $total);

            // Le rang se relit JUSTE AVANT d'écrire, jamais celui du plan :
            // chaque création décale la fratrie (`POST /articles` incrémente
            // l'ordre de tous les frères situés au rang visé et au-delà), donc
            // dès la deuxième entrée d'un même document le rang calculé au plan
            // désigne une autre place. Deux articles restitués dans le même
            // document sortaient ainsi dans le désordre.
            $rang = $this->rangActuel((string) $plan['reference_id']);

            if ($rang === null) {
                $echecs[] = [Str::limit((string) $plan['label'], 50), 'article de référence introuvable au moment d\'écrire'];
                $this->line("{$avancement} ✗ {$plan['label']} — référence disparue");

                continue;
            }

            // Création AVANT troncature, jamais l'inverse : les deux appels ne
            // partagent pas de transaction, donc il faut choisir quel demi-échec
            // on préfère. Créer d'abord laisse, au pire, le texte en double —
            // visible et corrigeable. Tronquer d'abord le perdrait si la
            // création échouait ensuite.
            $creation = $this->appeler($jeton, 'post', '/articles', [
                'document_id' => $plan['document_id'],
                'parent_node_id' => $plan['parent_node_id'],
                'numero_article' => $plan['numero'],
                'content' => $plan['contenu_nouveau'],
                'ordre_affichage' => $rang + 1,
                'validation_status' => 'pending',
            ]);

            if ($creation === null || ! $creation) {
                $echecs[] = [Str::limit((string) $plan['label'], 50), 'création refusée par l\'API'];
                $this->line("{$avancement} ✗ {$plan['label']} — création refusée");

                continue;
            }

            if ($plan['contenu_reference'] !== null) {
                $troncature = $this->appeler($jeton, 'patch', '/articles/'.$plan['reference_id'], [
                    'content' => $plan['contenu_reference'],
                ]);

                if ($troncature === null || ! $troncature) {
                    $echecs[] = [
                        Str::limit((string) $plan['label'], 50),
                        'article créé mais voisin '.$plan['reference_numero'].' NON tronqué — texte en double',
                    ];
                    $this->line("{$avancement} ⚠ {$plan['label']} — créé, voisin non tronqué");

                    continue;
                }
            }

            $restitues++;
            $this->line("{$avancement} ✓ {$plan['label']}");

            $this->respirer($intervalle, $debut, $rang, $total);
        }

        $this->newLine();
        $this->info("{$restitues}/{$total} article(s) restitué(s).");

        if ($echecs !== []) {
            $this->table(['Échec', 'Motif'], $echecs);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $charge
     */
    private function appeler(string $jeton, string $verbe, string $chemin, array $charge): ?bool
    {
        $url = $this->baseUrl().$chemin;

        for ($tentative = 1; $tentative <= self::TENTATIVES_MAX; $tentative++) {
            try {
                $reponse = Http::withToken($jeton)
                    ->acceptJson()
                    ->timeout(30)
                    ->{$verbe}($url, $charge);
            } catch (ConnectionException $e) {
                $this->warn('  connexion impossible : '.$e->getMessage());

                return null;
            }

            if ($reponse->status() !== 429) {
                if ($reponse->successful()) {
                    return true;
                }

                $this->warn('  '.$reponse->status().' — '.Str::limit($reponse->body(), 200));

                return false;
            }

            $attente = min(self::ATTENTE_MAX_SECONDES, (int) ($reponse->header('Retry-After') ?: 2 ** $tentative));
            $this->warn("  429 reçu, reprise dans {$attente}s (tentative {$tentative}/".self::TENTATIVES_MAX.')');
            Sleep::sleep($attente);
        }

        return false;
    }

    private function respirer(float $intervalle, float $debut, int $rang, int $total): void
    {
        if ($intervalle <= 0 || $rang >= $total) {
            return;
        }

        $reste = $intervalle - (microtime(true) - $debut);

        if ($reste > 0) {
            Sleep::usleep((int) ($reste * 1_000_000));
        }
    }
}
