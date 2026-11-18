<?php
/**
 * Underhållsverktyg: hämta placeringsetiketter (LOC) från Koha och skriv
 * mod_fbg_kohasearch/data/locations.json.
 *
 * Kohas placeringskoder (items.location) är auktoriserade värden i kategorin LOC
 * – t.ex. SPANNING → "Spänning", MAG → "Magasin". Sökmodulen har ingen egen
 * Koha-anslutning (bara Qdrant + Directus), så etiketterna hämtas här en gång och
 * checkas in som en statisk JSON-map som search.php läser vid visning av
 * "Finns inne"-listan i bokmodalen.
 *
 * Koder med tom beskrivning (t.ex. X = ingen särskild placering) utelämnas –
 * search.php visar då ingen placering alls för de exemplaren.
 *
 * Koha-uppgifter läses från integration-koha-web/.env (OAUTH_URL, CLIENT_ID,
 * CLIENT_SECRET, API_BASE_URL) – samma källa som synkpipelinen använder.
 *
 * Användning (CLI, från repo-roten):
 *   php tools/fetch_locations.php [sökväg-till-integration-koha-web]
 *
 * Kör om när nya hyllor lagts upp i Koha; commita den uppdaterade JSON-filen.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Endast CLI.\n");
}

$integrationRoot = rtrim($argv[1] ?? '/home/httpd/fbg-intranet/integrationer/integration-koha-web', '/');
$envFile = $integrationRoot . '/.env';
if (!file_exists($envFile)) {
    fwrite(STDERR, "Hittar inte $envFile\n");
    exit(1);
}

// Enkel .env-läsare (undviker beroende till integrationsrepots common.php)
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    putenv(trim($key) . '=' . trim(trim($value), "\"'"));
}

$oauthUrl  = getenv('OAUTH_URL');
$apiBase   = preg_replace('#/biblios/?$#', '', rtrim((string)getenv('API_BASE_URL'), '/'));
$clientId  = getenv('CLIENT_ID');
$clientSec = getenv('CLIENT_SECRET');

if (!$oauthUrl || !$apiBase || !$clientId || !$clientSec) {
    fwrite(STDERR, "OAUTH_URL, API_BASE_URL, CLIENT_ID och CLIENT_SECRET krävs i $envFile\n");
    exit(1);
}

// ── OAuth: client credentials ────────────────────────────────────────────

$ch = curl_init($oauthUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_POSTFIELDS     => http_build_query([
        'grant_type'    => 'client_credentials',
        'client_id'     => $clientId,
        'client_secret' => $clientSec,
    ]),
]);
$tokenBody = curl_exec($ch);
$tokenCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$token = json_decode((string)$tokenBody, true)['access_token'] ?? null;
if ($tokenCode !== 200 || !$token) {
    fwrite(STDERR, "Kunde inte hämta Koha-token (HTTP $tokenCode).\n");
    exit(1);
}

// ── Hämta LOC-värden ─────────────────────────────────────────────────────

$ch = curl_init($apiBase . '/authorised_value_categories/LOC/authorised_values?_per_page=1000');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Authorization: Bearer ' . $token],
]);
$body = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code !== 200) {
    fwrite(STDERR, "Koha-API svarade HTTP $code på LOC-värdena.\n");
    exit(1);
}

$values = json_decode((string)$body, true);
if (!is_array($values) || $values === []) {
    fwrite(STDERR, "Tomt eller oläsbart svar från Koha-API:t.\n");
    exit(1);
}

// ── Bygg map: kod → etikett (opac_description först, annars description) ──

$labels = [];
$skipped = 0;
foreach ($values as $row) {
    $codeValue = trim((string)($row['value'] ?? ''));
    $label     = trim((string)($row['opac_description'] ?? '')) ?: trim((string)($row['description'] ?? ''));
    if ($codeValue === '' || $label === '') {
        $skipped++;
        continue;
    }
    $labels[$codeValue] = $label;
}

ksort($labels, SORT_STRING);

$outDir  = dirname(__DIR__) . '/mod_fbg_kohasearch/data';
$outFile = $outDir . '/locations.json';
if (!is_dir($outDir) && !mkdir($outDir, 0755, true)) {
    fwrite(STDERR, "Kunde inte skapa $outDir\n");
    exit(1);
}

$json = json_encode($labels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
if (file_put_contents($outFile, $json) === false) {
    fwrite(STDERR, "Kunde inte skriva $outFile\n");
    exit(1);
}

printf("Skrev %d placeringar till %s (%d utan etikett hoppades över).\n", count($labels), $outFile, $skipped);
