<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use OptionsAPI\{ConfigParser,OptionsAPI,ResponseStatus};

ConfigParser::load(__DIR__ . '/../config/.env');

$api = new OptionsAPI(getenv('API_TOKEN') ?: null);
$api->setFetchMode($api::FETCH_NUM);

$db = new PDO('sqlite:.sqlite3');
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_NUM);

/**
 * Sync local database to OptionsAPI
 */
$db->exec(
    "CREATE TABLE IF NOT EXISTS option_chains (
        symbol TEXT NOT NULL, 
        expiration_date TEXT NOT NULL, 
        options TEXT NOT NULL, 
        last_modified INTEGER NOT NULL, 
        PRIMARY KEY (symbol, expiration_date)
    )"
);

$symbols = array_unique(
    array_merge(
        $api->getSymbols(),
        $db->query("SELECT DISTINCT symbol FROM option_chains")->fetchAll(PDO::FETCH_COLUMN, 0)
    )
);

$select_expiration_dates = $db->prepare(
    "SELECT 
        expiration_date 
    FROM 
        option_chains 
    WHERE 
        symbol = ?"
);

$select_last_modified = $db->prepare(
    "SELECT 
        last_modified 
    FROM 
        option_chains 
    WHERE 
        symbol = ? AND expiration_date = ?"
);

$delete_option_chains = $db->prepare(
    "DELETE FROM 
        option_chains 
    WHERE 
        symbol = ?"
);

$delete_option_chain = $db->prepare(
    "DELETE FROM 
        option_chains 
    WHERE 
        symbol = ? AND expiration_date = ?"
);

$insert_option_chains = $db->prepare(
    "INSERT INTO 
        option_chains (symbol, expiration_date, options, last_modified)
    VALUES 
        (?, ?, ?, ?)
    ON CONFLICT 
        (symbol, expiration_date) 
    DO UPDATE SET 
        options = excluded.options, 
        last_modified = excluded.last_modified"
);

foreach ($symbols as $symbol) {
    $expiration_dates = $api->getExpirationDates($symbol);

    if ($expiration_dates === ResponseStatus::NOT_FOUND) {
        $delete_option_chains->execute([$symbol]);

        continue;
    }

    $select_expiration_dates->execute([$symbol]);

    $delisted_dates = array_diff($select_expiration_dates->fetchAll(PDO::FETCH_COLUMN, 0), $expiration_dates);

    if ($delisted_dates) {
        foreach ($delisted_dates as $expiration_date) {
            $delete_option_chain->execute([$symbol, $expiration_date]);
        }
    }

    printf("[%s] Exp. Dates: %d\n", $symbol, count($expiration_dates));

    foreach ($expiration_dates as $expiration_date) {
        $select_last_modified->execute([$symbol, $expiration_date]);

        $options = $api->getOptions($symbol, $expiration_date, $select_last_modified->fetchColumn() ?: null);

        if (!($options instanceof ResponseStatus)) {
            $insert_option_chains->execute([$symbol, $expiration_date, json_encode($options), $api->getLastModified()]);
        }
    }
}

printf(
    "Options: %d | Underlyings: %d | Exp. Dates: %d\n",
    $db->query(
        "SELECT
            COUNT(value)
        FROM 
            option_chains, 
            json_each(options)"
    )->fetch(PDO::FETCH_COLUMN, 0),
    ...$db->query(
        "SELECT 
            COUNT(DISTINCT symbol), 
            COUNT(expiration_date)
        FROM 
            option_chains"
    )->fetch()
);
