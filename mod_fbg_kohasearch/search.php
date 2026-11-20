<?php
/**
 * FBG Koha Search – Sökendpoint
 *
 * Direkt PHP-endpoint (bypassar Joomla) för:
 *   mode=search        – Semantisk sökning via Qdrant med OpenAI-embeddings
 *   mode=facets        – Hämta filtervärden från Directus (cachade 6h)
 *   mode=browse        – Senaste nyförvärv och populärast (från Directus)
 *   mode=book          – Bokdetaljer + "Finns inne" från Directus (dygnsgammal data)
 *   mode=availability  – "Finns inne" just nu, direkt från Kohas API (kvoterad, cachad)
 *   (samt recommend, author, series, suggest – se CLAUDE.md)
 *
 * Konfiguration läses från Joomlas databas via configuration.php; Koha-uppgifter
 * för live-status ur integration-koha-web/.env (koha_env.php).
 *
 * @package FBG Koha Search
 */

// Tak för paginering: offset styr hur många träffar som hämtas från Qdrant
// (mode=search hämtar limit + offset), så ett fritt värde vore en lastattack.
const MAX_RESULT_OFFSET = 1000;

// Dolda filialer och låntyper – visas varken i filter eller sökresultat.
// Titlar som enbart finns på dolda filialer/med dolda typer exkluderas helt.
const HIDDEN_BRANCHES = ['GYMNASIET', 'FJARRLAN', 'LASFRAMJAN', 'SKOGSTORP', 'MUSEET'];
const VISIBLE_BRANCHES = ['FALK', 'MOBILA', 'SLOINGE', 'ULLARED', 'VESSIGEBRO', 'ATRAN'];
const HIDDEN_ITEM_TYPES = [
    // Koder (lagras som råkoder i Qdrant – ej i pipelinens label-map)
    'LASARLAN', 'GYMNOVRIG', 'BOKLAS', 'TERMINLAN', 'EJHEMLAN',
    'BARNLANG', 'FJARR', 'FJARRX', 'BOKLAD', 'DEPOSTION', 'KONTROLL',
    'ELEK',
    // Svenska etiketter (säkerhet ifall pipelinen ändras)
    'Läsårsexemplar', 'Gymnasiet övrigt', 'Bokläsning', 'Terminslån',
    'Ej hemlån', 'Barn-lång', 'Fjärrlån', 'Boklåda', 'Deposition', 'Kontroll',
    'Elektronisk resurs',
];
// Max antal författare i den dynamiska författarfacetten (100 träffar ger ofta
// 60–80 unika namn; listan scrollar i en 200px-box så toppen räcker)
const AUTHOR_FACET_LIMIT = 20;
// Max antal distinkta hyllor (placering + signum) som visas per filial i modalen
const SHELF_LIMIT = 3;

// Kohas NOT_LOAN-värde "Under inköp" (Falkenbergs auktoriserade värden:
// -1 Under inköp, 1 Referens, 2 Ej för utlån). Samma konstant som i
// integration-koha-web/directus/prepare_embedding_text.php. Måste stå här
// uppe: toppnivå-const hissas inte som funktioner, och dispatchen nedan körs
// innan resten av filen nås.
const NOT_LOAN_ON_ORDER = -1;
// Mjuk språkpreferens i mode=search: träffar på andra språk än de föredragna
// (svenska + webbläsarens språk + "på <språk>" i frågan) får poängen multiplicerad
// med denna faktor. 1.0 = av; lägre = hårdare nedflyttning. Inget filtreras bort.
const LANGUAGE_DEMOTE_FACTOR = 0.5;
// Språkkoder som aldrig viktas ned (okänt/flera språk/inget språkligt innehåll)
const LANGUAGE_NEUTRAL_CODES = ['', 'und', 'mul', 'zxx'];

// Live-status (mode=availability) – skyddslager mot att endpointen används för
// att översvämma Koha, som delas med nattsynken. Alla filer ligger
// i cache-live/ i repo-roten – utanför webbroten (bara mod_fbg_kohasearch/ är
// symlänkad in), eftersom OAuth-tokenen sparas där.
const LIVE_CACHE_TTL      = 90;    // s – resultat per titel återanvänds så länge
const LIVE_STALE_MAX      = 3600;  // s – så gammal cache får serveras som fallback
const LIVE_LOCK_SHARDS    = 32;    // single-flight-låsfiler (biblio_id % 32)
const LIVE_GLOBAL_PER_MIN = 30;    // Koha-hämtningar/min totalt (≈1 anrop/s, under synkens takt)
const LIVE_CLIENT_PER_MIN = 10;    // Koha-hämtningar/min per klient (IP-hash)
const LIVE_FAIL_PAUSE     = 60;    // s paus efter fel/timeout mot Koha
const LIVE_BLOCK_PAUSE    = 300;   // s paus efter 403/429 (troligt brandväggsblock)
const LIVE_TOKEN_MARGIN   = 300;   // s före utgång som tokenen förnyas
const KOHA_CONNECT_TIMEOUT = 3;
const KOHA_TIMEOUT         = 5;

require_once __DIR__ . '/koha_env.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-cache');

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Endast POST-begäran tillåten.', 405);
}

// Parse JSON body
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$mode  = $input['mode'] ?? 'search';

// ── Ladda konfiguration från Joomla ──────────────────────────────────────

$joomlaRoot = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
$configFile = $joomlaRoot . '/configuration.php';

if (!file_exists($configFile)) {
    jsonResponse(false, 'Joomla-konfiguration saknas.', 500);
}

try {
    require_once $configFile;
    $jConfig = new JConfig();

    $dsn = 'mysql:host=' . $jConfig->host . ';dbname=' . $jConfig->db . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $jConfig->user, $jConfig->password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    $prefix   = $jConfig->dbprefix;
    $moduleId = isset($input['module_id']) ? (int)$input['module_id'] : 0;

    if ($moduleId > 0) {
        $stmt = $pdo->prepare(
            "SELECT params FROM `{$prefix}modules` WHERE id = :id AND module = 'mod_fbg_kohasearch' AND published = 1 LIMIT 1"
        );
        $stmt->execute([':id' => $moduleId]);
    } else {
        $stmt = $pdo->prepare(
            "SELECT params FROM `{$prefix}modules` WHERE module = 'mod_fbg_kohasearch' AND published = 1 LIMIT 1"
        );
        $stmt->execute();
    }
    $params = json_decode($stmt->fetchColumn() ?: '{}', true);

} catch (\Exception $e) {
    // Detaljerna (PDO-fel kan innehålla databasvärd och användare) stannar i loggen
    error_log('FBG Koha Search konfigurationsfel: ' . $e->getMessage());
    jsonResponse(false, 'Konfigurationsfel.', 500);
}

$qdrantUrl     = rtrim($params['qdrant_url'] ?? 'https://qdrant.utvecklingfalkenberg.se', '/');
$qdrantKey     = $params['qdrant_api_key'] ?? '';
$openaiKey     = $params['openai_api_key'] ?? '';
$embModel      = $params['embedding_model'] ?? 'text-embedding-3-large';
$directusUrl   = rtrim($params['directus_url'] ?? 'https://nav.utvecklingfalkenberg.se', '/');
$directusToken = $params['directus_token'] ?? '';
$imageBaseUrl  = rtrim($params['image_base_url'] ?? 'https://bibliotek.falkenberg.se/fbg_apps/services/koha/images/', '/') . '/';

// ── Routing ──────────────────────────────────────────────────────────────

try {
    switch ($mode) {
        case 'search':
            handleSearch($input, $qdrantUrl, $qdrantKey, $openaiKey, $embModel, $imageBaseUrl);
            break;
        case 'facets':
            handleFacets($directusUrl, $directusToken);
            break;
        case 'browse':
            handleBrowse($input, $directusUrl, $directusToken, $imageBaseUrl);
            break;
        case 'book':
            handleBookDetail($input, $directusUrl, $directusToken);
            break;
        case 'recommend':
            handleRecommend($input, $qdrantUrl, $qdrantKey, $imageBaseUrl);
            break;
        case 'author':
            handleAuthorSearch($input, $qdrantUrl, $qdrantKey, $imageBaseUrl);
            break;
        case 'series':
            handleSeriesSearch($input, $qdrantUrl, $qdrantKey, $imageBaseUrl);
            break;
        case 'suggest':
            handleSuggest($input, $qdrantUrl, $qdrantKey);
            break;
        case 'availability':
            handleLiveAvailability($input, $qdrantUrl, $qdrantKey, (string)($jConfig->secret ?? ''));
            break;
        default:
            jsonResponse(false, 'Okänt mode: ' . $mode, 400);
    }
} catch (\Exception $e) {
    // Undantagen bär svar från Qdrant/Directus/OpenAI och interna sökvägar –
    // de loggas men skickas aldrig till klienten
    error_log('FBG Koha Search error: ' . $e->getMessage());
    jsonResponse(false, 'Sökningen kunde inte genomföras. Försök igen om en stund.', 500);
}

// ════════════════════════════════════════════════════════════════════════════
// Sökläge
// ════════════════════════════════════════════════════════════════════════════

function handleSearch(array $input, string $qdrantUrl, string $qdrantKey, string $openaiKey, string $embModel, string $imageBaseUrl): void
{
    $query   = trim($input['query'] ?? '');
    $limit   = min(max(1, (int)($input['limit'] ?? 24)), 100);
    $offset  = min(max(0, (int)($input['offset'] ?? 0)), MAX_RESULT_OFFSET);
    $filters = $input['filters'] ?? [];
    $sort    = $input['sort'] ?? 'relevance_desc';

    if (empty($query)) {
        jsonResponse(false, 'Sökfrågan kan inte vara tom.', 400);
    }

    if (empty($openaiKey) || empty($qdrantKey)) {
        jsonResponse(false, 'API-nycklar saknas. Konfigurera modulen i Joomla-admin.', 500);
    }

    // 1. Generera dense embedding (OpenAI) och sparse query vector (BM25)
    $embedding = generateEmbedding($query, $openaiKey, $embModel);
    $sparse    = generateSparseQueryVector($query);

    // 2. Bygg Qdrant-filter
    $qdrantFilter = buildQdrantFilter($filters);

    // 3. Hybrid search: hämta fler resultat än begärt för dynamiska facetter.
    //    Qdrant har inget inbyggt facet-API för vektorsökning, så vi räknar
    //    ut facetter från en bredare resultatmängd och paginerar i PHP.
    $facetLimit    = 100;
    $internalLimit = max($facetLimit, $limit + $offset);
    $prefetchLimit = max($internalLimit, 50);

    $prefetch = [
        [
            'query'  => $embedding,
            'using'  => 'dense',
            'limit'  => $prefetchLimit,
            'filter' => $qdrantFilter,
        ],
        [
            'query'  => $sparse,
            'using'  => 'sparse',
            'limit'  => $prefetchLimit,
            'filter' => $qdrantFilter,
        ],
    ];

    // Mjuk språk-boost: om frågan nämner "på <språk>" lägger vi till en extra
    // dense-arm filtrerad till det språket. RRF-fusionen lyfter då de språkmatchande
    // titlarna utan att utesluta övriga träffar.
    $boostCodes = detectQueryLanguages($query);
    if (!empty($boostCodes) && is_array($qdrantFilter)) {
        $langShould = array_map(
            fn($c) => ['key' => 'language_code', 'match' => ['value' => $c]],
            $boostCodes
        );
        $boostFilter = $qdrantFilter; // innehåller redan synlighets-must
        $boostFilter['must'][] = ['should' => $langShould];
        $prefetch[] = [
            'query'  => $embedding,
            'using'  => 'dense',
            'limit'  => $prefetchLimit,
            'filter' => $boostFilter,
        ];
    }

    $queryBody = [
        'prefetch'     => $prefetch,
        'query'        => ['fusion' => 'rrf'],
        'limit'        => $internalLimit,
        'offset'       => 0, // Hämta från början för facettberäkning
        'with_payload' => true,
        'with_vector'  => false,
    ];

    $resp = curlPost(
        "{$qdrantUrl}/collections/koha-biblios/points/query",
        $queryBody,
        ['api-key: ' . $qdrantKey, 'Content-Type: application/json']
    );

    if ($resp['code'] !== 200) {
        throw new \Exception('Qdrant-sökfel (HTTP ' . $resp['code'] . '): ' . substr($resp['body'], 0, 200));
    }

    $data    = json_decode($resp['body'], true);
    $points  = $data['result']['points'] ?? [];

    // 4. Formatera alla resultat
    $allResults = [];
    foreach ($points as $point) {
        $p = $point['payload'] ?? [];
        $allResults[] = formatBookResult($p, $point['score'] ?? 0, $imageBaseUrl);
    }

    // 5. Filtrera dolda låntyper och normalisera
    $allResults = filterHiddenResults($allResults);
    stripHiddenFromResults($allResults);

    // 5b. Mjuk språkpreferens: flytta ned träffar på språk besökaren troligen inte
    //     väntar sig (t.ex. kurdiska utgåvor vid sökning på "Astrid Lindgren").
    //     Bara vid relevanssortering och utan uttryckligt språkfilter.
    $preferredLanguages = array_values(array_unique(array_merge(
        ['swe'],
        browserLanguageCodes(is_array($input['languages'] ?? null) ? $input['languages'] : []),
        $boostCodes
    )));
    $isRelevanceSort = ($sort === 'relevance_desc' || empty($sort));
    if ($isRelevanceSort && empty($filters['language'])) {
        applyLanguagePreference($allResults, $preferredLanguages);
    }

    // 6. Sortera (om ej relevans – RRF-ordningen är redan korrekt)
    sortResults($allResults, $sort);

    // 7. Dynamiska facetter från hela resultatmängden
    $facets = computeDynamicFacets($allResults);

    // 8. Paginera för visning
    $pageResults = array_slice($allResults, $offset, $limit);
    $totalFound  = count($allResults) < $internalLimit
        ? count($allResults)
        : count($allResults) + 1; // Troligen fler resultat

    echo json_encode([
        'success'     => true,
        'results'     => $pageResults,
        'total_found' => $totalFound,
        'query'       => $query,
        'limit'       => $limit,
        'offset'      => $offset,
        'facets'      => $facets,
        'detected_languages' => $boostCodes,
        'preferred_languages' => $preferredLanguages,
    ], JSON_UNESCAPED_UNICODE);
}

