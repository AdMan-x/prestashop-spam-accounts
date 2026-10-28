-- =====================================================================
-- Schema minimal pour tester spam-check.php sans boutique reelle.
--
-- Ne contient que les colonnes utilisees par le script, avec des types
-- identiques a ceux de PrestaShop 1.7 / 8 / 9. Ce n'est PAS un schema
-- PrestaShop complet : il ne sert qu'aux tests.
--
--   mysql -u root -e "CREATE DATABASE ps_test"
--   mysql -u root ps_test < tests/schema.sql
--   mysql -u root ps_test < tests/seed.sql
-- =====================================================================

DROP TABLE IF EXISTS ps_customer;
DROP TABLE IF EXISTS ps_orders;

CREATE TABLE ps_customer (
    id_customer       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_shop_group     INT UNSIGNED NOT NULL DEFAULT 1,
    id_shop           INT UNSIGNED NOT NULL DEFAULT 1,
    id_gender         TINYINT UNSIGNED NOT NULL DEFAULT 0,
    id_default_group  INT UNSIGNED NOT NULL DEFAULT 3,
    id_lang           INT UNSIGNED NOT NULL DEFAULT 1,
    id_risk           TINYINT UNSIGNED NOT NULL DEFAULT 0,
    company           VARCHAR(255) NOT NULL DEFAULT '',
    siret             VARCHAR(14)  NOT NULL DEFAULT '',
    ape               VARCHAR(5)   NOT NULL DEFAULT '',
    firstname         VARCHAR(255) NOT NULL,
    lastname          VARCHAR(255) NOT NULL,
    email             VARCHAR(255) NOT NULL,
    passwd            VARCHAR(255) NOT NULL,
    last_passwd_gen   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    birthday          DATE         NULL,
    newsletter        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ip_registration_newsletter VARCHAR(15) NOT NULL DEFAULT '',
    newsletter_date_add DATETIME   NULL,
    optin             TINYINT UNSIGNED NOT NULL DEFAULT 0,
    website           VARCHAR(128) NOT NULL DEFAULT '',
    outstanding_allow_amount DECIMAL(20,6) NOT NULL DEFAULT 0.000000,
    show_public_prices TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_payment_days  INT UNSIGNED NOT NULL DEFAULT 0,
    secure_key        VARCHAR(32)  NOT NULL DEFAULT '-1',
    note              TEXT,
    active            TINYINT UNSIGNED NOT NULL DEFAULT 1,
    is_guest          TINYINT UNSIGNED NOT NULL DEFAULT 0,
    deleted           TINYINT UNSIGNED NOT NULL DEFAULT 0,
    date_add          DATETIME     NOT NULL,
    date_upd          DATETIME     NOT NULL,
    PRIMARY KEY (id_customer),
    UNIQUE KEY customer_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ps_orders (
    id_order          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_customer       INT UNSIGNED NOT NULL DEFAULT 0,
    reference         VARCHAR(9)   NOT NULL DEFAULT '',
    total_paid        DECIMAL(20,6) NOT NULL DEFAULT 0.000000,
    date_add          DATETIME     NOT NULL,
    PRIMARY KEY (id_order),
    KEY id_customer (id_customer)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
