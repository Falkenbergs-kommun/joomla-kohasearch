<?php
/**
 * Koha-uppgifter för live-status (mode=availability).
 *
 * Sökmodulen har ingen egen Koha-konfiguration – OAuth-uppgifterna läses ur
 * integration-koha-web/.env (samma fil som nattsynken och tools/fetch_locations.php
 * använder). Saknas filen eller någon variabel är live-status avstängd, tyst.
 *
 * Delas av search.php (endpointen) och mod_fbg_kohasearch.php (som talar om för
 * JS om funktionen är på). Innehåller bara funktioner – ingen utdata.
 *
 * @package FBG Koha Search
 */

const KOHA_ENV_FILE = '/home/httpd/fbg-intranet/integrationer/integration-koha-web/.env';

/**
 * Läs OAUTH_URL, API_BASE_URL, CLIENT_ID och CLIENT_SECRET ur .env.
 * API_BASE_URL pekar på .../api/v1/biblios/ – strippas till API-roten, samma
 * härledning som pipelinen gör. Returnerar null om något saknas.
 *
 * @return array{oauth_url:string, api_base:string, client_id:string, client_secret:string}|null
 */
function loadKohaEnv(): ?array
{
    if (!is_readable(KOHA_ENV_FILE)) {
        return null;
    }

    $vars = [];
    foreach (file(KOHA_ENV_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $vars[trim($key)] = trim(trim($value), "\"'");
    }

    $env = [
        'oauth_url'     => $vars['OAUTH_URL'] ?? '',
        'api_base'      => preg_replace('#/biblios/?$#', '', rtrim($vars['API_BASE_URL'] ?? '', '/')),
        'client_id'     => $vars['CLIENT_ID'] ?? '',
        'client_secret' => $vars['CLIENT_SECRET'] ?? '',
    ];

    foreach ($env as $value) {
        if ($value === '') {
            return null;
        }
    }

    return $env;
}