// ════════════════════════════════════════════════════════════════════════════
// Rekommendationer (liknande böcker via Qdrant recommend)
// ════════════════════════════════════════════════════════════════════════════

function handleRecommend(array $input, string $qdrantUrl, string $qdrantKey, string $imageBaseUrl): void
{
    $biblioId = (int)($input['biblio_id'] ?? 0);
    $limit    = min(max(1, (int)($input['limit'] ?? 24)), 100);
    $offset   = min(max(0, (int)($input['offset'] ?? 0)), MAX_RESULT_OFFSET);
    $filters  = $input['filters'] ?? [];
    $sort     = $input['sort'] ?? 'relevance_desc';

    if ($biblioId <= 0) {
        jsonResponse(false, 'biblio_id krävs.', 400);
    }

    if (empty($qdrantKey)) {
        jsonResponse(false, 'Qdrant API-nyckel saknas.', 500);
    }

    // Generera deterministiskt UUID (samma som sync-pipelinen)
    $pointId = biblioIdToUuid($biblioId);

    // Bygg filter – exkludera källboken
    $qdrantFilter = buildQdrantFilter($filters);
    $mustNot = [['key' => 'biblio_id', 'match' => ['value' => $biblioId]]];

    if ($qdrantFilter) {
        $qdrantFilter['must_not'] = $mustNot;
    } else {
        $qdrantFilter = ['must_not' => $mustNot];
    }

    $recommendBody = [
        'positive'     => [$pointId],
        'using'        => 'dense',
        'limit'        => $limit,
        'offset'       => $offset,
        'with_payload' => true,
        'with_vector'  => false,
        'filter'       => $qdrantFilter,
    ];

    $resp = curlPost(
        "{$qdrantUrl}/collections/koha-biblios/points/recommend",
        $recommendBody,
        ['api-key: ' . $qdrantKey, 'Content-Type: application/json']
    );

    if ($resp['code'] !== 200) {
        throw new \Exception('Qdrant recommend-fel (HTTP ' . $resp['code'] . '): ' . substr($resp['body'], 0, 200));
    }

    $data   = json_decode($resp['body'], true);
    $points = $data['result'] ?? [];

    $results = [];
    foreach ($points as $point) {
        $p = $point['payload'] ?? [];
        $results[] = formatBookResult($p, $point['score'] ?? 0, $imageBaseUrl);
    }

    $results = filterHiddenResults($results);
    stripHiddenFromResults($results);
    sortResults($results, $sort);

    $totalFound = count($results) < $limit ? $offset + count($results) : $offset + $limit + 1;

    $facets = computeDynamicFacets($results);

    echo json_encode([
        'success'     => true,
        'results'     => $results,
        'total_found' => $totalFound,
        'biblio_id'   => $biblioId,
        'limit'       => $limit,
        'offset'      => $offset,
        'facets'      => $facets,
    ], JSON_UNESCAPED_UNICODE);
}

// ════════════════════════════════════════════════════════════════════════════
// Författarsökning (exakt match via Qdrant scroll)
// ════════════════════════════════════════════════════════════════════════════

function handleAuthorSearch(array $input, string $qdrantUrl, string $qdrantKey, string $imageBaseUrl): void
{
    $author  = trim($input['author'] ?? '');
    $limit   = min(max(1, (int)($input['limit'] ?? 24)), 100);
    $offset  = min(max(0, (int)($input['offset'] ?? 0)), MAX_RESULT_OFFSET);
    $filters = $input['filters'] ?? [];
    $sort    = $input['sort'] ?? 'year_desc';

    if (empty($author)) {
        jsonResponse(false, 'Författarnamn krävs.', 400);
    }

    if (empty($qdrantKey)) {
        jsonResponse(false, 'Qdrant API-nyckel saknas.', 500);
    }

    // Bygg filter – exakt match på author + eventuella facettfilter.
    // Författarfacetten ignoreras här: författaren är redan ett hårt must-villkor
    // (och facettgruppen döljs i JS i författarläge).
    unset($filters['author']);
    $qdrantFilter = buildQdrantFilter($filters);
    $authorCondition = ['key' => 'author', 'match' => ['value' => $author]];

    if ($qdrantFilter) {
        $qdrantFilter['must'][] = $authorCondition;
    } else {
        $qdrantFilter = ['must' => [$authorCondition]];
    }

    // Använd scroll för att hämta utan vektor – sorterat efter publication_year desc
    $scrollBody = [
        'filter'       => $qdrantFilter,
        'limit'        => $limit,
        'offset'       => $offset,
        'with_payload' => true,
        'with_vector'  => false,
    ];

    $resp = curlPost(
        "{$qdrantUrl}/collections/koha-biblios/points/scroll",
        $scrollBody,
        ['api-key: ' . $qdrantKey, 'Content-Type: application/json']
    );

    if ($resp['code'] !== 200) {
        throw new \Exception('Qdrant scroll-fel (HTTP ' . $resp['code'] . '): ' . substr($resp['body'], 0, 200));
    }

    $data   = json_decode($resp['body'], true);
    $points = $data['result']['points'] ?? [];

    $results = [];
    foreach ($points as $point) {
        $p = $point['payload'] ?? [];
        $results[] = formatBookResult($p, 0, $imageBaseUrl);
    }

    $results = filterHiddenResults($results);
    stripHiddenFromResults($results);
    sortResults($results, $sort);

    // Uppskatta totalt: om vi fick exakt limit, finns troligen fler
    $totalFound = count($results) < $limit ? $offset + count($results) : $offset + $limit + 1;

    $facets = computeDynamicFacets($results);

    echo json_encode([
        'success'     => true,
        'results'     => $results,
        'total_found' => $totalFound,
        'author'      => $author,
        'limit'       => $limit,
        'offset'      => $offset,
        'facets'      => $facets,
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Generera deterministiskt UUID5 från biblio_id.
 * Replikerar Python: uuid.uuid5(uuid.NAMESPACE_URL, "koha-biblio-{biblio_id}")
 */
function biblioIdToUuid(int $biblioId): string
{
    // NAMESPACE_URL = 6ba7b811-9dad-11d1-80b4-00c04fd430c8
    $namespaceHex = '6ba7b8119dad11d180b400c04fd430c8';
    $namespaceBytes = hex2bin($namespaceHex);

    $name = "koha-biblio-{$biblioId}";
    $hash = sha1($namespaceBytes . $name);

    // Sätt version 5 och variant bits
    $timeLow     = substr($hash, 0, 8);
    $timeMid     = substr($hash, 8, 4);
    $timeHi      = '5' . substr($hash, 13, 3); // version 5
    $clockSeq    = dechex((hexdec(substr($hash, 16, 4)) & 0x3fff) | 0x8000); // variant
    $node        = substr($hash, 20, 12);

    return sprintf('%s-%s-%s-%s-%s', $timeLow, $timeMid, $timeHi, $clockSeq, $node);
}

// ════════════════════════════════════════════════════════════════════════════
// Facetter
// ════════════════════════════════════════════════════════════════════════════

function handleFacets(string $directusUrl, string $directusToken): void
{
    $cacheFile = __DIR__ . '/cache/facets_cache.json';
    $cacheTtl  = 6 * 3600; // 6 timmar

    // Kolla cache
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
        echo file_get_contents($cacheFile);
        return;
    }

    if (empty($directusToken)) {
        jsonResponse(false, 'Directus-token saknas.', 500);
    }

    $facets = [];

    // Mediatyper – hämta distinkta item_type_id från items
    $mediaTypes = directusGet(
        "{$directusUrl}/items/kft_koha_items?aggregate[countDistinct]=id&groupBy[]=item_type_id&limit=-1",
        $directusToken
    );
    $facets['media_types'] = [];
    $itemTypeLabels = getItemTypeLabels();
    if (!empty($mediaTypes['data'])) {
        foreach ($mediaTypes['data'] as $row) {
            $id = $row['item_type_id'] ?? '';
            if (empty($id)) continue;
            if (in_array($id, HIDDEN_ITEM_TYPES)) continue;
            $facets['media_types'][] = [
                'value' => $id,
                'label' => $itemTypeLabels[$id] ?? $id,
                'count' => (int)($row['countDistinct']['id'] ?? 0),
            ];
        }
        usort($facets['media_types'], fn($a, $b) => $b['count'] <=> $a['count']);
    }

    // Bibliotek/filialer
    $branches = directusGet(
        "{$directusUrl}/items/kft_koha_branches?fields=library_id,name&limit=-1",
        $directusToken
    );
    $facets['branches'] = [];
    if (!empty($branches['data'])) {
        foreach ($branches['data'] as $row) {
            if (in_array($row['library_id'] ?? '', HIDDEN_BRANCHES)) continue;
            $facets['branches'][] = [
                'value' => $row['library_id'] ?? '',
                'label' => $row['name'] ?? $row['library_id'] ?? '',
            ];
        }
        usort($facets['branches'], fn($a, $b) => strcmp($a['label'], $b['label']));
    }

    // Målgrupp – hårdkodade breda kategorier (fritextvärden i Qdrant är för spretiga)
    // Filtrering sker via Qdrant text-match på target_audience-fältet
    $facets['target_audience'] = [
        ['value' => 'Barn',        'label' => 'Barn'],
        ['value' => 'Ungdom',      'label' => 'Ungdom'],
        ['value' => 'Vuxna',       'label' => 'Vuxna'],
        ['value' => 'Allmänheten',  'label' => 'Allmänheten'],
    ];

    // Språk – distinkta language_code från biblios (MARC 008, ISO 639-2)
    $languages = directusGet(
        "{$directusUrl}/items/kft_koha_biblios?aggregate[count]=biblio_id&groupBy[]=language_code&filter[status][_eq]=active&limit=-1",
        $directusToken
    );
    $facets['language'] = [];
    $languageLabels = getLanguageLabels();
    if (!empty($languages['data'])) {
        foreach ($languages['data'] as $row) {
            $code = trim((string)($row['language_code'] ?? ''));
            if ($code === '') continue;
            $facets['language'][] = [
                'value' => $code,
                'label' => $languageLabels[$code] ?? strtoupper($code),
                'count' => (int)($row['count']['biblio_id'] ?? $row['count'] ?? 0),
            ];
        }
        usort($facets['language'], fn($a, $b) => $b['count'] <=> $a['count']);
    }

    // Utgivningsår – hårdkodad range
    $facets['publication_year'] = ['min' => 1900, 'max' => (int)date('Y')];

    $result = json_encode(['success' => true, 'facets' => $facets], JSON_UNESCAPED_UNICODE);

    // Skriv cache
    $cacheDir = __DIR__ . '/cache';
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }
    file_put_contents($cacheFile, $result);

    echo $result;
}

// ════════════════════════════════════════════════════════════════════════════
// Browse-läge (startsida)
// ════════════════════════════════════════════════════════════════════════════

function handleBrowse(array $input, string $directusUrl, string $directusToken, string $imageBaseUrl): void
{
    if (empty($directusToken)) {
        jsonResponse(false, 'Directus-token saknas.', 500);
    }

    // Tillåt-lista: värdet hamnar i cachefilnamnet, så fria värden skulle ge
    // obegränsat med cachefiler och ett Directus-anrop per påhittad sektion
    $section = ($input['section'] ?? 'latest') === 'popular' ? 'popular' : 'latest';
    $limit   = min(max(1, (int)($input['limit'] ?? 12)), 50);

    $cacheFile = __DIR__ . '/cache/browse_' . $section . '_' . $limit . '.json';
    $cacheTtl  = 3600; // 1 timme

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
        echo file_get_contents($cacheFile);
        return;
    }

    $books = [];

    if ($section === 'popular') {
        // Mest reserverade – join med biblios för metadata
        $holds = directusGet(
            "{$directusUrl}/items/kft_koha_hold_counts?sort=-total_holds&limit={$limit}&fields=biblio_id,total_holds",
            $directusToken
        );
        if (!empty($holds['data'])) {
            $biblioIds = array_column($holds['data'], 'biblio_id');
            $holdsMap  = array_column($holds['data'], 'total_holds', 'biblio_id');
            $idList    = implode(',', $biblioIds);
            $biblios   = directusGet(
                "{$directusUrl}/items/kft_koha_biblios?filter[biblio_id][_in]={$idList}&filter[status][_eq]=active&fields=biblio_id,title,author,isbn,isbn_clean,publication_year,abstract,image_cached_url,part_number,part_name,subtitle,language_code&limit={$limit}",
                $directusToken
            );
            if (!empty($biblios['data'])) {
                foreach ($biblios['data'] as $b) {
                    $bid = $b['biblio_id'];
                    $books[] = formatDirectusBook($b, $imageBaseUrl, $holdsMap[$bid] ?? 0);
                }
                // Sortera efter total_holds (samma ordning som holds-queryn)
                usort($books, fn($a, $b) => ($b['total_holds'] ?? 0) <=> ($a['total_holds'] ?? 0));
            }
        }
    } else {
        // Senaste nyförvärv
        $biblios = directusGet(
            "{$directusUrl}/items/kft_koha_biblios?sort=-biblio_id&filter[status][_eq]=active&fields=biblio_id,title,author,isbn,isbn_clean,publication_year,abstract,image_cached_url,part_number,part_name,subtitle,language_code&limit={$limit}",
            $directusToken
        );
        if (!empty($biblios['data'])) {
            foreach ($biblios['data'] as $b) {
                $books[] = formatDirectusBook($b, $imageBaseUrl, 0);
            }
        }
    }

    $result = json_encode([
        'success' => true,
        'section' => $section,
        'books'   => $books,
    ], JSON_UNESCAPED_UNICODE);

    $cacheDir = __DIR__ . '/cache';
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }
    file_put_contents($cacheFile, $result);

    echo $result;

}

// ════════════════════════════════════════════════════════════════════════════
// Bokdetaljer (för modal)
// ════════════════════════════════════════════════════════════════════════════

