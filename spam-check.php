<?php
declare(strict_types=1);

/**
 * spam-check.php — reperer les faux comptes clients d'une boutique PrestaShop.
 *
 * LECTURE SEULE : ce script ne modifie ni ne supprime jamais rien.
 * Il liste des comptes suspects avec les raisons, a vous de decider.
 *
 * Installation : deposer ce fichier a la racine de la boutique, puis ouvrir
 * https://votre-boutique.fr/spam-check.php dans un navigateur.
 *
 * Demonstration sans base : https://votre-boutique.fr/spam-check.php?demo=1
 * Ligne de commande : php spam-check.php --demo
 *
 * A SUPPRIMER après usage (bouton prévu dans la page).
 *
 * Licence MIT.
 */

const VERSION = '1.0.0';

/** Score minimum pour qu'un compte soit signale. */
const SEUIL = 3;

/** Nombre d'inscriptions dans la meme heure a partir duquel on parle de rafale. */
const RAFALE_PAR_HEURE = 10;

/** Garde-fou : nombre maximum de clients charges. */
const LIMITE_CLIENTS = 50000;

const JETABLES = [
    'yopmail.com', 'yopmail.fr', 'mailinator.com', 'guerrillamail.com', 'guerrillamail.net',
    'temp-mail.org', 'tempmail.com', 'trashmail.com', 'sharklasers.com', '10minutemail.com',
    'maildrop.cc', 'getnada.com', 'dispostable.com', 'throwawaymail.com', 'jetable.org',
    'mailnesia.com', 'mohmal.com', 'spam4.me', 'emailondeck.com', 'fakeinbox.com',
];

/* =====================================================================
 * 1. LOGIQUE DE DETECTION (pure, sans acces base : testable en local)
 * ===================================================================== */

/**
 * @param list<array<string,mixed>> $clients
 * @return array{suspects: list<array<string,mixed>>, total: int, heures: array<string,int>}
 */
