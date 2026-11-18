<?php
/**
 * Underhållsverktyg: hitta poster med trasig teckenkodning i Qdrant-collectionen.
 *
 * Skrollar hela koha-biblios payload-only och letar efter Unicode-ersättningstecknet
 * (U+FFFD, visas som �) eller ogiltig UTF-8 i alla textfält. Trasiga tecken kommer
 * från källdatan i Koha (typiskt Latin-1 tolkad som UTF-8 vid gammal import), så
 * rättning görs i Koha – nattsynken plockar upp ändringen.
 *
 * Läser Qdrant-inställningar från Joomla-modulens params, precis som search.php.
 *
 * Användning (CLI, från repo-roten):
 *   php tools/scan_encoding.php [joomla-root]
 *   php tools/scan_encoding.php /home/httpd/fbg-intranet/bibliotek.falkenberg.se
 *
 * Utskrift: sammanfattning + en rad per träff (biblio_id, fält, titel, trasigt värde),
 * tab-separerad så den går att klistra in i ett kalkylark.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Endast CLI.\n");
}

$joomlaRoot = rtrim($argv[1] ?? '/home/httpd/fbg-intranet/bibliotek.falkenberg.se', '/');
$configFile = $joomlaRoot . '/configuration.php';
if (!file_exists($configFile)) {
    fwrite(STDERR, "Hittar inte $configFile\n");
    exit(1);
}

require_once $configFile;
$c   = new JConfig();
$pdo = new PDO("mysql:host={$c->host};dbname={$c->db};charset=utf8mb4", $c->user, $c->password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$params = json_decode(
    $pdo->query("SELECT params FROM `{$c->dbprefix}modules` WHERE module = 'mod_fbg_kohasearch' AND published = 1 LIMIT 1")->fetchColumn() ?: '{}',
    true
);
$qdrantUrl = rtrim($params['qdrant_url'] ?? 'https://qdrant.utvecklingfalkenberg.se', '/');
$qdrantKey = $params['qdrant_api_key'] ?? '';
if ($qdrantKey === '') {
    fwrite(STDERR, "Qdrant API-nyckel saknas i modulens params.\n");
    exit(1);
}

// Textfält i payloaden som kontrolleras (skalära strängar eller listor av strängar)
$fields = [
    'title', 'subtitle', 'part_name', 'author', 'contributors', 'subjects', 'subjects_marc',
    'genre_form', 'tags', 'publisher', 'series_title', 'abstract',
];

$hits = [];      // [biblio_id, fält, titel, värde]
$perField = [];
$offset = null;
$scanned = 0;

do {
    $body = [
        'limit'        => 1000,
        'with_payload' => array_merge(['biblio_id'], $fields),
        'with_vector'  => false,
    ];
    if ($offset !== null) {
        $body['offset'] = $offset;
    }

    $ch = curl_init("$qdrantUrl/collections/koha-biblios/points/scroll");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => ["api-key: $qdrantKey", 'Content-Type: application/json'],
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) {
        fwrite(STDERR, "Qdrant scroll-fel (HTTP $code): " . substr((string)$raw, 0, 200) . "\n");
        exit(1);
    }
    $resp = json_decode($raw, true);

    foreach ($resp['result']['points'] ?? [] as $point) {
        $scanned++;
        $p = $point['payload'] ?? [];
        foreach ($fields as $field) {
            $values = $p[$field] ?? null;
            if ($values === null) {
                continue;
            }
            if (!is_array($values)) {
                $values = [$values];
            }
            foreach ($values as $value) {
                if (!is_string($value)) {
                    continue;
                }
                if (str_contains($value, "\u{FFFD}") || !mb_check_encoding($value, 'UTF-8')) {
                    $hits[] = [$p['biblio_id'] ?? '?', $field, $p['title'] ?? '', mb_substr($value, 0, 80)];
                    $perField[$field] = ($perField[$field] ?? 0) + 1;
                }
            }
        }
    }

    $offset = $resp['result']['next_page_offset'] ?? null;
} while ($offset !== null);

echo "Skannade $scanned poster\n";
echo 'Träffar per fält: ' . (json_encode($perField, JSON_UNESCAPED_UNICODE) ?: '{}') . "\n";
echo 'Unika biblio_id: ' . count(array_unique(array_column($hits, 0))) . "\n\n";

if ($hits) {
    usort($hits, fn($a, $b) => [$a[1], $a[0]] <=> [$b[1], $b[0]]);
    echo "biblio_id\tfält\ttitel\ttrasigt värde\n";
    foreach ($hits as $h) {
        echo implode("\t", $h) . "\n";
    }
}
