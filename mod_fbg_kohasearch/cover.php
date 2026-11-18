<?php
/**
 * FBG Koha Search – Bildproxy för bokomslag
 *
 * Serverar cachade omslagsbilder eller laddar ner från Syndetics on-demand.
 * Användning: cover.php?isbn=9789175671888
 *
 * Flöde:
 *   1. Finns bilden cachad? → Servera direkt (snabbt)
 *   2. Annars → Ladda ner från Syndetics, spara i cache, servera
 *   3. Ingen bild? → 302-redirect till placeholder eller 404
 *
 * @package FBG Koha Search
 */

// Bildcache-katalog (samma som integration-koha-web/public/images)
define('IMAGE_CACHE_DIR', '/home/httpd/fbg-intranet/integrationer/integration-koha-web/public/images');
define('SYNDETICS_CLIENT', 'bibfalken');

// Tak för nedladdningar från Syndetics (cachade bilder räknas inte). Endpointen
// är öppen, så utan tak kan vem som helst låta servern hämta och spara bilder
// för påhittade ISBN. 60/min räcker gott för en kall cache med fulla sliders.
define('COVER_DOWNLOADS_PER_MIN', 60);

// Validera ISBN-parameter
$rawIsbn = $_GET['isbn'] ?? '';
$isbn = preg_replace('/[^0-9X]/i', '', strtoupper(is_string($rawIsbn) ? $rawIsbn : ''));

if (strlen($isbn) < 8 || strlen($isbn) > 13) {
    http_response_code(400);
    exit;
}

/**
 * Glidande 60 s-fönster över alla klienter, i cache-live/ utanför webbroten
 * (samma katalog som live-statusens kvot i search.php). Misslyckat fillås
 * eller oskrivbar katalog = nekad (fail closed).
 */
function coverDownloadAllowed(): bool
{
    $dir = dirname(__DIR__) . '/cache-live';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }

    $fp = @fopen($dir . '/cover_ratelimit.json', 'c+');
    if (!$fp) {
        return false;
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return false;
    }

    $now  = time();
    $hits = json_decode((string)stream_get_contents($fp), true);
    $hits = array_values(array_filter(
        is_array($hits) ? $hits : [],
        fn($t) => is_int($t) && $t > $now - 60
    ));

    $allowed = count($hits) < COVER_DOWNLOADS_PER_MIN;
    if ($allowed) {
        $hits[] = $now;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($hits));
    }

    flock($fp, LOCK_UN);
    fclose($fp);
    return $allowed;
}

$imagePath = IMAGE_CACHE_DIR . '/' . $isbn . '.jpg';

// 1. Servera cachad bild
if (file_exists($imagePath) && filesize($imagePath) > 100) {
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($imagePath));
    header('Cache-Control: public, max-age=604800'); // 7 dagar
    readfile($imagePath);
    exit;
}

// 2. Ladda ner från Syndetics – om kvoten tillåter. 503 utan cachning, så
//    att bilden hämtas vid nästa sidvisning (JS visar placeholder så länge).
if (!coverDownloadAllowed()) {
    http_response_code(503);
    header('Retry-After: 60');
    header('Cache-Control: no-store');
    exit;
}

$syndeticsUrl = 'https://secure.syndetics.com/index.aspx?isbn='
    . urlencode($isbn) . '/LC.JPG&client=' . SYNDETICS_CLIENT . '&type=xw12';

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $syndeticsUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
]);

$imageData   = curl_exec($ch);
$httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

// Validera: riktig bild, inte felsida
if ($httpCode === 200 && $imageData && strlen($imageData) > 100
    && strpos($contentType, 'image') !== false) {

    // Spara i cache
    if (is_dir(IMAGE_CACHE_DIR) && is_writable(IMAGE_CACHE_DIR)) {
        file_put_contents($imagePath, $imageData);
    }

    // Servera direkt från minnet
    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . strlen($imageData));
    header('Cache-Control: public, max-age=604800'); // 7 dagar
    echo $imageData;
    exit;
}

// 3. Ingen bild tillgänglig
http_response_code(404);
header('Cache-Control: public, max-age=86400'); // Cacha 404 i 1 dag (undvik upprepad nedladdning)