function analyser(array $clients, int $seuil = SEUIL, int $rafaleMin = RAFALE_PAR_HEURE): array
{
    $parHeure = [];

    foreach ($clients as $client) {
        $heure = substr((string) $client['date_add'], 0, 13);
        $parHeure[$heure] = ($parHeure[$heure] ?? 0) + 1;
    }

    $suspects = [];

    foreach ($clients as $client) {
        $heure = substr((string) $client['date_add'], 0, 13);
        $signaux = signaux($client, $parHeure[$heure] ?? 0, $rafaleMin);
        $score = 0;

        foreach ($signaux as $signal) {
            $score += $signal['poids'];
        }

        if ($score >= $seuil) {
            $client['score'] = $score;
            $client['signaux'] = $signaux;
            $suspects[] = $client;
        }
    }

    usort($suspects, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

    arsort($parHeure);

    return [
        'suspects' => $suspects,
        'total'    => count($clients),
        'heures'   => $parHeure,
    ];
}

/**
 * @param array<string,mixed> $client
 * @return list<array{code: string, poids: int, libelle: string}>
 */
function signaux(array $client, int $inscritsDansLHeure, int $rafaleMin): array
{
    $signaux = [];
    $email = strtolower((string) ($client['email'] ?? ''));
    $prenom = (string) ($client['firstname'] ?? '');
    $nom = (string) ($client['lastname'] ?? '');
    $anniversaire = (string) ($client['birthday'] ?? '');
    $commandes = (int) ($client['orders'] ?? 0);

    if ($inscritsDansLHeure >= $rafaleMin) {
        $signaux[] = [
            'code'    => 'rafale',
            'poids'   => 2,
            'libelle' => sprintf('%d inscriptions dans la meme heure', $inscritsDansLHeure),
        ];
    }

    if (est_jetable($email)) {
        [$local, $domaine] = array_pad(explode('@', $email, 2), 2, '');
        $signaux[] = [
            'code'    => 'jetable',
            'poids'   => 2,
            'libelle' => sprintf('adresse jetable (%s)', $domaine),
        ];
    }

    if (normaliser($prenom) !== '' && normaliser($prenom) === normaliser($nom)) {
        $signaux[] = [
            'code'    => 'prenom_nom_identiques',
            'poids'   => 2,
            'libelle' => 'prenom et nom identiques',
        ];
    }

    if (nom_suspect($prenom) || nom_suspect($nom)) {
        $signaux[] = [
            'code'    => 'nom_suspect',
            'poids'   => 2,
            'libelle' => 'nom improbable (lien, chiffres ou suite de consonnes)',
        ];
    }

    if (email_technique($email)) {
        $signaux[] = [
            'code'    => 'email_technique',
            'poids'   => 2,
            'libelle' => 'adresse de test ou technique',
        ];
    }

    if ($anniversaire === '' || str_starts_with($anniversaire, '0000')) {
        $signaux[] = [
            'code'    => 'anniversaire_vide',
            'poids'   => 1,
            'libelle' => 'anniversaire non renseigne',
        ];
    }

    if ($commandes === 0) {
        $signaux[] = [
            'code'    => 'sans_commande',
            'poids'   => 1,
            'libelle' => 'aucune commande',
        ];
    }

    return $signaux;
}

function est_jetable(string $email): bool
{
    $domaine = substr(strrchr($email, '@') ?: '', 1);

    return $domaine !== '' && in_array($domaine, JETABLES, true);
}

function email_technique(string $email): bool
{
    if (!str_contains($email, '@')) {
        return false;
    }

    [$local, $domaine] = explode('@', $email, 2);

    $locaux = ['test', 'test1', 'admin', 'administrator', 'demo', 'example'];
    $domaines = ['test.com', 'test.fr', 'example.com', 'example.org', 'localhost'];

    return in_array($local, $locaux, true) || in_array($domaine, $domaines, true);
}

function nom_suspect(string $valeur): bool
{
    $valeur = trim($valeur);

    if ($valeur === '') {
        return false;
    }

    if (preg_match('~https?://|www\.~i', $valeur) === 1) {
        return true;
    }

    if (preg_match('~\d~', $valeur) === 1) {
        return true;
    }

    if (mb_strlen_compat($valeur) > 30) {
        return true;
    }

    foreach (preg_split('~[\s\-]+~u', $valeur) ?: [] as $mot) {
        if (mb_strlen_compat($mot) >= 5 && preg_match('~[aeiouyàâäéèêëîïôöùûü]~iu', $mot) !== 1) {
            return true;
        }
    }

    return false;
}

function normaliser(string $valeur): string
{
    $valeur = trim($valeur);
    $valeur = function_exists('mb_strtolower') ? mb_strtolower($valeur, 'UTF-8') : strtolower($valeur);

    return preg_replace('~[^a-z0-9]~', '', $valeur) ?? $valeur;
}

function mb_strlen_compat(string $valeur): int
{
    return function_exists('mb_strlen') ? mb_strlen($valeur, 'UTF-8') : strlen($valeur);
}

/* =====================================================================
 * 2. CHARGEMENT DES DONNEES
 * ===================================================================== */

/** @return list<array<string,mixed>> */
function charger_demo(): array
{
    $fichier = __DIR__ . '/demo/customers.json';

    if (!is_file($fichier)) {
        throw new RuntimeException('demo/customers.json introuvable.');
    }

    $data = json_decode((string) file_get_contents($fichier), true);

    if (!is_array($data)) {
        throw new RuntimeException('demo/customers.json illisible.');
    }

    return $data;
}

/**
 * Lit les identifiants de la base directement dans la configuration
 * de PrestaShop, pour eviter toute saisie a l'utilisateur.
 *
 * @return array{host:string,port:string,name:string,user:string,pass:string,prefix:string}
 */
function config_boutique(): array
{
    $parametres = __DIR__ . '/app/config/parameters.php';

    if (is_file($parametres)) {
        $data = include $parametres;
        $p = is_array($data) && isset($data['parameters']) ? $data['parameters'] : $data;

        if (is_array($p) && isset($p['database_name'])) {
            return [
                'host'   => (string) ($p['database_host'] ?? 'localhost'),
                'port'   => (string) ($p['database_port'] ?? ''),
                'name'   => (string) $p['database_name'],
                'user'   => (string) ($p['database_user'] ?? ''),
                'pass'   => (string) ($p['database_password'] ?? ''),
                'prefix' => (string) ($p['database_prefix'] ?? 'ps_'),
            ];
        }
    }

    $settings = __DIR__ . '/config/settings.inc.php';

    if (is_file($settings)) {
        include $settings;

        if (defined('_DB_NAME_')) {
            return [
                'host'   => defined('_DB_SERVER_') ? (string) _DB_SERVER_ : 'localhost',
                'port'   => '',
                'name'   => (string) _DB_NAME_,
                'user'   => defined('_DB_USER_') ? (string) _DB_USER_ : '',
                'pass'   => defined('_DB_PASSWD_') ? (string) _DB_PASSWD_ : '',
                'prefix' => defined('_DB_PREFIX_') ? (string) _DB_PREFIX_ : 'ps_',
            ];
        }
    }

    throw new RuntimeException(
        "Configuration PrestaShop introuvable. Placez ce fichier à la racine de la boutique."
    );
}

/** @return list<array<string,mixed>> */
function charger_base(): array
{
    $c = config_boutique();
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $c['host'], $c['name']);

    if ($c['port'] !== '') {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['name']);
    }

    $pdo = new PDO($dsn, $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $p = $c['prefix'];

    $sql = sprintf(
        "SELECT c.id_customer, c.email, c.firstname, c.lastname, c.date_add, c.birthday, c.active,
                COUNT(o.id_order) AS orders
         FROM %1\$scustomer c
         LEFT JOIN %1\$sorders o ON o.id_customer = c.id_customer
         GROUP BY c.id_customer, c.email, c.firstname, c.lastname, c.date_add, c.birthday, c.active
         ORDER BY c.date_add DESC
         LIMIT %2\$d",
        $p,
        LIMITE_CLIENTS
    );

    return $pdo->query($sql)->fetchAll();
}