function handleBookDetail(array $input, string $directusUrl, string $directusToken): void
{
    $biblioId = (int)($input['biblio_id'] ?? 0);
    if ($biblioId <= 0) {
        jsonResponse(false, 'biblio_id krävs.', 400);
    }

    if (empty($directusToken)) {
        jsonResponse(false, 'Directus-token saknas.', 500);
    }

    // Hämta grunddata från biblios
    $biblio = directusGet(
        "{$directusUrl}/items/kft_koha_biblios?" . http_build_query([
            'filter[biblio_id][_eq]' => $biblioId,
            'filter[status][_eq]'    => 'active',
            'fields'                 => 'biblio_id,title,author,abstract,part_number,part_name,subtitle,isbn,isbn_clean,publication_year,publisher,series_title,age_restriction,pages,language_code',
            'limit'                  => 1,
        ]),
        $directusToken
    );

    if (empty($biblio['data'])) {
        jsonResponse(false, 'Boken hittades inte.', 404);
    }

    $book = $biblio['data'][0];

    // Hämta berikad metadata (abstract, ämnen, taggar)
    $enriched = directusGet(
        "{$directusUrl}/items/kft_koha_enriched?" . http_build_query([
            'filter[biblio_id][_eq]' => $biblioId,
            'fields'                 => 'abstract_enriched,subjects,tags,target_audience',
            'limit'                  => 1,
        ]),
        $directusToken
    );

    $enrichedData = $enriched['data'][0] ?? [];

    // Bygg svar — enriched abstract har prioritet
    $abstract = $enrichedData['abstract_enriched'] ?? $book['abstract'] ?? null;

    $isbnClean = $book['isbn_clean'] ?? '';
    if (empty($isbnClean) && !empty($book['isbn'])) {
        $isbnClean = preg_replace('/[^0-9X]/', '', strtoupper(explode(',', str_replace('|', ',', $book['isbn']))[0] ?? ''));
    }

    // Hämta aktiva exemplar – ger både reserverbarhet (minst ett exemplar) och
    // tillgänglighet per filial ("Finns inne"). Data från senaste dygnssynk.
    $itemFields = 'holding_library_id,home_library_id,checked_out_date,'
                . 'not_for_loan_status,damaged_status,lost_status,withdrawn,'
                . 'location,permanent_location,callnumber';
    $itemsQuery = [
        'filter[biblio_id][_eq]' => $biblioId,
        'filter[status][_eq]'    => 'active',
        'limit'                  => -1,
    ];
    try {
        // hold_status (reservationshyllan/transit) sätts av holds-synken. Saknas
        // fältet i Directus svarar den 403 på hela frågan – fall då tillbaka utan det.
        $itemsResp = directusGet(
            "{$directusUrl}/items/kft_koha_items?" . http_build_query(['fields' => $itemFields . ',hold_status'] + $itemsQuery),
            $directusToken
        );
    } catch (\Exception $e) {
        $itemsResp = directusGet(
            "{$directusUrl}/items/kft_koha_items?" . http_build_query(['fields' => $itemFields] + $itemsQuery),
            $directusToken
        );
    }
    $items = $itemsResp['data'] ?? [];
    $itemCount = count($items);
    $availability = aggregateBranchAvailability($items);
    $reserveLink = $itemCount > 0
        ? 'https://bibliotekskatalog.falkenberg.se/cgi-bin/koha/opac-reserve.pl?biblionumber=' . $biblioId
        : null;

    // Språk – visa bara för icke-svenska titlar (svenska utelämnas för mindre brus)
    $language = languageDisplayName($book['language_code'] ?? '');

    echo json_encode([
        'success'          => true,
        'biblio_id'        => $biblioId,
        'title'            => $book['title'] ?? '',
        'part_number'      => $book['part_number'] ?? '',
        'part_name'        => $book['part_name'] ?? '',
        'subtitle'         => $book['subtitle'] ?? '',
        'author'           => $book['author'] ?? '',
        'abstract'         => $abstract,
        'publication_year' => $book['publication_year'] ?? null,
        'publisher'        => $book['publisher'] ?? '',
        'series_title'     => parseSeriesTitle($book['series_title'] ?? null),
        'pages'            => $book['pages'] ?? '',
        'age_restriction'  => $book['age_restriction'] ?? '',
        'language'         => $language,
        'subjects'         => $enrichedData['subjects'] ?? [],
        'tags'             => $enrichedData['tags'] ?? [],
        'target_audience'  => $enrichedData['target_audience'] ?? '',
        'image_url'        => !empty($isbnClean) ? '/modules/mod_fbg_kohasearch/cover.php?isbn=' . urlencode($isbnClean) : null,
        'catalog_link'     => 'https://bibliotekskatalog.falkenberg.se/cgi-bin/koha/opac-detail.pl?biblionumber=' . $biblioId,
        'reserve_link'     => $reserveLink,
        'availability'     => $availability,
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Är exemplaret tillgängligt för hemlån just nu?
 * Speglar villkoret i sync-pipelinen (prepare_embedding_text.php): ej utlånad,
 * ej referens/ej-hemlån, ej skadat, ej förkommet, ej kasserat – OCH hemma, dvs.
 * exemplaret befinner sig på sin hemfilial (holdingbranch = homebranch). Ett
 * exemplar som tillfälligt står på en annan filial (transport, återlämnat där)
 * räknas inte som inne – OCH inte reserverat: hold_status W (väntar på
 * reservationshyllan) / T (i transit) betyder att exemplaret är upptaget fast
 * exemplarfälten ser lediga ut. Fältet sätts av holds-synken (Directus) resp.
 * av live-läget (mode=availability) från Kohas holds; saknas det räknas
 * exemplaret som oreserverat.
 */
function isItemAvailable(array $item): bool
{
    return empty($item['checked_out_date'])
        && (int)($item['not_for_loan_status'] ?? 0) === 0
        && (int)($item['damaged_status'] ?? 0) === 0
        && (int)($item['lost_status'] ?? 0) === 0
        && (int)($item['withdrawn'] ?? 0) === 0
        && empty($item['hold_status'])
        && itemIsHome($item);
}

/**
 * Är exemplaret under inköp? Beställt och i övrigt felfritt. Speglar
 * isItemOnOrder() i sync-pipelinen, som ger on_order_items i Qdrant.
 */
function isItemOnOrder(array $item): bool
{
    return (int)($item['not_for_loan_status'] ?? 0) === NOT_LOAN_ON_ORDER
        && (int)($item['damaged_status'] ?? 0) === 0
        && (int)($item['lost_status'] ?? 0) === 0
        && (int)($item['withdrawn'] ?? 0) === 0;
}

/**
 * Befinner sig exemplaret på sin hemfilial? Saknas holdingbranch antas hemma.
 */
function itemIsHome(array $item): bool
{
    $home = $item['home_library_id'] ?? null;
    $holding = $item['holding_library_id'] ?? $home;
    return $holding === $home;
}

/**
 * Aggregera exemplar per synlig filial: [{code, label, available, total, reserved, shelves}].
 * Filial = exemplarets hemfilial (homebranch), fallback holdingbranch. Ett
 * exemplar som är på annan filial räknas alltså i totalen för sin hemfilial men
 * inte som tillgängligt (isItemAvailable kräver hemma) – "Slöinge: 0 av 1 inne".
 * `reserved` = exemplar med hold_status (på reservationshyllan/i transit). De
 * räknas inte som inne; frontend visar inte antalet (bara "0 av 1 inne"), fältet
 * finns kvar i svaret för ev. framtida bruk. Dolda filialer utelämnas. Sorteras
 * på filialnamn.
 *
 * `shelves` = [{location, callnumber}] – var i huset boken står. Bygger på de
 * *tillgängliga* exemplaren när sådana finns (det är dem låntagaren kan gå och
 * hämta); saknas tillgängliga exemplar används alla, så hyllan syns ändå.
 */
function aggregateBranchAvailability(array $items): array
{
    $labels = getBranchLabels();
    $byBranch = [];

    foreach ($items as $item) {
        $code = $item['home_library_id'] ?? $item['holding_library_id'] ?? null;
        if ($code === null || !in_array($code, VISIBLE_BRANCHES, true)) {
            continue;
        }
        if (!isset($byBranch[$code])) {
            $byBranch[$code] = [
                'code'      => $code,
                'label'     => $labels[$code] ?? $code,
                'available' => 0,
                'total'     => 0,
                'reserved'  => 0,
                'on_order'  => 0,
                'shelves'   => [],
                '_allShelves' => [],
            ];
        }
        $byBranch[$code]['total']++;
        if (!empty($item['hold_status'])) {
            $byBranch[$code]['reserved']++;
        }
        $shelf = itemShelf($item);
        if ($shelf !== null) {
            $key = $shelf['location'] . '|' . $shelf['callnumber'];
            $byBranch[$code]['_allShelves'][$key] = $shelf;
            if (isItemAvailable($item)) {
                $byBranch[$code]['shelves'][$key] = $shelf;
            }
        }
        if (isItemAvailable($item)) {
            $byBranch[$code]['available']++;
        } elseif (isItemOnOrder($item)) {
            $byBranch[$code]['on_order']++;
        }
    }

    $result = [];
    foreach ($byBranch as $branch) {
        $shelves = $branch['shelves'] ?: $branch['_allShelves'];
        unset($branch['_allShelves']);
        // Exemplar utan placering (Kohas X-kod) bidrar bara med signum. Har filialen
        // någon riktig placering säger den raden inget nytt – den skulle bara ge
        // "Sv-språk · Hcf, Hcf" – så den faller bort när bättre info finns.
        $placed = array_filter($shelves, fn($s) => $s['location'] !== '');
        if ($placed !== []) {
            $shelves = $placed;
        }
        $branch['shelves'] = array_values(array_slice($shelves, 0, SHELF_LIMIT));
        $result[] = $branch;
    }
    usort($result, fn($a, $b) => strcmp($a['label'], $b['label']));
    return $result;
}

/**
 * Placering + hyllsignum för ett exemplar, eller null om båda saknas.
 * Placeringskoden översätts via LOC-mappen; okända koder och koder utan etikett
 * (t.ex. X = ingen särskild placering) ger tom placering – då visas bara signum.
 */
function itemShelf(array $item): ?array
{
    $locCode = trim((string)($item['location'] ?? $item['permanent_location'] ?? ''));
    $location = $locCode !== '' ? (getLocationLabels()[$locCode] ?? '') : '';
    $callnumber = trim((string)($item['callnumber'] ?? ''));

    if ($location === '' && $callnumber === '') {
        return null;
    }
    return ['location' => $location, 'callnumber' => $callnumber];
}

// ════════════════════════════════════════════════════════════════════════════
// Live-status (mode=availability) – "Finns inne" direkt från Kohas API
// ════════════════════════════════════════════════════════════════════════════

/**
 * mode=availability: tillgänglighet per filial för EN titel, hämtad från Koha
 * just nu i stället för nattsynken. Svarar alltid HTTP 200 (utom ogiltigt
 * biblio_id) så att frontend tyst kan behålla nattdatan när live inte går:
 *
 *   {success, biblio_id, live:true,  cached, stale, fetched_at, age, not_found?, availability:[…]}
 *   {success, biblio_id, live:false, reason:'disabled'|'rate_limited'|'busy'|'koha_unavailable'|'error'}
 */
function handleLiveAvailability(array $input, string $qdrantUrl, string $qdrantKey, string $secret): void
{
    $biblioId = (int)($input['biblio_id'] ?? 0);
    if ($biblioId <= 0) {
        jsonResponse(false, 'biblio_id krävs.', 400);
    }

    try {
        $result = liveAvailability($biblioId, $qdrantUrl, $qdrantKey, $secret);
    } catch (\Throwable $e) {
        error_log('FBG Koha Search live: ' . $e->getMessage());
        $result = ['live' => false, 'reason' => 'error'];
    }

    echo json_encode(['success' => true, 'biblio_id' => $biblioId] + $result, JSON_UNESCAPED_UNICODE);
}

/**
 * Själva flödet, billigast först – cache-träffar förbrukar ingen kvot:
 *   cache → circuit breaker → single-flight-lås → kvoter → token → Koha → cache + Qdrant
 */
function liveAvailability(int $biblioId, string $qdrantUrl, string $qdrantKey, string $secret): array
{
    $env = loadKohaEnv();
    if ($env === null) {
        return ['live' => false, 'reason' => 'disabled'];
    }

    $cached = readLiveCache($biblioId);
    if ($cached !== null && $cached['age'] < LIVE_CACHE_TTL) {
        return liveFromCache($cached, true);
    }

    if (liveCircuitOpen()) {
        return liveStaleOr($cached, 'koha_unavailable');
    }

    $lock = acquireLiveLock($biblioId);
    if ($lock === null) {
        return liveStaleOr($cached, 'busy');
    }

    try {
        // Dubbelkolla under låset – en annan request kan just ha hämtat
        clearstatcache();
        $cached = readLiveCache($biblioId);
        if ($cached !== null && $cached['age'] < LIVE_CACHE_TTL) {
            return liveFromCache($cached, true);
        }

        $clientKey = substr(hash_hmac('sha256', $_SERVER['REMOTE_ADDR'] ?? '', $secret), 0, 16);
        if (!liveRateReserve($clientKey)) {
            return liveStaleOr($cached, 'rate_limited');
        }

        $token = kohaAccessToken($env);
        if ($token === null) {
            markLiveFailure('oauth');
            return liveStaleOr($cached, 'koha_unavailable');
        }

        // Exemplar. 404 = titeln finns inte (längre) i Koha – cachas som tomt svar.
        $resp = kohaRequest($env['api_base'] . '/biblios/' . $biblioId . '/items?_per_page=200', $token);
        if ($resp['code'] === 404) {
            $payload = ['fetched_at' => time(), 'not_found' => true, 'availability' => []];
            writeLiveCache($biblioId, $payload);
            return liveFromCache($payload + ['age' => 0], false);
        }
        if ($resp['code'] !== 200) {
            return liveKohaError($resp, $cached);
        }
        $items = json_decode($resp['body'], true);
        if (!is_array($items)) {
            markLiveFailure('items_json');
            return liveStaleOr($cached, 'koha_unavailable');
        }

        // Reservationer med status W (på hyllan) / T (i transit) – bara de som
        // upptar ett exemplar. Köade holds (status null) påverkar inte "inne".
        $q = json_encode(['biblio_id' => $biblioId, 'status' => ['-in' => ['W', 'T']]]);
        $resp = kohaRequest($env['api_base'] . '/holds?_per_page=200&q=' . rawurlencode($q), $token);
        if ($resp['code'] !== 200) {
            return liveKohaError($resp, $cached);
        }
        $holds = json_decode($resp['body'], true);
        $held = [];
        foreach (is_array($holds) ? $holds : [] as $hold) {
            if (isset($hold['item_id']) && in_array($hold['status'] ?? null, ['W', 'T'], true)) {
                $held[(int)$hold['item_id']] = $hold['status'];
            }
        }

        // Kohas item-fält heter som Directus-raderna → samma aggregering som mode=book
        foreach ($items as &$item) {
            $item['hold_status'] = $held[(int)($item['item_id'] ?? 0)] ?? null;
        }
        unset($item);

        $payload = [
            'fetched_at'   => time(),
            'not_found'    => false,
            'availability' => aggregateBranchAvailability($items),
        ];
        writeLiveCache($biblioId, $payload);
        updateQdrantAvailability($biblioId, $items, $qdrantUrl, $qdrantKey);
        maybeCleanLiveCache();

        return liveFromCache($payload + ['age' => 0], false);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * Koha svarade fel (eller nätverksfel, code 0): öppna circuit breakern och
 * servera gammal cache om sådan finns. 401 = tokenen gäller inte längre trots
 * att den inte gått ut – kasta den så nästa försök hämtar ny.
 */
function liveKohaError(array $resp, ?array $cached): array
{
    if ($resp['code'] === 401) {
        @unlink(liveCacheDir() . '/koha_token.json');
    }
    markLiveFailure($resp['code'] === 0 ? 'curl: ' . ($resp['error'] ?? '') : 'http_' . $resp['code'], $resp['code']);
    return liveStaleOr($cached, 'koha_unavailable');
}

function liveFromCache(array $cached, bool $hit): array
{
    return [
        'live'         => true,
        'cached'       => $hit,
        'stale'        => $cached['age'] >= LIVE_CACHE_TTL,
        'fetched_at'   => date('c', (int)$cached['fetched_at']),
        'age'          => (int)$cached['age'],
        'not_found'    => !empty($cached['not_found']),
        'availability' => $cached['availability'] ?? [],
    ];
}

/** Gammal cache (< LIVE_STALE_MAX) hellre än inget – annars live:false med orsak. */
function liveStaleOr(?array $cached, string $reason): array
{
    if ($cached !== null && $cached['age'] < LIVE_STALE_MAX) {
        return liveFromCache($cached, true) + ['reason' => $reason];
    }
    return ['live' => false, 'reason' => $reason];
}

/**
 * Katalog för live-lägets filer: repo-roten/cache-live – utanför webbroten
 * (__DIR__ har symlänkar upplösta). 0700 eftersom OAuth-tokenen ligger här.
 */
function liveCacheDir(): string
{
    $dir = dirname(__DIR__) . '/cache-live';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir;
}

function readLiveCache(int $biblioId): ?array
{
    $file = liveCacheDir() . '/avail_' . $biblioId . '.json';
    if (!is_file($file)) {
        return null;
    }
    $data = json_decode((string)file_get_contents($file), true);
    if (!is_array($data) || !isset($data['fetched_at'])) {
        return null;
    }
    $data['age'] = max(0, time() - (int)$data['fetched_at']);
    return $data;
}

function writeLiveCache(int $biblioId, array $payload): void
{
    $file = liveCacheDir() . '/avail_' . $biblioId . '.json';
    $tmp  = $file . '.' . getmypid() . '.tmp';
    if (file_put_contents($tmp, json_encode($payload, JSON_UNESCAPED_UNICODE)) !== false) {
        rename($tmp, $file);
    }
}

/** Var ~50:e hämtning: rensa resultatfiler äldre än LIVE_STALE_MAX. */
function maybeCleanLiveCache(): void
{
    if (mt_rand(1, 50) !== 1) {
        return;
    }
    $cutoff = time() - LIVE_STALE_MAX;
    foreach (glob(liveCacheDir() . '/avail_*.json') ?: [] as $file) {
        if (@filemtime($file) < $cutoff) {
            @unlink($file);
        }
    }
}

/** Single-flight per titel: 32 shardade låsfiler, LOCK_NB – den som inte får låset serverar stale. */
function acquireLiveLock(int $biblioId)
{
    $fh = fopen(liveCacheDir() . '/lock_' . ($biblioId % LIVE_LOCK_SHARDS), 'c');
    if ($fh === false) {
        return null;
    }
    if (!flock($fh, LOCK_EX | LOCK_NB)) {
        fclose($fh);
        return null;
    }
    return $fh;
}

/**
 * Circuit breaker: efter fel mot Koha pausas alla live-hämtningar en stund.
 * 403/429 tolkas som brandväggsblock och ger längre paus (LIVE_BLOCK_PAUSE).
 */
function liveCircuitOpen(): bool
{
    $file = liveCacheDir() . '/koha_circuit.json';
    if (!is_file($file)) {
        return false;
    }
    $data = json_decode((string)file_get_contents($file), true);
    return is_array($data) && (int)($data['until'] ?? 0) > time();
}

function markLiveFailure(string $reason, int $httpCode = 0): void
{
    $pause = ($httpCode === 403 || $httpCode === 429) ? LIVE_BLOCK_PAUSE : LIVE_FAIL_PAUSE;
    $file  = liveCacheDir() . '/koha_circuit.json';
    $tmp   = $file . '.' . getmypid() . '.tmp';
    file_put_contents($tmp, json_encode(['until' => time() + $pause, 'reason' => $reason, 'at' => time()]));
    rename($tmp, $file);
    error_log("FBG Koha Search live: Koha-fel ({$reason}), pausar live-status {$pause} s");
}

/**
 * Kvoter, glidande 60 s-fönster i en fil under exklusivt lås:
 *   {global:[ts…], clients:{<ip-hmac>:[ts…]}}
 * Räknar hämtningar (= 2 Koha-anrop, 3 vid tokenförnyelse). Misslyckat lås
 * räknas som nekat – hellre ingen live-status än okontrollerad trafik.
 */
function liveRateReserve(string $clientKey): bool
{
    $fh = fopen(liveCacheDir() . '/ratelimit.json', 'c+');
    if ($fh === false) {
        return false;
    }
    if (!flock($fh, LOCK_EX | LOCK_NB)) {
        fclose($fh);
        return false;
    }

    try {
        $data = json_decode((string)stream_get_contents($fh), true);
        if (!is_array($data)) {
            $data = [];
        }
        $now = time();
        $cut = $now - 60;
        $keep = fn($t) => (int)$t > $cut;

        $global  = array_values(array_filter((array)($data['global'] ?? []), $keep));
        $clients = [];
        foreach ((array)($data['clients'] ?? []) as $key => $stamps) {
            $stamps = array_values(array_filter((array)$stamps, $keep));
            if ($stamps !== []) {
                $clients[$key] = $stamps;
            }
        }
        if (count($clients) > 2000) {
            $clients = [];
        }

        $mine    = $clients[$clientKey] ?? [];
        $allowed = count($global) < LIVE_GLOBAL_PER_MIN && count($mine) < LIVE_CLIENT_PER_MIN;
        if ($allowed) {
            $global[] = $now;
            $mine[]   = $now;
            $clients[$clientKey] = $mine;
        }

        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode(['global' => $global, 'clients' => $clients]));
        fflush($fh);
        return $allowed;
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/**
 * OAuth2 client_credentials-token, cachad i koha_token.json (0600). Förnyas
 * LIVE_TOKEN_MARGIN s före utgång under eget lås; den som inte får låset
 * använder den gamla tokenen så länge den faktiskt gäller.
 */
function kohaAccessToken(array $env): ?string
{
    $dir  = liveCacheDir();
    $file = $dir . '/koha_token.json';
    $now  = time();

    $tok = readKohaTokenFile($file);
    if ($tok !== null && $tok['expires_at'] - LIVE_TOKEN_MARGIN > $now) {
        return $tok['access_token'];
    }

    $lockFh = fopen($dir . '/koha_token.lock', 'c');
    if ($lockFh === false || !flock($lockFh, LOCK_EX | LOCK_NB)) {
        if ($lockFh !== false) {
            fclose($lockFh);
        }
        return ($tok !== null && $tok['expires_at'] > $now) ? $tok['access_token'] : null;
    }

    try {
        clearstatcache();
        $tok = readKohaTokenFile($file);
        if ($tok !== null && $tok['expires_at'] - LIVE_TOKEN_MARGIN > $now) {
            return $tok['access_token'];
        }

        $resp = kohaRequest($env['oauth_url'], null, [
            'grant_type'    => 'client_credentials',
            'client_id'     => $env['client_id'],
            'client_secret' => $env['client_secret'],
        ]);
        if ($resp['code'] !== 200) {
            return null;
        }
        $data = json_decode($resp['body'], true);
        if (empty($data['access_token'])) {
            return null;
        }

        $tmp = $file . '.' . getmypid() . '.tmp';
        file_put_contents($tmp, json_encode([
            'access_token' => $data['access_token'],
            'expires_at'   => $now + (int)($data['expires_in'] ?? 3600),
        ]));
        chmod($tmp, 0600);
        rename($tmp, $file);

        return $data['access_token'];
    } finally {
        flock($lockFh, LOCK_UN);
        fclose($lockFh);
    }
}

function readKohaTokenFile(string $file): ?array
{
    if (!is_file($file)) {
        return null;
    }
    $data = json_decode((string)file_get_contents($file), true);
    if (!is_array($data) || empty($data['access_token']) || empty($data['expires_at'])) {
        return null;
    }
    return ['access_token' => (string)$data['access_token'], 'expires_at' => (int)$data['expires_at']];
}

/**
 * Skriv tillgängligheten till Qdrant-payloaden så bokkort och "Finns inne på"-
 * facetten stämmer resten av dagen. Samma beräkning som pipelinens
 * aggregateItemsByBiblio(): hemfilial för exemplar som är inne (alla filialer,
 * dolda rensas vid läsning), antal inne, antal totalt. set_payload mergar
 * nycklar – vektorer och övrig payload rörs inte.
 *
 * payload_hash sätts till ett live-värde så att nattsynken (som jämför sin
 * beräknade hash med den lagrade) alltid skriver över nästa morgon – annars
 * kunde ett live-värde som blivit inaktuellt bli kvar tills något annat ändras.
 * Fel loggas bara; svaret till klienten påverkas inte.
 */
function updateQdrantAvailability(int $biblioId, array $items, string $qdrantUrl, string $qdrantKey): void
{
    if ($qdrantKey === '') {
        return;
    }

    $branches = [];
    $available = 0;
    $onOrder = 0;
    foreach ($items as $item) {
        if (isItemOnOrder($item)) {
            $onOrder++;
        }
        if (isItemAvailable($item)) {
            $available++;
            $home = $item['home_library_id'] ?? null;
            if ($home !== null && $home !== '') {
                $branches[(string)$home] = true;
            }
        }
    }

    try {
        $resp = curlPost(
            $qdrantUrl . '/collections/koha-biblios/points/payload?wait=false',
            [
                'points'  => [biblioIdToUuid($biblioId)],
                'payload' => [
                    'available_branches' => array_keys($branches),
                    'available_items'    => $available,
                    'on_order_items'     => $onOrder,
                    'total_items'        => count($items),
                    'payload_hash'       => 'live-' . time(),
                ],
            ],
            ['api-key: ' . $qdrantKey, 'Content-Type: application/json']
        );
        if ($resp['code'] !== 200) {
            error_log("FBG Koha Search live: Qdrant set_payload för {$biblioId} gav HTTP {$resp['code']}");
        }
    } catch (\Exception $e) {
        error_log('FBG Koha Search live: Qdrant set_payload misslyckades: ' . $e->getMessage());
    }
}

/**
 * Placeringskod → svensk etikett (Kohas auktoriserade värden, kategori LOC).
 * Statisk map i data/locations.json – uppdateras med tools/fetch_locations.php.
 */
function getLocationLabels(): array
{
    static $labels = null;
    if ($labels === null) {
        $file = __DIR__ . '/data/locations.json';
        $labels = file_exists($file)
            ? (json_decode(file_get_contents($file), true) ?: [])
            : [];
    }
    return $labels;
}

// ════════════════════════════════════════════════════════════════════════════
// OpenAI Embedding
// ════════════════════════════════════════════════════════════════════════════

function generateEmbedding(string $text, string $apiKey, string $model): array
{
    $resp = curlPost(
        'https://api.openai.com/v1/embeddings',
        ['model' => $model, 'input' => $text, 'encoding_format' => 'float'],
        ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json']
    );

    if ($resp['code'] !== 200) {
        throw new \Exception('OpenAI Embedding-fel (HTTP ' . $resp['code'] . ')');
    }

    $data = json_decode($resp['body'], true);
    if (!isset($data['data'][0]['embedding'])) {
        throw new \Exception('Ogiltigt svar från OpenAI Embeddings API.');
    }

    return $data['data'][0]['embedding'];
}

// ════════════════════════════════════════════════════════════════════════════
// BM25 Sparse Query Embedding (ren PHP, kompatibel med fastembed Qdrant/bm25)
// ════════════════════════════════════════════════════════════════════════════

/**
 * Generera sparse query vector för BM25 hybrid search.
 *
 * Replikerar fastembed Qdrant/bm25 query_embed():
 * 1. Ta bort icke-alfanumeriska tecken (behåll \w och \s)
 * 2. Tokenisera (lowercase, split på non-word)
 * 3. Filtrera stopord, punktuation, för långa tokens
 * 4. Stemma med Snowball English
 * 5. Hasha unika tokens med MurmurHash3 32-bit, value = 1.0
 *
 * IDF-viktningen sker på Qdrant-sidan (modifier="idf" i sparse index).
 */
function generateSparseQueryVector(string $text): array
{
    static $stopwords = null;
    if ($stopwords === null) {
        $stopwords = array_flip(explode("\n", trim(file_get_contents(__DIR__ . '/bm25_stopwords_en.txt'))));
    }

    // Steg 1: remove_non_alphanumeric (matchar Python re.sub(r"[^\w\s]", " ", text))
    $text = preg_replace('/[^\w\s]/u', ' ', $text);

    // Steg 2: SimpleTokenizer.tokenize — lowercase, ersätt non-word med space, split
    $text = preg_replace('/[^\w]/u', ' ', mb_strtolower($text, 'UTF-8'));
    $text = preg_replace('/\s+/', ' ', $text);
    $tokens = array_filter(explode(' ', trim($text)), fn($t) => $t !== '');

    // Steg 3: Filtrera stopord och för långa tokens
    $stemmed = [];
    foreach ($tokens as $token) {
        if (isset($stopwords[$token])) continue;
        if (mb_strlen($token) > 40) continue;
        // Steg 4: Snowball English stemmer
        $stem = snowballStemEnglish($token);
        if ($stem !== '') {
            $stemmed[] = $stem;
        }
    }

    // Steg 5: Unika tokens → MurmurHash3 32-bit, value = 1.0
    $uniqueTokens = array_unique($stemmed);
    $indices = [];
    $values = [];
    foreach ($uniqueTokens as $token) {
        // PHP hash('murmur3a') returnerar hex, konvertera till signed 32-bit, ta abs()
        $hex = hash('murmur3a', $token);
        $unsigned = hexdec($hex);
        $signed = ($unsigned >= 0x80000000) ? $unsigned - 0x100000000 : $unsigned;
        $indices[] = abs($signed);
        $values[] = 1.0;
    }

    return ['indices' => $indices, 'values' => $values];
}

/**
 * Snowball English stemmer (Porter2).
 *
 * Baserad på Snowball-algoritmens specifikation:
 * https://snowballstem.org/algorithms/english/stemmer.html
 */
function snowballStemEnglish(string $word): string
{
    if (mb_strlen($word) <= 2) return $word;

    // Specialfall
    $exceptions = [
        'skis' => 'ski', 'skies' => 'sky', 'dying' => 'die', 'lying' => 'lie',
        'tying' => 'tie', 'idly' => 'idl', 'gently' => 'gentl', 'ugly' => 'ugli',
        'early' => 'earli', 'only' => 'onli', 'singly' => 'singl',
        'sky' => 'sky', 'news' => 'news', 'howe' => 'howe',
        'atlas' => 'atlas', 'cosmos' => 'cosmos', 'bias' => 'bias',
        'andes' => 'andes', 'inning' => 'inning', 'innings' => 'inning',
        'outing' => 'outing', 'outings' => 'outing', 'canning' => 'canning',
        'cannings' => 'canning', 'herring' => 'herring', 'herrings' => 'herring',
        'earring' => 'earring', 'earrings' => 'earring', 'proceed' => 'proceed',
        'proceeds' => 'proceed', 'proceeded' => 'proceed', 'proceeding' => 'proceed',
        'exceed' => 'exceed', 'exceeds' => 'exceed', 'exceeded' => 'exceed',
        'exceeding' => 'exceed', 'succeed' => 'succeed', 'succeeds' => 'succeed',
        'succeeded' => 'succeed', 'succeeding' => 'succeed',
    ];
    if (isset($exceptions[$word])) return $exceptions[$word];

    // Steg 0: Trimma apostrofer
    $word = ltrim($word, "'");

    // Markera Y som konsonant
    if ($word[0] === 'y') $word = 'Y' . substr($word, 1);
    $word = preg_replace('/(?<=[aeiouy])y/', 'Y', $word);

    // Hitta R1 och R2 regioner
    $r1 = strlen($word);
    $r2 = strlen($word);

    // Specialhantering av "gener", "commun", "arsen"
    if (str_starts_with($word, 'gener') || str_starts_with($word, 'arsen')) {
        $r1 = 5;
    } elseif (str_starts_with($word, 'commun')) {
        $r1 = 6;
    } else {
        if (preg_match('/[aeiouyAEIOUY][^aeiouyAEIOUY]/', $word, $m, PREG_OFFSET_CAPTURE)) {
            $r1 = $m[0][1] + 2;
        }
    }
    if ($r1 < strlen($word)) {
        $rest = substr($word, $r1);
        if (preg_match('/[aeiouyAEIOUY][^aeiouyAEIOUY]/', $rest, $m, PREG_OFFSET_CAPTURE)) {
            $r2 = $r1 + $m[0][1] + 2;
        }
    }

    // Steg 1a
    if (str_ends_with($word, 'sses')) {
        $word = substr($word, 0, -2);
    } elseif (str_ends_with($word, 'ied') || str_ends_with($word, 'ies')) {
        $word = strlen($word) > 4 ? substr($word, 0, -2) : substr($word, 0, -1);
    } elseif (str_ends_with($word, 'ss') || str_ends_with($word, 'us')) {
        // Inget
    } elseif (str_ends_with($word, 's') && strlen($word) > 2) {
        $preceding = substr($word, 0, -2);
        if (preg_match('/[aeiouyAEIOUY]/', $preceding)) {
            $word = substr($word, 0, -1);
        }
    }

    // Steg 1b
    $step1b_extra = false;
    foreach (['eedly', 'eed'] as $suffix) {
        if (str_ends_with($word, $suffix)) {
            $stem = substr($word, 0, -strlen($suffix));
            if (strlen($stem) >= $r1) {
                $word = $stem . 'ee';
            }
            goto step1c;
        }
    }
    foreach (['ingly', 'edly', 'ing', 'ed'] as $suffix) {
        if (str_ends_with($word, $suffix)) {
            $stem = substr($word, 0, -strlen($suffix));
            if (preg_match('/[aeiouyAEIOUY]/', $stem)) {
                $word = $stem;
                $step1b_extra = true;
            }
            break;
        }
    }
    if ($step1b_extra) {
        if (str_ends_with($word, 'at') || str_ends_with($word, 'bl') || str_ends_with($word, 'iz')) {
            $word .= 'e';
        } elseif (preg_match('/(bb|dd|ff|gg|mm|nn|pp|rr|tt)$/', $word)) {
            $word = substr($word, 0, -1);
        } elseif (_isShortWord($word, $r1)) {
            $word .= 'e';
        }
    }
    step1c:

    // Steg 1c
    if (strlen($word) > 2 && (str_ends_with($word, 'y') || str_ends_with($word, 'Y'))) {
        $c = $word[strlen($word) - 2];
        if (!in_array($c, ['a','e','i','o','u','A','E','I','O','U','y','Y'])) {
            $word = substr($word, 0, -1) . 'i';
        }
    }

    // Steg 2
    $step2 = [
        'ational' => 'ate', 'tional' => 'tion', 'enci' => 'ence', 'anci' => 'ance',
        'abli' => 'able', 'entli' => 'ent', 'izer' => 'ize', 'ization' => 'ize',
        'ation' => 'ate', 'ator' => 'ate', 'alism' => 'al', 'aliti' => 'al',
        'alli' => 'al', 'fulness' => 'ful', 'ousli' => 'ous', 'ousness' => 'ous',
        'iveness' => 'ive', 'iviti' => 'ive', 'biliti' => 'ble', 'bli' => 'ble',
        'fulli' => 'ful', 'lessli' => 'less',
    ];
    foreach ($step2 as $suffix => $replacement) {
        if (str_ends_with($word, $suffix)) {
            $stem = substr($word, 0, -strlen($suffix));
            if (strlen($stem) >= $r1) {
                $word = $stem . $replacement;
            }
            break;
        }
    }
    // ogi → og (om föregående tecken är 'l')
    if (str_ends_with($word, 'ogi') && strlen($word) > 3 && $word[strlen($word) - 4] === 'l') {
        $stem = substr($word, 0, -1);
        if (strlen(substr($word, 0, -3)) >= $r1) $word = $stem;
    }
    // li → (ta bort om föregående tecken är valid li-ending)
    if (str_ends_with($word, 'li') && strlen($word) > 2) {
        $c = $word[strlen($word) - 3];
        if (in_array($c, ['c','d','e','g','h','k','m','n','r','t'])) {
            $stem = substr($word, 0, -2);
            if (strlen($stem) >= $r1) $word = $stem;
        }
    }

    // Steg 3
    $step3 = [
        'ational' => 'ate', 'tional' => 'tion', 'alize' => 'al', 'icate' => 'ic',
        'iciti' => 'ic', 'ical' => 'ic', 'ful' => '', 'ness' => '',
    ];
    foreach ($step3 as $suffix => $replacement) {
        if (str_ends_with($word, $suffix)) {
            $stem = substr($word, 0, -strlen($suffix));
            if (strlen($stem) >= $r1) {
                $word = $stem . $replacement;
            }
            break;
        }
    }
    if (str_ends_with($word, 'ative')) {
        $stem = substr($word, 0, -5);
        if (strlen($stem) >= $r2) $word = $stem;
    }

    // Steg 4
    $step4 = ['ement','ance','ence','able','ible','ment','ant','ent','ism','ate','iti','ous','ive','ize','al','er','ic','ion'];
    foreach ($step4 as $suffix) {
        if (str_ends_with($word, $suffix)) {
            $stem = substr($word, 0, -strlen($suffix));
            if (strlen($stem) >= $r2) {
                if ($suffix === 'ion') {
                    if (strlen($stem) > 0 && in_array($stem[strlen($stem) - 1], ['s','t'])) {
                        $word = $stem;
                    }
                } else {
                    $word = $stem;
                }
            }
            break;
        }
    }

    // Steg 5
    if (str_ends_with($word, 'e')) {
        $stem = substr($word, 0, -1);
        if (strlen($stem) >= $r2 || (strlen($stem) >= $r1 && !_endsWithShortSyllable($stem))) {
            $word = $stem;
        }
    } elseif (str_ends_with($word, 'll') && strlen(substr($word, 0, -1)) >= $r2) {
        $word = substr($word, 0, -1);
    }

    return strtolower(str_replace('Y', 'y', $word));
}

function _isShortWord(string $word, int $r1): bool
{
    return $r1 >= strlen($word) && _endsWithShortSyllable($word);
}

function _endsWithShortSyllable(string $word): bool
{
    $len = strlen($word);
    if ($len === 2) {
        return preg_match('/^[aeiouyAEIOUY][^aeiouyAEIOUY]$/', $word) === 1;
    }
    if ($len >= 3) {
        $last3 = substr($word, -3);
        return preg_match('/[^aeiouyAEIOUY][aeiouyAEIOUY][^aeiouyAEIOUYwxY]$/', $last3) === 1;
    }
    return false;
}

// ════════════════════════════════════════════════════════════════════════════
// Qdrant-filter
// ════════════════════════════════════════════════════════════════════════════

function buildQdrantFilter(array $filters): ?array
{
    $must = [];

    // Branches – "Finns inne på": matchar available_branches (filialer med minst ett
    // tillgängligt exemplar just nu, enligt senaste dygnssynk), inte ägande filial.
    // Exakt match på kod (Qdrant lagrar koder: FALK, MOBILA, etc.)
    if (!empty($filters['branches']) && is_array($filters['branches'])) {
        $conditions = [];
        foreach ($filters['branches'] as $val) {
            // Dolda filialer kan inte väljas i UI:t – ignorera dem även via API
            if (!in_array((string)$val, VISIBLE_BRANCHES, true)) {
                continue;
            }
            $conditions[] = ['key' => 'available_branches', 'match' => ['value' => (string)$val]];
        }
        if ($conditions) {
            $must[] = count($conditions) === 1 ? $conditions[0] : ['should' => $conditions];
        }
    }

    // Media_types – matcha på både kod OCH etikett (Qdrant lagrar etiketter
    // för typer i pipelinens label-map, t.ex. "Bok" istället för "BOK",
    // men råkoder för typer utanför mapen, t.ex. "LASARLAN")
    if (!empty($filters['media_types']) && is_array($filters['media_types'])) {
        $conditions = [];
        $labels = getItemTypeLabels();
        foreach ($filters['media_types'] as $val) {
            $conditions[] = ['key' => 'media_types', 'match' => ['value' => (string)$val]];
            $label = $labels[$val] ?? null;
            if ($label !== null && $label !== $val) {
                $conditions[] = ['key' => 'media_types', 'match' => ['value' => $label]];
            }
        }
        $must[] = count($conditions) === 1 ? $conditions[0] : ['should' => $conditions];
    }

    // Författare – facettvärdet är rensat (suggestCleanAuthor), men Qdrant lagrar
    // orensad MARC-interpunktion ("Lindgren, Astrid." / "Lindgren, Astrid,").
    // Matcha exakt (keyword-index) mot alla varianter; match.text undviks eftersom
    // det är substringmatch ("Lind" skulle träffa "Lindgren").
    if (!empty($filters['author']) && is_array($filters['author'])) {
        $conditions = [];
        foreach ($filters['author'] as $val) {
            $clean = suggestCleanAuthor((string)$val);
            if ($clean === '') {
                continue;
            }
            foreach ([$clean, $clean . ',', $clean . '.', $clean . ';'] as $variant) {
                $conditions[] = ['key' => 'author', 'match' => ['value' => $variant]];
            }
        }
        if ($conditions) {
            $must[] = ['should' => $conditions];
        }
    }

    // Ämnen och taggar – text-match
    foreach (['subjects', 'tags'] as $field) {
        if (!empty($filters[$field]) && is_array($filters[$field])) {
            $conditions = [];
            foreach ($filters[$field] as $val) {
                $conditions[] = [
                    'key'   => $field,
                    'match' => ['text' => (string)$val],
                ];
            }
            if (count($conditions) === 1) {
                $must[] = $conditions[0];
            } else {
                $must[] = ['should' => $conditions];
            }
        }
    }

    // Målgrupp – text-match (fritext i Qdrant, t.ex. "Barn (5-8 år)", "Vuxna singlar")
    if (!empty($filters['target_audience']) && is_array($filters['target_audience'])) {
        $conditions = [];
        foreach ($filters['target_audience'] as $val) {
            $conditions[] = [
                'key'   => 'target_audience',
                'match' => ['text' => (string)$val],
            ];
        }
        if (count($conditions) === 1) {
            $must[] = $conditions[0];
        } else {
            $must[] = ['should' => $conditions];
        }
    }

    // Språk – exakt match på kod (Qdrant-nyckel language_code, keyword-index, t.ex. "swe", "eng")
    if (!empty($filters['language']) && is_array($filters['language'])) {
        $conditions = [];
        foreach ($filters['language'] as $val) {
            $conditions[] = ['key' => 'language_code', 'match' => ['value' => (string)$val]];
        }
        $must[] = count($conditions) === 1 ? $conditions[0] : ['should' => $conditions];
    }

    // Utgivningsår – range
    $yearRange = [];
    if (!empty($filters['publication_year_min'])) {
        $yearRange['gte'] = (int)$filters['publication_year_min'];
    }
    if (!empty($filters['publication_year_max'])) {
        $yearRange['lte'] = (int)$filters['publication_year_max'];
    }
    if (!empty($yearRange)) {
        $must[] = ['key' => 'publication_year', 'range' => $yearRange];
    }

    // Kräv alltid minst en synlig filial (exkluderar titlar som bara finns på dolda filialer)
    $branchConditions = [];
    foreach (VISIBLE_BRANCHES as $branch) {
        $branchConditions[] = ['key' => 'branches', 'match' => ['value' => $branch]];
    }
    $must[] = ['should' => $branchConditions];

    return ['must' => $must];
}

// ════════════════════════════════════════════════════════════════════════════
// Seriesökning (via Qdrant scroll)
// ════════════════════════════════════════════════════════════════════════════

function handleSeriesSearch(array $input, string $qdrantUrl, string $qdrantKey, string $imageBaseUrl): void
{
    $series  = trim($input['series'] ?? '');
    $limit   = min(max(1, (int)($input['limit'] ?? 24)), 100);
    $offset  = min(max(0, (int)($input['offset'] ?? 0)), MAX_RESULT_OFFSET);
    $filters = $input['filters'] ?? [];
    $sort    = $input['sort'] ?? 'year_desc';

    if (empty($series)) {
        jsonResponse(false, 'Serietitel krävs.', 400);
    }

    if (empty($qdrantKey)) {
        jsonResponse(false, 'Qdrant API-nyckel saknas.', 500);
    }

    // Rensa MARC-punktuation från söktermen
    $seriesClean = rtrim(trim($series), ';,/.');
    $seriesClean = trim($seriesClean);

    // Bygg filter – matcha series_title med should för att hantera
    // både rensade och orensade värden i Qdrant (match.value exakt,
    // plus vanliga MARC-varianter med trailing ; och ,)
    $qdrantFilter = buildQdrantFilter($filters);
    $seriesCondition = [
        'key' => 'series_title',
        'match' => ['value' => $seriesClean],
    ];
    $seriesVariants = [
        $seriesCondition,
        ['key' => 'series_title', 'match' => ['value' => $seriesClean . ' ;']],
        ['key' => 'series_title', 'match' => ['value' => $seriesClean . ',']],
        ['key' => 'series_title', 'match' => ['value' => $seriesClean . ' ,']],
    ];
    $seriesMust = ['should' => $seriesVariants];

    if ($qdrantFilter) {
        $qdrantFilter['must'][] = $seriesMust;
    } else {
        $qdrantFilter = ['must' => [$seriesMust]];
    }

    $scrollBody = [
        'filter'       => $qdrantFilter,
        'limit'        => $limit,
        'offset'       => $offset,
        'with_payload' => true,
        'with_vector'  => false,
    ];

    $resp = curlPost(
        "{$qdrantUrl}/collections/koha-biblios/points/scroll",
        $scrollBody,
        ['api-key: ' . $qdrantKey, 'Content-Type: application/json']
    );

    if ($resp['code'] !== 200) {
        throw new \Exception('Qdrant scroll-fel (HTTP ' . $resp['code'] . '): ' . substr($resp['body'], 0, 200));
    }

    $data   = json_decode($resp['body'], true);
    $points = $data['result']['points'] ?? [];

    $results = [];
    foreach ($points as $point) {
        $p = $point['payload'] ?? [];
        $results[] = formatBookResult($p, 0, $imageBaseUrl);
    }

    $results = filterHiddenResults($results);
    stripHiddenFromResults($results);
    sortResults($results, $sort);

    $totalFound = count($results) < $limit ? $offset + count($results) : $offset + $limit + 1;

    $facets = computeDynamicFacets($results);

    echo json_encode([
        'success'     => true,
        'results'     => $results,
        'total_found' => $totalFound,
        'series'      => $series,
        'limit'       => $limit,
        'offset'      => $offset,
        'facets'      => $facets,
    ], JSON_UNESCAPED_UNICODE);
}

// ════════════════════════════════════════════════════════════════════════════
// Autocomplete-förslag (mode=suggest)
//
// Prefixmatchar titlar/författare/serier mot ett cachat TSV-index byggt från
// Qdrant scroll. Inget OpenAI-anrop – svarar snabbt nog för varje tangenttryck.
// Indexformat: typ<TAB>norm<TAB>label<TAB>count, sorterat på count fallande
// så att skanningen kan avbrytas tidigt (populäraste posterna kommer först).
// ════════════════════════════════════════════════════════════════════════════

function handleSuggest(array $input, string $qdrantUrl, string $qdrantKey): void
{
    $q     = trim($input['q'] ?? '');
    $limit = min(max(1, (int)($input['limit'] ?? 8)), 20);

    if (mb_strlen($q) < 2) {
        echo json_encode(['success' => true, 'suggestions' => []], JSON_UNESCAPED_UNICODE);
        return;
    }

    $indexFile = __DIR__ . '/cache/suggest_index.tsv';
    $hasIndex  = ensureSuggestIndex($indexFile, 24 * 3600, $qdrantUrl, $qdrantKey);

    if (!$hasIndex) {
        // Index byggs av en annan process eller kunde inte skapas – svara tomt
        echo json_encode(['success' => true, 'suggestions' => [], 'building' => true], JSON_UNESCAPED_UNICODE);
        return;
    }

    $qn = suggestNormalize($q);

    // Skanna indexet radvis (populärast först) och samla kandidater.
    // score 2 = börjar med frågan, score 1 = något ord i posten börjar med frågan.
    $candidates = [];
    $fh = fopen($indexFile, 'r');
    if ($fh === false) {
        echo json_encode(['success' => true, 'suggestions' => []], JSON_UNESCAPED_UNICODE);
        return;
    }
    while (($line = fgets($fh)) !== false) {
        $parts = explode("\t", rtrim($line, "\n"), 4);
        if (count($parts) !== 4) {
            continue;
        }
        [$type, $norm, $label, $count] = $parts;

        // norm kan innehålla |-separerade varianter (t.ex. författare både som
        // "lindgren, astrid" och "astrid lindgren") – prefix mot varje variant
        $score = 0;
        foreach (explode('|', $norm) as $variant) {
            if (str_starts_with($variant, $qn)) {
                $score = 2;
                break;
            }
        }
        if ($score === 0 && str_contains(strtr($norm, '|', ' '), ' ' . $qn)) {
            $score = 1;
        }
        if ($score === 0) {
            continue;
        }

        $candidates[] = [
            'label' => $label,
            'type'  => $type,
            'count' => (int)$count,
            'score' => $score,
        ];
        if (count($candidates) >= 200) {
            break;
        }
    }
    fclose($fh);

    usort($candidates, function (array $a, array $b) {
        return [$b['score'], $b['count'], $a['label']] <=> [$a['score'], $a['count'], $b['label']];
    });

    // Per-typ-tak så att titlar inte tränger ut författare/serier helt
    $typeMap     = ['t' => 'title', 'a' => 'author', 's' => 'series'];
    $maxPerType  = (int)ceil($limit * 0.6);
    $typeCounts  = [];
    $suggestions = [];
    foreach ($candidates as $cand) {
        $type = $typeMap[$cand['type']] ?? $cand['type'];
        if (($typeCounts[$type] ?? 0) >= $maxPerType) {
            continue;
        }
        $typeCounts[$type] = ($typeCounts[$type] ?? 0) + 1;
        $suggestions[] = [
            'label' => $cand['label'],
            'type'  => $type,
            'count' => $cand['count'],
        ];
        if (count($suggestions) >= $limit) {
            break;
        }
    }

    echo json_encode(['success' => true, 'suggestions' => $suggestions], JSON_UNESCAPED_UNICODE);
}

/**
 * Säkerställ att förslagsindexet finns och är färskt.
 * Låsfil förhindrar att flera requests bygger samtidigt (stampede):
 * den som får låset bygger, övriga serverar den gamla filen om den finns.
 * Returnerar true om det finns en användbar indexfil.
 */
function ensureSuggestIndex(string $file, int $ttl, string $qdrantUrl, string $qdrantKey): bool
{
    if (file_exists($file) && (time() - filemtime($file)) < $ttl) {
        return true;
    }

    $cacheDir = dirname($file);
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }

    $lockFh = fopen($cacheDir . '/suggest_index.lock', 'c');
    if ($lockFh === false || !flock($lockFh, LOCK_EX | LOCK_NB)) {
        // Annan process bygger just nu – servera stale index om det finns
        if ($lockFh !== false) {
            fclose($lockFh);
        }
        return file_exists($file);
    }

    try {
        // Dubbelkolla färskheten – en annan process kan ha hunnit bygga klart
        clearstatcache(true, $file);
        if (!file_exists($file) || (time() - filemtime($file)) >= $ttl) {
            buildSuggestIndex($file, $qdrantUrl, $qdrantKey);
        }
    } catch (\Exception $e) {
        error_log('FBG Koha Search suggest-indexbygge misslyckades: ' . $e->getMessage());
    } finally {
        flock($lockFh, LOCK_UN);
        fclose($lockFh);
    }

    return file_exists($file);
}

/**
 * Bygg förslagsindexet genom att skrolla hela Qdrant-collectionen (payload only).
 * buildQdrantFilter([]) ger VISIBLE_BRANCHES-villkoret gratis, så titlar som
 * enbart finns på dolda filialer hamnar aldrig i indexet.
 */
function buildSuggestIndex(string $file, string $qdrantUrl, string $qdrantKey): void
{
    if (empty($qdrantKey)) {
        throw new \Exception('Qdrant API-nyckel saknas.');
    }

    $titles  = [];
    $authors = [];
    $series  = [];

    $offset    = null;
    $iteration = 0;

    do {
        $body = [
            'filter'       => buildQdrantFilter([]),
            'limit'        => 5000,
            'with_payload' => ['include' => ['title', 'author', 'series_title', 'media_types']],
            'with_vector'  => false,
        ];
        if ($offset !== null) {
            $body['offset'] = $offset;
        }

        $resp = curlPost(
            "{$qdrantUrl}/collections/koha-biblios/points/scroll",
            $body,
            ['api-key: ' . $qdrantKey, 'Content-Type: application/json']
        );

        if ($resp['code'] !== 200) {
            throw new \Exception('Qdrant scroll-fel (HTTP ' . $resp['code'] . '): ' . substr($resp['body'], 0, 200));
        }

        $data   = json_decode($resp['body'], true);
        $points = $data['result']['points'] ?? [];
        $offset = $data['result']['next_page_offset'] ?? null;

        foreach ($points as $point) {
            $p = $point['payload'] ?? [];

            // Hoppa över titlar vars alla exemplartyper är dolda
            $mediaTypes = $p['media_types'] ?? [];
            if (!empty($mediaTypes) && empty(array_diff($mediaTypes, HIDDEN_ITEM_TYPES))) {
                continue;
            }

            $title = suggestCleanLabel($p['title'] ?? '');
            if ($title !== '') {
                $key = suggestNormalize($title);
                if (!isset($titles[$key])) {
                    $titles[$key] = ['t', $key, $title, 0];
                }
                $titles[$key][3]++;
            }

            $author = suggestCleanAuthor($p['author'] ?? '');
            if ($author !== '') {
                $key = suggestNormalize($author);
                if (!isset($authors[$key])) {
                    // MARC lagrar "Efternamn, Förnamn" – lägg till inverterad
                    // variant så att prefixmatch träffar även "Förnamn Efternamn"
                    $norm = $key;
                    if (preg_match('/^([^,]+),\s*(.+)$/', $author, $m)) {
                        $flip = suggestNormalize($m[2] . ' ' . $m[1]);
                        if ($flip !== $norm) {
                            $norm .= '|' . $flip;
                        }
                    }
                    $authors[$key] = ['a', $norm, $author, 0];
                }
                $authors[$key][3]++;
            }

            foreach ((array)($p['series_title'] ?? []) as $serie) {
                if (!is_string($serie)) {
                    continue;
                }
                $serie = trim(rtrim(trim($serie), ';,/.'));
                if ($serie === '') {
                    continue;
                }
                $key = suggestNormalize($serie);
                if (!isset($series[$key])) {
                    $series[$key] = ['s', $key, $serie, 0];
                }
                $series[$key][3]++;
            }
        }

        $iteration++;
    } while ($offset !== null && $iteration < 30);

    // Slå ihop och sortera på förekomst (fallande) så att skanning kan avbrytas tidigt
    $all = array_merge(array_values($titles), array_values($authors), array_values($series));
    usort($all, fn(array $a, array $b) => $b[3] <=> $a[3]);

    $tmpFile = $file . '.tmp';
    $fh = fopen($tmpFile, 'w');
    if ($fh === false) {
        throw new \Exception('Kunde inte skriva förslagsindex: ' . $tmpFile);
    }
    foreach ($all as $row) {
        // Tab/radbrytning i labels skulle förstöra TSV-formatet
        $label = preg_replace('/[\t\n\r]+/', ' ', $row[2]);
        $norm  = preg_replace('/[\t\n\r]+/', ' ', $row[1]);
        fwrite($fh, $row[0] . "\t" . $norm . "\t" . $label . "\t" . $row[3] . "\n");
    }
    fclose($fh);
    rename($tmpFile, $file);
}

/**
 * Normalisera text för prefixmatchning: gemener, kollapsad whitespace och
 * vikta latinska accenter – men INTE å/ä/ö som är egna svenska bokstäver.
 * Samma normalisering används på både indexnycklar och sökfråga, därför är
 * byte-vis str_starts_with/str_contains säkert på UTF-8.
 */
function suggestNormalize(string $s): string
{
    $s = mb_strtolower($s, 'UTF-8');
    // | är reserverat som variantavgränsare i indexet
    $s = strtr($s, [
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o',
        'ñ' => 'n', 'ç' => 'c', '|' => ' ',
    ]);
    return trim(preg_replace('/\s+/u', ' ', $s));
}

/**
 * Rensa MARC-punktuation från slutet av en titel (t.ex. "Pippi Långstrump /").
 */
function suggestCleanLabel(string $s): string
{
    return trim(rtrim(trim($s), ' /:;'));
}

/**
 * Rensa MARC-punktuation från författarnamn så att varianterna
 * "Lindgren, Astrid." / "Lindgren, Astrid," / "Lindgren, Astrid" slås ihop.
 * Avslutande punkt behålls efter initialer (t.ex. "Tolkien, J. R. R.").
 */
function suggestCleanAuthor(string $s): string
{
    $s = trim(rtrim(trim($s), ' ,;'));
    return preg_replace('/(?<!\p{Lu})\.$/u', '', $s);
}

// ════════════════════════════════════════════════════════════════════════════
// Sortering
// ════════════════════════════════════════════════════════════════════════════

/**
 * Sortera resultat efter vald ordning.
 * relevance_desc = behåll Qdrant-ordning (score/RRF), year_desc/year_asc = utgivningsår,
 * added_desc/added_asc = biblio_id som proxy för katalogdatum,
 * author_asc/author_desc = författarens efternamn, title_asc/title_desc = titel.
 */
function sortResults(array &$results, string $sort): void
{
    if ($sort === 'relevance_desc' || empty($sort)) {
        return; // Standardordning från Qdrant
    }

    usort($results, function (array $a, array $b) use ($sort) {
        switch ($sort) {
            case 'year_desc':
                return ($b['publication_year'] ?? 0) <=> ($a['publication_year'] ?? 0);
            case 'year_asc':
                return ($a['publication_year'] ?? 0) <=> ($b['publication_year'] ?? 0);
            case 'added_desc':
                return ($b['biblio_id'] ?? 0) <=> ($a['biblio_id'] ?? 0);
            case 'added_asc':
                return ($a['biblio_id'] ?? 0) <=> ($b['biblio_id'] ?? 0);
            case 'author_asc':
                return strnatcasecmp(extractLastName($a['author'] ?? ''), extractLastName($b['author'] ?? ''));
            case 'author_desc':
                return strnatcasecmp(extractLastName($b['author'] ?? ''), extractLastName($a['author'] ?? ''));
            case 'title_asc':
                return strnatcasecmp($a['title'] ?? '', $b['title'] ?? '');
            case 'title_desc':
                return strnatcasecmp($b['title'] ?? '', $a['title'] ?? '');
            default:
                return 0;
        }
    });
}

/**
 * Extrahera efternamn från författarsträng.
 * Hanterar "Efternamn, Förnamn"-format (vanligast i MARC) och "Förnamn Efternamn".
 */
function extractLastName(string $author): string
{
    if (empty($author)) return '';
    // MARC-format: "Lindgren, Astrid" → efternamn = "Lindgren"
    if (str_contains($author, ',')) {
        return trim(explode(',', $author, 2)[0]);
    }
    // Omvänt: "Astrid Lindgren" → efternamn = "Lindgren"
    $parts = explode(' ', trim($author));
    return end($parts);
}

// ════════════════════════════════════════════════════════════════════════════
// Formatering
// ════════════════════════════════════════════════════════════════════════════

function formatBookResult(array $payload, float $score, string $imageBaseUrl): array
{
    $isbn = $payload['isbn'] ?? '';
    // Rensa ISBN (ta bort bindestreck etc.) för bild-URL
    $isbnClean = preg_replace('/[^0-9X]/', '', strtoupper(explode(',', str_replace('|', ',', $isbn))[0] ?? ''));

    return [
        'biblio_id'       => $payload['biblio_id'] ?? null,
        'title'           => $payload['title'] ?? 'Okänd titel',
        'author'          => $payload['author'] ?? '',
        'part_number'     => $payload['part_number'] ?? '',
        'part_name'       => $payload['part_name'] ?? '',
        'subtitle'        => $payload['subtitle'] ?? '',
        'publication_year' => $payload['publication_year'] ?? null,
        'publisher'       => $payload['publisher'] ?? '',
        'media_types'     => $payload['media_types'] ?? [],
        'target_audience' => $payload['target_audience'] ?? '',
        'language_code'   => $payload['language_code'] ?? '',
        'language'        => languageDisplayName($payload['language_code'] ?? ''),
        'subjects'        => $payload['subjects'] ?? [],
        'tags'            => $payload['tags'] ?? [],
        'branches'        => $payload['branches'] ?? [],
        'available_branches' => $payload['available_branches'] ?? [],
        'series_title'    => parseSeriesTitle($payload['series_title'] ?? null),
        'isbn'            => $isbn,
        'image_url'       => !empty($isbnClean) ? '/modules/mod_fbg_kohasearch/cover.php?isbn=' . urlencode($isbnClean) : null,
        'catalog_link'    => $payload['catalog_link'] ?? ('https://bibliotekskatalog.falkenberg.se/cgi-bin/koha/opac-detail.pl?biblionumber=' . ($payload['biblio_id'] ?? '')),
        'available_items' => $payload['available_items'] ?? 0,
        'on_order_items'  => $payload['on_order_items'] ?? 0,
        'total_items'     => $payload['total_items'] ?? 0,
        'total_holds'     => $payload['total_holds'] ?? 0,
        'score'           => round($score, 4),
    ];
}

/**
 * Ta bort resultat vars ALLA media_types är dolda.
 * Branch-filtrering sker på Qdrant-nivå (should visible branches).
 */
function filterHiddenResults(array $results): array
{
    return array_values(array_filter($results, function ($book) {
        $types = $book['media_types'] ?? [];
        if (!empty($types)) {
            $visible = array_diff($types, HIDDEN_ITEM_TYPES);
            if (empty($visible)) return false;
        }
        return true;
    }));
}

/**
 * Rensa dolda filialer/låntyper och normalisera media_types till koder.
 * Qdrant lagrar etiketter ("Bok") för typer i pipelinens map, men
 * facetter och JS arbetar med koder ("BOK"). Normalisering krävs för
 * att dynamiska facetter och ITEM_TYPE_LABELS-uppslag ska fungera.
 */
function stripHiddenFromResults(array &$results): void
{
    $labels = getItemTypeLabels();
    $labelToCode = array_flip($labels); // "Bok" → "BOK", "E-bok" → "EBOK", etc.

    foreach ($results as &$book) {
        $book['branches'] = array_values(array_diff($book['branches'] ?? [], HIDDEN_BRANCHES));
        $book['available_branches'] = array_values(array_diff($book['available_branches'] ?? [], HIDDEN_BRANCHES));

        // Normalisera media_types: etikett → kod, filtrera dolda
        $normalized = [];
        foreach ($book['media_types'] ?? [] as $type) {
            $code = $labelToCode[$type] ?? $type; // Konvertera etikett till kod om möjlig
            if (!in_array($code, HIDDEN_ITEM_TYPES) && !in_array($type, HIDDEN_ITEM_TYPES)) {
                $normalized[] = $code;
            }
        }
        $book['media_types'] = array_values(array_unique($normalized));
    }
}

/**
 * Beräkna dynamiska facetter från sökresultat.
 * Returnerar samma format som handleFacets (value/label/count)
 * så att JS kan använda dem direkt.
 */
function computeDynamicFacets(array $results): array
{
    $mediaTypeCounts = [];
    $branchCounts = [];
    $audienceCounts = [];
    $languageCounts = [];
    $authorCounts = [];

    foreach ($results as $book) {
        foreach ($book['media_types'] ?? [] as $code) {
            $mediaTypeCounts[$code] = ($mediaTypeCounts[$code] ?? 0) + 1;
        }
        // Författare – gruppera på rensat namn så MARC-varianter
        // ("Lindgren, Astrid." / "Lindgren, Astrid,") räknas som en
        $author = suggestCleanAuthor((string)($book['author'] ?? ''));
        if ($author !== '') {
            $authorCounts[$author] = ($authorCounts[$author] ?? 0) + 1;
        }
        // "Finns inne på" – räkna filialer med tillgängligt exemplar, inte ägande filial
        foreach ($book['available_branches'] ?? [] as $code) {
            $branchCounts[$code] = ($branchCounts[$code] ?? 0) + 1;
        }
        $audience = $book['target_audience'] ?? '';
        if ($audience !== '') {
            $audienceCounts[$audience] = ($audienceCounts[$audience] ?? 0) + 1;
        }
        $lang = trim((string)($book['language_code'] ?? ''));
        if ($lang !== '') {
            $languageCounts[$lang] = ($languageCounts[$lang] ?? 0) + 1;
        }
    }

    // Media types: kod → {value, label, count}
    $labels = getItemTypeLabels();
    $mediaFacets = [];
    foreach ($mediaTypeCounts as $code => $count) {
        $mediaFacets[] = [
            'value' => $code,
            'label' => $labels[$code] ?? $code,
            'count' => $count,
        ];
    }
    usort($mediaFacets, fn($a, $b) => $b['count'] <=> $a['count']);

    // Författare: rensat namn → {value, label, count}, top N efter antal (lika antal: alfabetiskt).
    // value = rensat namn; buildQdrantFilter() expanderar tillbaka till interpunktionsvarianterna.
    $authorFacets = [];
    foreach ($authorCounts as $name => $count) {
        $authorFacets[] = ['value' => (string)$name, 'label' => (string)$name, 'count' => $count];
    }
    usort($authorFacets, fn($a, $b) => ($b['count'] <=> $a['count']) ?: strnatcasecmp($a['label'], $b['label']));
    $authorFacets = array_slice($authorFacets, 0, AUTHOR_FACET_LIMIT);

    // Branches: kod → {value, label, count} – hämta namn från cachade facetter
    $branchLabels = getBranchLabels();
    $branchFacets = [];
    foreach ($branchCounts as $code => $count) {
        $branchFacets[] = [
            'value' => $code,
            'label' => $branchLabels[$code] ?? $code,
            'count' => $count,
        ];
    }
    usort($branchFacets, fn($a, $b) => $b['count'] <=> $a['count']);

    // Målgrupp: {value, label, count}
    $audienceFacets = [];
    foreach ($audienceCounts as $val => $count) {
        $audienceFacets[] = ['value' => $val, 'label' => $val, 'count' => $count];
    }
    usort($audienceFacets, fn($a, $b) => $b['count'] <=> $a['count']);

    // Språk: kod → {value, label, count}
    $languageLabels = getLanguageLabels();
    $languageFacets = [];
    foreach ($languageCounts as $code => $count) {
        $languageFacets[] = [
            'value' => $code,
            'label' => $languageLabels[$code] ?? strtoupper($code),
            'count' => $count,
        ];
    }
    usort($languageFacets, fn($a, $b) => $b['count'] <=> $a['count']);

    return [
        'media_types'     => $mediaFacets,
        'author'          => $authorFacets,
        'branches'        => $branchFacets,
        'target_audience' => $audienceFacets,
        'language'        => $languageFacets,
    ];
}

/**
 * Hämta filialnamn (kod → visningsnamn) från cachade facetter.
 */
function getBranchLabels(): array
{
    $cacheFile = __DIR__ . '/cache/facets_cache.json';
    if (file_exists($cacheFile)) {
        $data = json_decode(file_get_contents($cacheFile), true);
        $map = [];
        foreach ($data['facets']['branches'] ?? [] as $b) {
            $map[$b['value']] = $b['label'];
        }
        return $map;
    }
    return [];
}

/**
 * Normalisera series_title till array av {name, display}.
 * name = serienamn (för sökning), display = namn + ev. volym (för visning).
 * Hanterar: null, sträng, JSON-sträng, array av strängar, array av objekt {name, volume}.
 * Rensar MARC-punktuation (trailing ; , / .) och extra whitespace.
 */
function parseSeriesTitle($raw): array
{
    if ($raw === null || $raw === '' || $raw === []) {
        return [];
    }
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        $raw = is_array($decoded) ? $decoded : [$raw];
    }
    if (!is_array($raw)) {
        return [];
    }

    $seen = [];
    $result = [];
    foreach ($raw as $item) {
        $name = '';
        $volume = '';
        if (is_array($item) && !empty($item['name'])) {
            $name = $item['name'];
            $volume = $item['volume'] ?? '';
        } elseif (is_string($item)) {
            $name = $item;
        } else {
            continue;
        }
        // Rensa MARC-punktuation och whitespace
        $name = trim(rtrim(trim($name), ';,/.'));
        $volume = trim(rtrim(trim($volume), ';,/.'));
        if ($name === '') continue;

        $display = $volume !== '' ? $name . ' ' . $volume : $name;
        $key = $name . '|' . $display;
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $result[] = ['name' => $name, 'display' => $display];
    }
    return $result;
}

function formatDirectusBook(array $b, string $imageBaseUrl, int $totalHolds): array
{
    $isbn      = $b['isbn'] ?? '';
    $isbnClean = $b['isbn_clean'] ?? preg_replace('/[^0-9X]/', '', strtoupper(explode(',', str_replace('|', ',', $isbn))[0] ?? ''));

    return [
        'biblio_id'        => $b['biblio_id'] ?? null,
        'title'            => $b['title'] ?? 'Okänd titel',
        'author'           => $b['author'] ?? '',
        'part_number'      => $b['part_number'] ?? '',
        'part_name'        => $b['part_name'] ?? '',
        'subtitle'         => $b['subtitle'] ?? '',
        'publication_year' => $b['publication_year'] ?? null,
        'isbn'             => $isbn,
        'image_url'        => !empty($isbnClean) ? '/modules/mod_fbg_kohasearch/cover.php?isbn=' . urlencode($isbnClean) : null,
        'catalog_link'     => 'https://bibliotekskatalog.falkenberg.se/cgi-bin/koha/opac-detail.pl?biblionumber=' . ($b['biblio_id'] ?? ''),
        'abstract'         => $b['abstract'] ?? '',
        'language'         => languageDisplayName($b['language_code'] ?? ''),
        'total_holds'      => $totalHolds,
    ];
}

function getItemTypeLabels(): array
{
    return [
        'BOK'        => 'Bok',
        'EBOK'       => 'E-bok',
        'LJUDBOK'    => 'Ljudbok',
        'DBOK'       => 'Daisybok',
        'BARNBOK'    => 'Barnbok',
        'BARNCD'     => 'Barn-CD',
        'BARNDVD'    => 'Barn-DVD',
        'BARNSPEL'   => 'Barnspel',
        'BARNPER'    => 'Barntidskrift',
        'BARNLJUDBO' => 'Barn-ljudbok',
        'BARNLANG'   => 'Barn-lång',
        'CD'         => 'CD-skiva',
        'CDBOK'      => 'CD-bok',
        'DVD'        => 'DVD-film',
        'BLURAY'     => 'Blu-ray',
        'TIDSKRIFT'  => 'Tidskrift',
        'PER'        => 'Tidskrift',
        'SERIAL'     => 'Seriepublikation',
        'DAGSTID'    => 'Dagstidning',
        'NOTA'       => 'Musikalier/noter',
        'SPEL'       => 'Spel',
        'TVSPEL'     => 'TV-spel',
        'PUNKT'      => 'Punktskrift',
        'KARTA'      => 'Karta',
        'KART'       => 'Karta',
        'MIKROFILM'  => 'Mikrofilm',
        'MIKROFICHE' => 'Mikrofiche',
        'FILM'       => 'Film',
        'MUSIK'      => 'Musik',
        'SPRAKKURS'  => 'Språkkurs',
        'SPRAKKEDJA' => 'Språkkedja',
        'REFBOK'     => 'Referensbok',
        'REF'        => 'Referens',
        'DATABAS'    => 'Databas',
        'DEPBOK'     => 'Depositionsbok',
        'DEPOSTION'  => 'Deposition',
        'MANGMEDIA'  => 'Mångmedia',
        'MULTI'      => 'Multimedia',
        'LJUDCPMP3'  => 'Ljudbok MP3',
        'TAL'        => 'Talbok',
        'ELEK'       => 'Elektronisk resurs',
        'FOREMAL'    => 'Föremål',
        'LASARLAN'   => 'Läsårsexemplar',
        'BOKLAS'     => 'Bokläsning',
        'BOKLAD'     => 'Boklåda',
        'BOKCIRKEL'  => 'Bokcirkel',
        'GYMNOVRIG'  => 'Gymnasiet övrigt',
        'TERMINLAN'  => 'Terminslån',
        'EJHEMLAN'   => 'Ej hemlån',
        'ONORDER'    => 'Beställd',
        'FJARR'      => 'Fjärrlån',
        'FJARRX'     => 'Fjärrlån',
        'LADDARE'    => 'Laddare',
        'KONTROLL'   => 'Kontroll',
    ];
}

/**
 * ISO 639-2 (MARC 008) språkkod → svenskt namn.
 * Källa: kft_koha_biblios.language_code / Qdrant-payload language_code.
 * Fallback för okända koder hanteras av anroparen (strtoupper($code)).
 */
function getLanguageLabels(): array
{
    return [
        'swe' => 'Svenska',
        'eng' => 'Engelska',
        'dan' => 'Danska',
        'nor' => 'Norska',
        'fin' => 'Finska',
        'ger' => 'Tyska',
        'deu' => 'Tyska',
        'fre' => 'Franska',
        'fra' => 'Franska',
        'spa' => 'Spanska',
        'ita' => 'Italienska',
        'por' => 'Portugisiska',
        'nld' => 'Nederländska',
        'dut' => 'Nederländska',
        'ara' => 'Arabiska',
        'per' => 'Persiska',
        'fas' => 'Persiska',
        'rus' => 'Ryska',
        'pol' => 'Polska',
        'ukr' => 'Ukrainska',
        'som' => 'Somaliska',
        'kur' => 'Kurdiska',
        'tur' => 'Turkiska',
        'gre' => 'Grekiska',
        'ell' => 'Grekiska',
        'chi' => 'Kinesiska',
        'zho' => 'Kinesiska',
        'jpn' => 'Japanska',
        'kor' => 'Koreanska',
        'tha' => 'Thailändska',
        'vie' => 'Vietnamesiska',
        'hin' => 'Hindi',
        'urd' => 'Urdu',
        'ben' => 'Bengali',
        'tir' => 'Tigrinja',
        'amh' => 'Amhariska',
        'sqi' => 'Albanska',
        'alb' => 'Albanska',
        'srp' => 'Serbiska',
        'hrv' => 'Kroatiska',
        'bos' => 'Bosniska',
        'hun' => 'Ungerska',
        'ron' => 'Rumänska',
        'rum' => 'Rumänska',
        'ces' => 'Tjeckiska',
        'cze' => 'Tjeckiska',
        'slk' => 'Slovakiska',
        'slo' => 'Slovakiska',
        'bul' => 'Bulgariska',
        'isl' => 'Isländska',
        'ice' => 'Isländska',
        'lat' => 'Latin',
        'heb' => 'Hebreiska',
        'est' => 'Estniska',
        'lav' => 'Lettiska',
        'lit' => 'Litauiska',
        'smi' => 'Samiska',
        'mul' => 'Flera språk',
        'und' => 'Obestämt',
    ];
}

/**
 * Svenskt visningsnamn för en språkkod – men ENDAST för icke-svenska titlar.
 * Returnerar '' för tom kod och för svenska (`swe`), så att svenska titlar inte
 * får någon språkmarkering i kort/modal (minskar brus, eftersom nästan allt är svenskt).
 */
function languageDisplayName($code): string
{
    $code = trim((string)$code);
    if ($code === '' || $code === 'swe') {
        return '';
    }
    return getLanguageLabels()[$code] ?? strtoupper($code);
}

/**
 * Upptäck efterfrågat språk i en fritextfråga, t.ex. "barnbok på tyska".
 * Triggar ENDAST på det tydliga mönstret "på <språk>" för att undvika feltolkning
 * av t.ex. "engelska för nybörjare" (en svensk bok OM engelska).
 * Returnerar unik lista av ISO 639-2-koder (med synonymkoder för robusthet) som används
 * för en mjuk boost-arm i sökningen – INGET filtreras bort.
 */
function detectQueryLanguages(string $query): array
{
    static $phraseToCodes = [
        'svenska'        => ['swe'],
        'engelska'       => ['eng'],
        'arabiska'       => ['ara'],
        'franska'        => ['fre', 'fra'],
        'persiska'       => ['per', 'fas'],
        'farsi'          => ['per', 'fas'],
        'spanska'        => ['spa'],
        'tyska'          => ['ger', 'deu'],
        'danska'         => ['dan'],
        'somaliska'      => ['som'],
        'polska'         => ['pol'],
        'finska'         => ['fin'],
        'ryska'          => ['rus'],
        'kinesiska'      => ['chi', 'zho'],
        'kurdiska'       => ['kur'],
        'albanska'       => ['alb', 'sqi'],
        'japanska'       => ['jpn'],
        'italienska'     => ['ita'],
        'norska'         => ['nor', 'nob', 'nno'],
        'thailändska'    => ['tha'],
        'thai'           => ['tha'],
        'ukrainska'      => ['ukr'],
        'turkiska'       => ['tur'],
        'tigrinja'       => ['tir'],
        'portugisiska'   => ['por'],
        'koreanska'      => ['kor'],
        'isländska'      => ['ice', 'isl'],
        'serbiska'       => ['srp'],
        'urdu'           => ['urd'],
        'nederländska'   => ['dut', 'nld'],
        'holländska'     => ['dut', 'nld'],
        'ungerska'       => ['hun'],
        'bosniska'       => ['bos'],
        'hindi'          => ['hin'],
        'litauiska'      => ['lit'],
        'rumänska'       => ['rum', 'ron'],
        'hebreiska'      => ['heb'],
        'bengali'        => ['ben'],
        'kroatiska'      => ['hrv'],
        'tjeckiska'      => ['cze', 'ces'],
        'grekiska'       => ['gre', 'grc', 'ell'],
        'vietnamesiska'  => ['vie'],
        'jiddisch'       => ['yid'],
        'estniska'       => ['est'],
        'lettiska'       => ['lav'],
        'bulgariska'     => ['bul'],
        'slovakiska'     => ['slo', 'slk'],
        'latin'          => ['lat'],
    ];

    // Längsta först så att t.ex. "thailändska" matchas före "thai"
    $phrases = array_keys($phraseToCodes);
    usort($phrases, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
    $alternation = implode('|', array_map(fn($p) => preg_quote($p, '/'), $phrases));

    // "på <språk>" – ordgränser via lookarounds som funkar med å/ä/ö
    $pattern = '/(?<!\p{L})på\s+(' . $alternation . ')(?![\p{L}])/iu';

    if (!preg_match_all($pattern, $query, $matches)) {
        return [];
    }

    $codes = [];
    foreach ($matches[1] as $word) {
        $key = mb_strtolower($word);
        foreach ($phraseToCodes[$key] ?? [] as $code) {
            $codes[$code] = true;
        }
    }

    return array_keys($codes);
}

/**
 * Översätt webbläsarens språklista (BCP 47, t.ex. "sv-SE", "en", "ckb") till
 * ISO 639-2-koder som i Qdrants language_code. Tar högst fem taggar, okända
 * och ogiltiga värden ignoreras – indatan kommer direkt från klienten.
 */
function browserLanguageCodes(array $tags): array
{
    static $tagToCodes = [
        'sv' => ['swe'],
        'en' => ['eng'],
        'ar' => ['ara'],
        'fr' => ['fre', 'fra'],
        'fa' => ['per', 'fas'],
        'ps' => ['pus'],
        'es' => ['spa'],
        'de' => ['ger', 'deu'],
        'da' => ['dan'],
        'so' => ['som'],
        'pl' => ['pol'],
        'fi' => ['fin'],
        'ru' => ['rus'],
        'zh' => ['chi', 'zho'],
        'ku' => ['kur'], 'ckb' => ['kur'], 'kmr' => ['kur'],
        'sq' => ['alb', 'sqi'],
        'ja' => ['jpn'],
        'it' => ['ita'],
        'no' => ['nor', 'nob', 'nno'], 'nb' => ['nor', 'nob', 'nno'], 'nn' => ['nor', 'nob', 'nno'],
        'th' => ['tha'],
        'uk' => ['ukr'],
        'tr' => ['tur'],
        'ti' => ['tir'],
        'pt' => ['por'],
        'ko' => ['kor'],
        'is' => ['ice', 'isl'],
        'sr' => ['srp'],
        'ur' => ['urd'],
        'nl' => ['dut', 'nld'],
        'hu' => ['hun'],
        'bs' => ['bos'],
        'hi' => ['hin'],
        'lt' => ['lit'],
        'ro' => ['rum', 'ron'],
        'he' => ['heb'],
        'bn' => ['ben'],
        'hr' => ['hrv'],
        'cs' => ['cze', 'ces'],
        'el' => ['gre', 'grc', 'ell'],
        'vi' => ['vie'],
        'yi' => ['yid'],
        'et' => ['est'],
        'lv' => ['lav'],
        'bg' => ['bul'],
        'sk' => ['slo', 'slk'],
    ];

    $codes = [];
    foreach (array_slice($tags, 0, 5) as $tag) {
        if (!is_string($tag) || !preg_match('/^([a-z]{2,3})(?:-[a-z0-9-]{1,30})?$/i', $tag, $m)) {
            continue;
        }
        foreach ($tagToCodes[strtolower($m[1])] ?? [] as $code) {
            $codes[$code] = true;
        }
    }

    return array_keys($codes);
}

/**
 * Mjuk språkpreferens: multiplicera poängen med LANGUAGE_DEMOTE_FACTOR för träffar
 * vars språk varken är föredraget eller neutralt, och sortera om på poäng.
 * usort är stabil (PHP 8+), så lika poäng behåller Qdrants inbördes ordning.
 */
function applyLanguagePreference(array &$results, array $preferred): void
{
    $changed = false;
    foreach ($results as &$book) {
        $code = strtolower(trim((string)($book['language_code'] ?? '')));
        if (in_array($code, LANGUAGE_NEUTRAL_CODES, true) || in_array($code, $preferred, true)) {
            continue;
        }
        $book['score'] = ($book['score'] ?? 0) * LANGUAGE_DEMOTE_FACTOR;
        $changed = true;
    }
    unset($book);

    if ($changed) {
        usort($results, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
    }
}

// ════════════════════════════════════════════════════════════════════════════
// HTTP-hjälpare
// ════════════════════════════════════════════════════════════════════════════

function curlPost(string $url, array $data, array $headers): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 60,
    ]);

    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        throw new \Exception("cURL-fel: {$err}");
    }

    return ['code' => $code, 'body' => $body];
}

/**
 * Anrop mot Kohas REST-API med korta timeouts (KOHA_CONNECT_TIMEOUT/KOHA_TIMEOUT)
 * så att ett långsamt Koha inte binder PHP-workers. $form !== null ger en
 * formulär-POST (OAuth), annars GET. Kastar inte – nätverksfel ger code 0 med
 * 'error' satt, HTTP-koder returneras som de är och tolkas av anroparen.
 */
function kohaRequest(string $url, ?string $token, ?array $form = null): array
{
    $headers = ['Accept: application/json'];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    $ch = curl_init($url);
    $opts = [
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CONNECTTIMEOUT => KOHA_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT        => KOHA_TIMEOUT,
    ];
    if ($form !== null) {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($form);
        $opts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
    }
    curl_setopt_array($ch, $opts);

    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false || $err !== '') {
        return ['code' => 0, 'body' => '', 'error' => $err];
    }
    return ['code' => $code, 'body' => (string)$body, 'error' => ''];
}

function directusGet(string $url, string $token): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);

    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        throw new \Exception("Directus cURL-fel: {$err}");
    }

    if ($code !== 200) {
        throw new \Exception("Directus-fel (HTTP {$code}): " . substr($body, 0, 200));
    }

    return json_decode($body, true) ?: [];
}

function jsonResponse(bool $success, string $message, int $httpCode = 200): void
{
    http_response_code($httpCode);
    echo json_encode(['success' => $success, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}
