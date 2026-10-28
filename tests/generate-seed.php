<?php
declare(strict_types=1);

/**
 * Genere tests/seed.sql a partir de demo/customers.json, pour que le test
 * d'integration et les tests unitaires portent exactement sur les memes
 * comptes.
 *
 *   php tests/generate-seed.php > tests/seed.sql
 */

$fichier = __DIR__ . '/../demo/customers.json';
$clients = json_decode((string) file_get_contents($fichier), true);

if (!is_array($clients)) {
    fwrite(STDERR, "demo/customers.json illisible\n");
    exit(1);
}

$echapper = static fn (?string $v): string => $v === null ? 'NULL' : "'" . str_replace("'", "''", $v) . "'";

echo "-- Genere par tests/generate-seed.php depuis demo/customers.json\n";
echo "-- Ne pas editer a la main.\n";
echo "-- Note : les anniversaires vides sont stockes en NULL, MySQL 9 refusant\n";
echo "-- la date zero '0000-00-00'. Les boutiques plus anciennes peuvent en\n";
echo "-- contenir, le script gere les deux cas.\n\n";
echo "DELETE FROM ps_orders;\n";
echo "DELETE FROM ps_customer;\n\n";

$lignes = [];
$commandes = [];
$idCommande = 1;

foreach ($clients as $c) {
    $anniversaire = $c['birthday'] ?? null;

    if ($anniversaire === '0000-00-00' || $anniversaire === '') {
        $anniversaireSql = 'NULL';
    } elseif ($anniversaire === null) {
        $anniversaireSql = 'NULL';
    } else {
        $anniversaireSql = $echapper((string) $anniversaire);
    }

    $lignes[] = sprintf(
        '(%d, %s, %s, %s, %s, %s, %d, %s, %s)',
        (int) $c['id_customer'],
        $echapper((string) $c['firstname']),
        $echapper((string) $c['lastname']),
        $echapper((string) $c['email']),
        $echapper(md5((string) $c['email'])),
        $anniversaireSql,
        (int) $c['active'],
        $echapper((string) $c['date_add']),
        $echapper((string) $c['date_add'])
    );

    for ($i = 0; $i < (int) $c['orders']; $i++) {
        $commandes[] = sprintf(
            "(%d, %d, 'REF%06d', 49.900000, %s)",
            $idCommande++,
            (int) $c['id_customer'],
            $idCommande,
            $echapper((string) $c['date_add'])
        );
    }
}

echo "INSERT INTO ps_customer\n"
    . "  (id_customer, firstname, lastname, email, passwd, birthday, active, date_add, date_upd)\n"
    . "VALUES\n  " . implode(",\n  ", $lignes) . ";\n\n";

if ($commandes !== []) {
    echo "INSERT INTO ps_orders (id_order, id_customer, reference, total_paid, date_add)\nVALUES\n  "
        . implode(",\n  ", $commandes) . ";\n";
}

fwrite(STDERR, count($lignes) . " clients, " . count($commandes) . " commandes\n");