/* =====================================================================
 * 3. RENDU
 * ===================================================================== */

/** @param array{suspects: list<array<string,mixed>>, total: int, heures: array<string,int>} $resultat */
function rendre_texte(array $resultat): string
{
    $lignes = [];
    $pourcent = $resultat['total'] > 0 ? round(count($resultat['suspects']) / $resultat['total'] * 100) : 0;

    $lignes[] = sprintf(
        '%d comptes analyses, %d suspects (%d %%)',
        $resultat['total'],
        count($resultat['suspects']),
        $pourcent
    );
    $lignes[] = str_repeat('-', 100);

    foreach ($resultat['suspects'] as $s) {
        $libelles = array_map(static fn (array $x): string => $x['libelle'], $s['signaux']);
        $lignes[] = sprintf(
            '%-6s %-34s %-19s cmd:%-3d score:%-2d %s',
            $s['id_customer'],
            mb_strimwidth_compat((string) $s['email'], 34),
            (string) $s['date_add'],
            (int) $s['orders'],
            (int) $s['score'],
            implode(' | ', $libelles)
        );
    }

    return implode("\n", $lignes) . "\n";
}

function mb_strimwidth_compat(string $valeur, int $largeur): string
{
    return strlen($valeur) > $largeur ? substr($valeur, 0, $largeur - 1) . '~' : $valeur;
}

/** @param array{suspects: list<array<string,mixed>>, total: int, heures: array<string,int>} $resultat */
function rendre_csv(array $resultat): string
{
    $flux = fopen('php://temp', 'r+');
    fputcsv($flux, ['id_customer', 'email', 'prenom', 'nom', 'date_inscription', 'commandes', 'score', 'signaux'], ',', '"', '\\');

    foreach ($resultat['suspects'] as $s) {
        fputcsv($flux, [
            $s['id_customer'],
            $s['email'],
            $s['firstname'],
            $s['lastname'],
            $s['date_add'],
            $s['orders'],
            $s['score'],
            implode(', ', array_column($s['signaux'], 'libelle')),
        ], ',', '"', '\\');
    }

    rewind($flux);
    $contenu = (string) stream_get_contents($flux);
    fclose($flux);

    return $contenu;
}

/* =====================================================================
 * 4. POINT D'ENTREE
 * ===================================================================== */

/* Le fichier peut etre inclus (tests) : on ne lance l'entree que si c'est
   bien lui qu'on execute. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== realpath(__FILE__)) {
    return;
}

$estCli = PHP_SAPI === 'cli';

if ($estCli) {
    $demo = in_array('--demo', $argv, true);

    try {
        $clients = $demo ? charger_demo() : charger_base();
    } catch (Throwable $e) {
        fwrite(STDERR, 'Erreur : ' . $e->getMessage() . "\n");
        exit(1);
    }

    echo rendre_texte(analyser($clients));
    exit(0);
}

/* ---------- mode web ---------- */
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow', true);

