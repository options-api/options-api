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
 * Retrieve options from local database
 */
$option_chains = $db->query(
    "SELECT 
        symbol, expiration_date 
    FROM 
        option_chains 
    GROUP BY 
        symbol, expiration_date"
)->fetchAll();

$select_options = $db->prepare(
    "SELECT 
        json_extract(value, '$[0]') strike, 
        json_extract(value, '$[1]') type, 
        json_extract(value, '$[2]') symbol, 
        json_extract(value, '$[3]') figi
    FROM 
        option_chains, 
        json_each(options)
    WHERE 
        symbol = ? AND expiration_date = ?"
);

foreach ($option_chains as [$symbol, $expiration_date]) {
    $select_options->execute([$symbol, $expiration_date]);

    $options = $select_options->fetchAll();

    foreach ($options as $option) {
        // Get option by symbol
        // $option = array_slice($api->getOption($option[2]), 2);

        // Get option by FIGI
        // $option = array_slice($api->getOption($option[3]), 2);

        printf(
            "[%s][%s][%s][%d DTE] Strike: %.2f | Type: %s | Symbol: %s | FIGI: %s\n",
            $symbol,
            $expiration_date,
            $api::getExpirationType($expiration_date),
            $api::getDaysUntilExpiration($expiration_date),
            ...array_values($option)
        );
    }
}
