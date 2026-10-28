-- =====================================================================
-- Comptes clients suspects d'une boutique PrestaShop
--
-- A executer dans phpMyAdmin (onglet SQL), Adminer, ou en ligne de commande.
-- LECTURE SEULE : aucune requete ici ne modifie quoi que ce soit.
--
-- IMPORTANT : remplacez ps_ par le prefixe de vos tables s'il est different.
-- Il est visible dans la liste des tables de votre base (Parametres > Base de
-- donnees dans le back-office, ou dans phpMyAdmin).
-- =====================================================================


-- ---------------------------------------------------------------------
-- 1. Vue d'ensemble : combien de comptes au total, combien sans commande
-- ---------------------------------------------------------------------
SELECT
    COUNT(*)                                              AS clients_total,
    SUM(CASE WHEN o.nb IS NULL THEN 1 ELSE 0 END)         AS sans_commande
FROM ps_customer c
LEFT JOIN (
    SELECT id_customer, COUNT(*) AS nb
    FROM ps_orders
    GROUP BY id_customer
) o ON o.id_customer = c.id_customer;


-- ---------------------------------------------------------------------
-- 2. Le signal le plus fiable : les rafales d'inscriptions
--    Une heure avec plus de 10 inscriptions est presque toujours un bot.
--    Regardez d'abord cette requete : elle suffit souvent a tout comprendre.
-- ---------------------------------------------------------------------
SELECT
    DATE_FORMAT(date_add, '%Y-%m-%d %H:00') AS heure,
    COUNT(*)                                AS inscriptions
FROM ps_customer
GROUP BY heure
HAVING inscriptions >= 10
ORDER BY inscriptions DESC
LIMIT 20;


-- ---------------------------------------------------------------------
-- 3. Les comptes crees pendant ces rafales, avec le nombre de commandes
-- ---------------------------------------------------------------------
SELECT
    c.id_customer,
    c.email,
    CONCAT(c.firstname, ' ', c.lastname) AS nom,
    c.date_add,
    COUNT(o.id_order)                    AS commandes
FROM ps_customer c
LEFT JOIN ps_orders o ON o.id_customer = c.id_customer
WHERE DATE_FORMAT(c.date_add, '%Y-%m-%d %H:00') IN (
    SELECT heure FROM (
        SELECT DATE_FORMAT(date_add, '%Y-%m-%d %H:00') AS heure
        FROM ps_customer
        GROUP BY heure
        HAVING COUNT(*) >= 10
    ) AS rafales
)
GROUP BY c.id_customer, c.email, c.firstname, c.lastname, c.date_add
ORDER BY c.date_add;


-- ---------------------------------------------------------------------
-- 4. Adresses email jetables
--    Liste volontairement courte : ajoutez les domaines que vous voyez
--    revenir dans vos propres inscriptions.
-- ---------------------------------------------------------------------
SELECT id_customer, email, date_add
FROM ps_customer
WHERE email REGEXP '(yopmail|mailinator|guerrillamail|temp-mail|tempmail|trashmail|sharklasers|10minutemail|maildrop|getnada|dispostable|throwawaymail|jetable|mailnesia|mohmal|spam4\\.me|fakeinbox)'
ORDER BY date_add DESC;


-- ---------------------------------------------------------------------
-- 5. Prenom identique au nom (signature des inscriptions automatiques)
-- ---------------------------------------------------------------------
SELECT id_customer, email, firstname, lastname, date_add
FROM ps_customer
WHERE LOWER(TRIM(firstname)) = LOWER(TRIM(lastname))
  AND LENGTH(TRIM(firstname)) > 2
ORDER BY date_add DESC;


-- ---------------------------------------------------------------------
-- 6. REQUETE PRINCIPALE : le score
--    Chaque signal ajoute des points ; a partir de 3, le compte merite
--    un coup d'oeil. Les poids sont ceux de spam-check.php.
-- ---------------------------------------------------------------------
SELECT
    c.id_customer,
    c.email,
    CONCAT(c.firstname, ' ', c.lastname) AS nom,
    c.date_add,
    COALESCE(o.nb, 0) AS commandes,
    (CASE WHEN r.nb >= 10 THEN 2 ELSE 0 END)                                      -- rafale
  + (CASE WHEN c.email REGEXP '(yopmail|mailinator|guerrillamail|temp-mail|tempmail|trashmail|mailnesia|mohmal|fakeinbox)' THEN 2 ELSE 0 END)  -- jetable
  + (CASE WHEN LOWER(TRIM(c.firstname)) = LOWER(TRIM(c.lastname)) AND LENGTH(TRIM(c.firstname)) > 2 THEN 2 ELSE 0 END)  -- prenom = nom
  + (CASE WHEN c.lastname REGEXP 'https?://|www\\.' OR c.lastname REGEXP '[0-9]' THEN 2 ELSE 0 END)  -- nom suspect
  + (CASE WHEN c.email REGEXP '^(test|test1|admin|demo)@' OR c.email REGEXP '@(test|example)\\.' THEN 2 ELSE 0 END)   -- adresse technique
  + (CASE WHEN c.birthday IS NULL OR c.birthday < '1900-01-01' THEN 1 ELSE 0 END)   -- anniversaire vide (NULL, ou date zero sur les vieilles boutiques)
  + (CASE WHEN COALESCE(o.nb, 0) = 0 THEN 1 ELSE 0 END)                          -- aucune commande
    AS score
FROM ps_customer c
LEFT JOIN (
    SELECT id_customer, COUNT(*) AS nb
    FROM ps_orders
    GROUP BY id_customer
) o ON o.id_customer = c.id_customer
LEFT JOIN (
    SELECT DATE_FORMAT(date_add, '%Y-%m-%d %H:00') AS heure, COUNT(*) AS nb
    FROM ps_customer
    GROUP BY heure
) r ON r.heure = DATE_FORMAT(c.date_add, '%Y-%m-%d %H:00')
HAVING score >= 3
ORDER BY score DESC, c.date_add DESC
LIMIT 500;


-- =====================================================================
-- ET APRES ?
--
-- NE SUPPRIMEZ PAS EN MASSE. Un vrai client peut s'inscrire sans commander :
-- l'erreur est irreversible, et vous perdez un client, pas un bot.
--
-- Le bon geste, en deux temps :
--
--   1. Desactiver (reversible) :
--      UPDATE ps_customer SET active = 0 WHERE id_customer IN (...);
--
--   2. Attendre 30 jours. Les comptes toujours sans commande peuvent
--      alors etre supprimes, avec leurs adresses, via le back-office ou
--      le module de nettoyage de votre choix.
--
-- Notez aussi qu'un compte desactive ne peut plus se connecter : si vous
-- vous trompez, la personne vous ecrira et vous reactivez le compte.
-- =====================================================================