$demo = isset($_GET['demo']);
$action = $_POST['action'] ?? null;
$erreur = null;
$resultat = null;
$supprime = false;

if (($action === 'supprimer' || isset($_GET['supprimer'])) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $supprime = @unlink(__FILE__);
}

if ($action === 'analyser' || isset($_GET['csv']) || ($demo && !$supprime)) {
    try {
        $clients = $demo ? charger_demo() : charger_base();
        $resultat = analyser($clients);
    } catch (Throwable $e) {
        $erreur = $e->getMessage();
    }
}

if (isset($_GET['csv']) && $resultat !== null) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="comptes-suspects.csv"');
    echo rendre_csv($resultat);
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Comptes clients suspects - PrestaShop</title>
<style>
  body { font: 15px/1.55 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
         margin: 0; padding: 2rem 1.25rem; background: #f6f7f9; color: #1f2933; }
  main { max-width: 1040px; margin: 0 auto; }
  h1 { font-size: 1.45rem; margin: 0 0 .3rem; }
  .sub { color: #6b7684; margin: 0 0 1.75rem; }
  .card { background: #fff; border: 1px solid #e2e6ea; border-radius: 10px; padding: 1.25rem 1.4rem; margin-bottom: 1rem; }
  .btn { display: inline-block; border: 0; border-radius: 7px; padding: .65rem 1.1rem;
         font-size: .95rem; font-weight: 600; cursor: pointer; text-decoration: none; }
  .btn-main { background: #1a7f5a; color: #fff; }
  .btn-ghost { background: #eef1f4; color: #333d47; }
  .btn-danger { background: #b42318; color: #fff; }
  .kpi { display: flex; gap: 2rem; flex-wrap: wrap; margin: 0 0 1rem; }
  .kpi div { min-width: 120px; }
  .kpi strong { display: block; font-size: 1.9rem; line-height: 1.1; }
  .kpi span { color: #6b7684; font-size: .85rem; }
  table { width: 100%; border-collapse: collapse; font-size: .88rem; table-layout: fixed; }
  th, td { text-align: left; padding: .55rem .6rem; border-bottom: 1px solid #eef1f4; vertical-align: top; }
  th { font-size: .78rem; text-transform: uppercase; letter-spacing: .04em; color: #6b7684; }
  td.mail { word-break: break-all; }
  .tag { display: inline-block; background: #fdf0ef; color: #b42318; border-radius: 4px;
         padding: .06rem .34rem; font-size: .75rem; margin: 0 .18rem .18rem 0; white-space: nowrap; }
  .score { font-weight: 700; }
  .warn { background: #fff8e6; border-color: #f3d99b; }
  .ok { background: #eefaf3; border-color: #bfe6d0; }
  .err { background: #fdf0ef; border-color: #f0c2bd; }
  code { background: #eef1f4; padding: .1rem .3rem; border-radius: 4px; }
  .foot { color: #6b7684; font-size: .82rem; margin-top: 1.5rem; }
</style>
</head>
<body>
<main>
  <h1>Comptes clients suspects</h1>
  <p class="sub">Détection des faux comptes d'une boutique PrestaShop. Aucune donnée modifiée : vous gardez la main.</p>

<?php if ($supprime): ?>
  <div class="card ok">
    <strong>Le fichier a été supprimé.</strong>
    <p>Rien d'autre à faire. Si vous en avez encore besoin, renvoyez-le par FTP.</p>
  </div>
<?php elseif ($erreur !== null && !$demo): ?>
  <div class="card err">
    <strong>Impossible de lire la base de données</strong>
    <p><?= htmlspecialchars($erreur, ENT_QUOTES, 'UTF-8') ?></p>
    <p>Vérifiez que ce fichier est bien à la racine de la boutique, à côté du dossier <code>app</code> ou <code>config</code>.</p>
    <p><a class="btn btn-ghost" href="?demo=1">Voir d'abord une démonstration avec des données fictives</a></p>
  </div>
<?php elseif ($resultat === null): ?>
  <div class="card warn">
    <strong>Avant de lancer</strong>
    <p>L'analyse lit uniquement les tables <code>customer</code> et <code>orders</code>. Aucune écriture.
       Aucune donnée n'est envoyée ailleurs : tout se passe sur votre serveur.</p>
    <p><strong>Supprimez ce fichier après usage</strong> : il donne accès à la liste de vos clients.</p>
    <form method="post">
      <input type="hidden" name="action" value="analyser">
      <button class="btn btn-main" type="submit">Lancer l'analyse</button>
      <a class="btn btn-ghost" href="?demo=1">Voir une démonstration</a>
    </form>
  </div>
<?php else: ?>
  <?php $pourcent = $resultat['total'] > 0 ? round(count($resultat['suspects']) / $resultat['total'] * 100) : 0; ?>
  <div class="card kpi">
    <div><strong><?= $resultat['total'] ?></strong><span>comptes analysés</span></div>
    <div><strong><?= count($resultat['suspects']) ?></strong><span>suspects (<?= $pourcent ?> %)</span></div>
    <?php $pic = array_key_first($resultat['heures']); ?>
    <?php if ($pic !== null): ?>
      <div><strong><?= $resultat['heures'][$pic] ?></strong><span>inscriptions max sur une heure<br>(<?= htmlspecialchars($pic, ENT_QUOTES, 'UTF-8') ?> h)</span></div>
    <?php endif; ?>
  </div>

  <?php if ($demo): ?>
    <div class="card warn"><strong>Démonstration.</strong> Les comptes affichés sont fictifs, générés pour l'exemple.</div>
  <?php endif; ?>

  <div class="card">
    <?php if ($resultat['suspects'] === []): ?>
      <p>Aucun compte suspect avec le seuil actuel (score &ge; <?= SEUIL ?>).</p>
    <?php else: ?>
      <table>
        <colgroup>
          <col style="width:52px"><col style="width:232px"><col style="width:150px">
          <col style="width:142px"><col style="width:48px"><col style="width:58px"><col>
        </colgroup>
        <thead>
          <tr><th>#</th><th>Email</th><th>Nom</th><th>Inscrit le</th><th>Cmd</th><th>Score</th><th>Signaux</th></tr>
        </thead>
        <tbody>
        <?php foreach ($resultat['suspects'] as $s): ?>
          <tr>
            <td><?= (int) $s['id_customer'] ?></td>
            <td class="mail"><?= htmlspecialchars((string) $s['email'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars(trim((string) $s['firstname'] . ' ' . (string) $s['lastname']), ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string) $s['date_add'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= (int) $s['orders'] ?></td>
            <td class="score"><?= (int) $s['score'] ?></td>
            <td>
              <?php foreach ($s['signaux'] as $signal): ?>
                <span class="tag"><?= htmlspecialchars($signal['libelle'], ENT_QUOTES, 'UTF-8') ?></span>
              <?php endforeach; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p class="foot">
        <a class="btn btn-ghost" href="?<?= $demo ? 'demo=1&amp;' : '' ?>csv=1">Exporter en CSV</a>
      </p>
    <?php endif; ?>
  </div>

  <div class="card">
    <strong>Que faire de cette liste ?</strong>
    <p>Ne supprimez pas en masse. Le bon geste est de désactiver le compte (<code>active = 0</code>), d'attendre
       30 jours, puis de supprimer ceux qui n'ont toujours aucune commande. Un vrai client peut s'inscrire sans commander.</p>
    <p>Un compte signalé n'est pas forcément un faux : c'est un compte qui mérite un coup d'œil.</p>
    <p><strong>Ce n'est pas forcément un piratage.</strong> Si vous voyez aussi des pages que vous n'avez pas
       créées, des redirections inconnues ou du texte étranger dans Google, le problème est ailleurs :
       <a href="https://www.adrienmansard.com/prestashop-piratage">voir le guide PrestaShop piraté</a>.</p>
  </div>

  <form method="post" onsubmit="return confirm('Supprimer définitivement ce fichier ?');">
    <input type="hidden" name="action" value="supprimer">
    <button class="btn btn-danger" type="submit">J'ai terminé, supprimer ce fichier</button>
  </form>
<?php endif; ?>

  <p class="foot">spam-check <?= VERSION ?> — lecture seule, aucune donnée envoyée.</p>
  <p class="foot">Des milliers de comptes à traiter, ou des inscriptions qui continuent ?
     <a href="https://www.adrienmansard.com/">Faites-vous accompagner</a>.</p>
</main>
</body>
</html>
