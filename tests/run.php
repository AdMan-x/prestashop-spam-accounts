<?php
declare(strict_types=1);

/**
 * Tests de la logique de detection, sans base de donnees.
 *
 *   php tests/run.php
 *
 * Sortie : une ligne par test, code 0 si tout passe, 1 sinon.
 */

require __DIR__ . '/../spam-check.php';

$echecs = 0;
$total = 0;

function verifier(string $libelle, mixed $obtenu, mixed $attendu): void
{
    global $echecs, $total;
    $total++;
    $ok = $obtenu === $attendu;

    printf("%s %s\n", $ok ? 'OK  ' : 'FAIL', $libelle);

    if (!$ok) {
        $echecs++;
        printf("     attendu : %s\n", var_export($attendu, true));
        printf("     obtenu  : %s\n", var_export($obtenu, true));
    }
}

/* ---------- Jeu de donnees ---------- */
$clients = charger_demo();
$resultat = analyser($clients);

$ids = array_column($resultat['suspects'], 'id_customer');
$parId = [];
foreach ($resultat['suspects'] as $s) {
    $parId[(int) $s['id_customer']] = $s;
}

/* 1. Volume */
verifier('47 comptes charges', count($clients), 47);
verifier('24 comptes signales', count($resultat['suspects']), 24);

/* 2. Aucun faux positif sur les 15 clients legitimes (ids 1 a 15) */
$fauxPositifs = array_filter($ids, static fn ($id) => $id <= 15);
verifier('aucun client legitime signale', $fauxPositifs, []);

/* 3. Aucun faux positif sur les 8 cas limites (ids 16 a 23) :
      0 commande, vrai nom, parfois anniversaire vide */
$limites = array_filter($ids, static fn ($id) => $id >= 16 && $id <= 23);
verifier('aucun cas limite signale (seuil = 3)', $limites, []);

/* 4. La rafale est bien detectee */
verifier('22 inscriptions dans la meme heure', $resultat['heures']['2026-10-14 03'], 22);

/* 5. Signaux attendus sur des comptes precis */
verifier(
    'compte jetable signale comme jetable',
    in_array('jetable', array_column($parId[25]['signaux'], 'code'), true),
    true
);
verifier(
    'test@test.com signale comme adresse technique',
    in_array('email_technique', array_column($parId[46]['signaux'], 'code'), true),
    true
);
verifier(
    'nom contenant une URL signale',
    in_array('nom_suspect', array_column($parId[47]['signaux'], 'code'), true),
    true
);
verifier(
    'prenom et nom identiques signales',
    in_array('prenom_nom_identiques', array_column($parId[24]['signaux'], 'code'), true),
    true
);

/* 6. Le tri place les scores les plus hauts en premier */
$scores = array_column($resultat['suspects'], 'score');
$tri = $scores;
rsort($tri);
verifier('suspects tries par score decroissant', $scores, $tri);

/* 7. Anniversaire a la date zero (vieilles boutiques, avant MySQL 8) */
verifier(
    'anniversaire 0000-00-00 detecte comme vide',
    in_array('anniversaire_vide', array_column(signaux([
        'email'     => 'paul.leroy@free.fr',
        'firstname' => 'Paul',
        'lastname'  => 'Leroy',
        'birthday'  => '0000-00-00',
        'orders'    => 2,
    ], 1, 10), 'code'), true),
    true
);

/* 8. Un client sans aucun signal n'est jamais signale */
verifier('client parfait non signale', analyser([[
    'id_customer' => 999,
    'email'       => 'marie.dupont@gmail.com',
    'firstname'   => 'Marie',
    'lastname'    => 'Dupont',
    'date_add'    => '2026-10-01 11:00:00',
    'birthday'    => '1985-04-12',
    'orders'      => 3,
    'active'      => 1,
]])['suspects'], []);

/* 8. Le seuil est bien un plancher */
verifier('seuil 9 ne signale plus personne (score max = 8)', count(analyser($clients, 9)['suspects']), 0);

printf("\n%d tests, %d echec(s)\n", $total, $echecs);

exit($echecs === 0 ? 0 : 1);
