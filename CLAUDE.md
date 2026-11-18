# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

Joomla 5-modul (`mod_fbg_kohasearch`) – sök- och discovery-sida för Falkenbergs bibliotekskatalog. Semantisk sökning via Qdrant vektordatabas (~68 000 titlar), facettfiltrering, bokomslagsbilder och browse-läge. Ingen build-process – vanilla JS + PHP.

## Commands

```bash
# PHP-syntaxkontroll (enda "lint" som finns)
php -l mod_fbg_kohasearch/search.php
php -l mod_fbg_kohasearch/helper.php
php -l mod_fbg_kohasearch/mod_fbg_kohasearch.php

# Testa sökendpoint direkt
curl -X POST http://localhost/modules/mod_fbg_kohasearch/search.php \
  -H "Content-Type: application/json" \
  -d '{"query":"vikingatiden","limit":5,"mode":"search"}'

# Testa facetter
curl -X POST http://localhost/modules/mod_fbg_kohasearch/search.php \
  -H "Content-Type: application/json" \
  -d '{"mode":"facets"}'

# Testa browse
curl -X POST http://localhost/modules/mod_fbg_kohasearch/search.php \
  -H "Content-Type: application/json" \
  -d '{"mode":"browse","section":"latest","limit":5}'

# Testa recommend
curl -X POST http://localhost/modules/mod_fbg_kohasearch/search.php \
  -H "Content-Type: application/json" \
  -d '{"mode":"recommend","biblio_id":12345,"limit":5}'

# Testa recommend med ämnesfilter
curl -X POST http://localhost/modules/mod_fbg_kohasearch/search.php \
  -H "Content-Type: application/json" \
  -d '{"mode":"recommend","biblio_id":12345,"limit":5,"filters":{"subjects":["Vikingar"]}}'

# Testa författarfacett/-filter (facetten i .facets.author; filtret matchar alla MARC-interpunktionsvarianter)
curl -X POST http://localhost/modules/mod_fbg_kohasearch/search.php \
  -H "Content-Type: application/json" \
  -d '{"query":"barnbok","limit":5,"mode":"search","filters":{"author":["Lindgren, Astrid"]}}'

# Testa filialfilter ("Finns inne på" – alla träffar ska ha SLOINGE i available_branches)
curl -X POST http://localhost/modules/mod_fbg_kohasearch/search.php \
  -H "Content-Type: application/json" \
  -d '{"query":"deckare","limit":5,"mode":"search","filters":{"branches":["SLOINGE"]}}'

# Testa författarsökning (exakt match)
curl -X POST http://localhost/modules/mod_fbg_kohasearch/search.php \
  -H "Content-Type: application/json" \
  -d '{"mode":"author","author":"Astrid Lindgren","limit":5}'

# Testa seriesökning (exakt match)
curl -X POST http://localhost/modules/mod_fbg_kohasearch/search.php \
  -H "Content-Type: application/json" \
  -d '{"mode":"series","series":"Muminserien","limit":5}'

# Testa autocomplete-förslag (första anropet bygger index, tar några sekunder)
curl -X POST http://localhost/modules/mod_fbg_kohasearch/search.php \
  -H "Content-Type: application/json" \
  -d '{"mode":"suggest","q":"astr","limit":8}'

# Testa live-status (mode=availability – direkt mot Koha, kvoterad/cachad, se "Live-status" nedan).
# Titel 73948 har ett Falkenberg-exemplar på reservationshyllan → available 0, reserved 1.
curl -X POST http://localhost/modules/mod_fbg_kohasearch/search.php \
  -H "Content-Type: application/json" \
  -d '{"mode":"availability","biblio_id":73948}'

# Rensa cachade facetter/browse-data + förslagsindex
rm -f mod_fbg_kohasearch/cache/*.json mod_fbg_kohasearch/cache/*.tsv

# Rensa live-statusens cache (resultat per titel, kvotfönster, circuit breaker, OAuth-token)
rm -rf cache-live

# Underhåll: hitta poster med trasig teckenkodning (U+FFFD/ogiltig UTF-8) i Qdrant.
# Skrollar hela collectionen (~68k poster, ~1 min), tab-separerad lista med biblio_id/fält/titel.
# Felen ligger i Koha-källdatan – rätta där, nattsynken plockar upp ändringen.
php tools/scan_encoding.php [joomla-root]   # default: /home/httpd/fbg-intranet/bibliotek.falkenberg.se

# Underhåll: uppdatera placeringsetiketterna (Kohas LOC-koder → svensk text).
# Hämtar via Koha-API:t med uppgifter ur integration-koha-web/.env och skriver
# mod_fbg_kohasearch/data/locations.json – commita filen efteråt.
# Kör om när nya hyllor lagts upp i Koha.
php tools/fetch_locations.php [sökväg-till-integration-koha-web]
```

## Architecture

### Två request-vägar

**Primär:** `search.php` – direkt PHP-endpoint som bypassar Joomla. Accepterar POST med JSON-body. Läser modulconfig via PDO från Joomla DB (`configuration.php` → `#__modules`). Nio modes:
- `mode=search` – OpenAI embedding → Qdrant dense vector search → JSON-resultat
- `mode=recommend` – Qdrant recommend API med bokens point-ID → liknande böcker (inget OpenAI-anrop)
- `mode=author` – Qdrant scroll med exakt `match.value`-filter på `author` → alla böcker av en författare (inget OpenAI-anrop)
- `mode=series` – Qdrant scroll med `should`-match på `series_title` (hanterar MARC-varianter med/utan trailing `;`,`,`) → alla böcker i en serie (inget OpenAI-anrop)
- `mode=facets` – Directus aggregering → cachad 6h i `cache/facets_cache.json`
- `mode=browse` – Directus senaste/populärast → cachad 1h i `cache/browse_*.json`
- `mode=book` – Bokdetaljer från Directus (biblios + enriched) för modal-visning. Hämtar alla aktiva exemplar från `kft_koha_items` och returnerar `availability` = `[{code, label, available, total, reserved, on_order, shelves}]` per synlig filial ("Finns inne"-listan i modalen; grupperat på *hemfilial*, `aggregateBranchAvailability()`/`isItemAvailable()` speglar pipelinens tillgänglighetsvillkor inkl. hemma-kravet, se "Finns inne på" nedan). `shelves` = `[{location, callnumber}]`, se "Placering och hyllsignum" nedan. Inkluderar `reserve_link` endast när minst ett exemplar finns (annars `null`) – titlar utan exemplar går inte att reservera. `language` är översatt svenskt språknamn men sätts bara för icke-svenska titlar (tom sträng för `swe`)
- `mode=suggest` – Autocomplete-förslag (titlar/författare/serier) via prefixmatch mot cachat TSV-index (inget OpenAI-anrop). Se "Autocomplete-förslag" nedan
- `mode=availability` – "Finns inne" för en titel **just nu**, hämtat direkt från Kohas REST-API (exemplar + väntande reservationer) i stället för nattsynken. Samma `availability`-form som `mode=book`. Kvoterad, cachad och med circuit breaker – se "Live-status" nedan

**Fallback:** `helper.php` via Joomlas `com_ajax` – `ModFbgKohasearchHelper::searchAjax()`. Stöder bara search-mode.

### Sökpipeline (search.php, mode=search)

```
Sökord → generateEmbedding() [OpenAI text-embedding-3-large, 3072 dims]
       → generateSparseQueryVector() [BM25 i ren PHP: tokenize → stem → mmh3]
       → buildQdrantFilter() [must-villkor från aktiva facetter + synlighetsfilter]
       → POST /collections/koha-biblios/points/query [prefetch dense+sparse, fusion=rrf, limit=100 för facetter]
       → filterHiddenResults() → stripHiddenFromResults() [normalisera + rensa dolda]
       → computeDynamicFacets() [beräkna facetter från resultat]
       → paginera → JSON (results + facets)
```

Hybrid search med Reciprocal Rank Fusion (RRF): dense (semantisk) och sparse (BM25 termmatchning) söks separat via `prefetch`, fusioneras av Qdrant. Dense fångar begreppslikhet, sparse boostar exakta termer (namn, titlar, facktermer).

**Mjuk språk-boost:** `detectQueryLanguages()` upptäcker mönstret `på <språk>` i frågan (t.ex. "barnbok på tyska" → `['ger','deu']`, svensk fras-map → MARC-koder med synonymer). När språk upptäcks läggs en *tredje* dense-prefetch-arm till, filtrerad på `language_code` för de koderna. RRF lyfter då språkmatchande titlar tydligt utan att utesluta övriga – en mjuk boost, inte ett hårt filter (jämför den uttryckliga `language`-facetten som *är* hård `must`-match). Triggar bara på `på <språk>` för att undvika feltolkning av t.ex. "engelska för nybörjare". Upptäckta koder returneras som `detected_languages` i JSON-svaret. Ingen frontend-inblandning – varje sökning omtolkas backend-sidan.

**Mjuk språkpreferens:** egennamn ("Astrid Lindgren") ser likadana ut i alla översättningar, så utan åtgärd hamnar utgåvor på kurdiska, tigrinja m.fl. högt upp. `applyLanguagePreference()` multiplicerar därför poängen med `LANGUAGE_DEMOTE_FACTOR` (0,5) för träffar vars `language_code` inte är föredraget, och sorterar om (stabil `usort`) – före facetter och paginering. Föredragna språk = `swe` (alltid) + webbläsarens språk (`languages` i request-bodyn = `navigator.languages`, översatt BCP 47 → MARC-koder av `browserLanguageCodes()`, högst fem taggar, okända ignoreras) + koder från `detectQueryLanguages()`. `LANGUAGE_NEUTRAL_CODES` (tom, `und`, `mul`, `zxx`) viktas aldrig ned. Hoppas över vid annan sortering än relevans och när `language`-filtret är aktivt. Gäller bara `mode=search` – recommend/author/series är orörda. Använda koder returneras som `preferred_languages`. Justera styrkan med konstanten (1.0 = av) och mät med "Astrid Lindgren".

Sökningen hämtar fler resultat (100) än som visas per sida (24) för att beräkna dynamiska facetter. Facetterna returneras i JSON-svaret och visar antal träffar per mediatyp/författare/filial/målgrupp/språk baserat på sökresultaten – inte statiska databastotaler.

**Författarfacetten är enbart dynamisk.** `computeDynamicFacets()` grupperar `author` på rensat namn via `suggestCleanAuthor()` (Qdrant lagrar orensad MARC-interpunktion: "Lindgren, Astrid." / "Lindgren, Astrid,") och returnerar top `AUTHOR_FACET_LIMIT` (20) efter antal. Facettvärdet är det rensade namnet; `buildQdrantFilter()` expanderar det till fyra `match.value`-varianter (utan/med `,` `.` `;`) i en `should` – exakt keyword-match, inte `match.text` (substring). Finns inte i `handleFacets()` (statiska), så gruppen försvinner i browse-läge; i JS döljs den även i författarläge (`state.authorName`), och `handleAuthorSearch()` ignorerar `filters.author` eftersom författaren redan är ett hårt `must`.

**Filialfacetten betyder "Finns inne på", inte "ägs av".** Filternyckeln heter fortfarande `branches` (JS/API-kompatibilitet) men `buildQdrantFilter()` matchar mot payload-fältet `available_branches` – filialer där titeln har minst ett exemplar som är tillgängligt **och hemma**: ej utlånat/referens/skadat/förkommet/kasserat *och* holdingbranch = homebranch. Ett Falkenberg-exemplar som råkar stå i Slöinge (transport, återlämnat där) räknas inte som inne någonstans – Slöinge-filtret ska bara ge titlar som hör hemma i Slöinge. Samma regel i `isItemAvailable()` (modalen) och i pipelinens `aggregateItemsByBiblio()` (`available_branches`/`available_items`) – ändra på båda ställena. `computeDynamicFacets()` räknar också på `available_branches`. Datan uppdateras av nattlig sync (07:30) och är upp till ett dygn gammal; modalen kompletterar med live-status från Koha (se nedan).

**Reservationshyllan:** exemplarfälten i Koha säger inget om att ett exemplar väntar på sin låntagare – det ligger i `reserves` (`found` = `W` på hyllan, `T` i transit). Därför har `kft_koha_items` fältet `hold_status` (`W`/`T`/null) som holds-synken i integration-koha-web sätter varje natt, och `isItemAvailable()`/`aggregateItemsByBiblio()` kräver `empty(hold_status)`. `aggregateBranchAvailability()` returnerar dessutom `reserved` per filial, men modalen visar inte antalet – reserverade exemplar syns bara som ej inne ("0 av 1 inne"). `handleBookDetail()` begär `hold_status` från Directus men faller tillbaka utan fältet om Directus svarar 403 (fältet saknas) – då räknas reserverade exemplar som inne, precis som före 2026-09. Bokkorten visar "Inne på: Falkenberg, Slöinge" (`BRANCH_SHORT_LABELS` i JS) när `available_branches` är icke-tom, "Under inköp" när inget är inne men `on_order_items` > 0, annars `n/m tillgängliga`.

**Under inköp** (APP-26110005): exemplar med `not_for_loan_status = -1` (Falkenbergs NOT_LOAN: -1 Under inköp, 1 Referens, 2 Ej för utlån) som i övrigt är felfria, se `isItemOnOrder()`. De är aldrig tillgängliga men särskiljs från referens- och skadade exemplar. `aggregateBranchAvailability()` returnerar `on_order` per filial, och modalen visar "1 av 2 inne · 1 under inköp" eller bara "Under inköp" när alla filialens exemplar är beställda (klass `fbg-ks-avail-order`). Payloadfältet `on_order_items` sätts av pipelinens `isItemOnOrder()` i integration-koha-web och av `updateQdrantAvailability()` vid live-status. Ändra regeln på båda ställena.

`generateSparseQueryVector()` är en ren PHP-implementation av fastembed Qdrant/bm25 `query_embed()`: Snowball English stemmer + MurmurHash3 (PHP 8.1+ `hash('murmur3a')`). Varje unikt token får value 1.0; IDF-viktning sker i Qdrant (sparse index `modifier="idf"`).

### Recommend-pipeline (search.php, mode=recommend)

```
biblio_id → biblioIdToUuid() [UUID5(NAMESPACE_URL, "koha-biblio-{id}")]
          → buildQdrantFilter() [must-villkor + must_not för källboken]
          → POST /collections/koha-biblios/points/recommend [positive: [uuid], using: "dense"]
          → formatBookResult() → JSON
```

Kräver inget OpenAI-anrop – använder bokens befintliga vektor i Qdrant. `biblioIdToUuid()` replikerar Python-pipelinens `uuid.uuid5(uuid.NAMESPACE_URL, "koha-biblio-{id}")`. Används av "Fler som denna"-knappen i modalen.

### Författarsökning (search.php, mode=author)

```
author → buildQdrantFilter() [must: author match.value + eventuella facettfilter]
       → POST /collections/koha-biblios/points/scroll [filter, no vector]
       → usort() by publication_year desc → formatBookResult() → JSON
```

Kräver inget OpenAI-anrop – använder Qdrant scroll med exakt payload-match på `author`-fältet. Sorterar resultaten efter utgivningsår (nyast först). Används av klickbart författarnamn i bokmodalen.

### Seriesökning (search.php, mode=series)

```
series → rensa MARC-punktuation (trailing ; , / .)
       → buildQdrantFilter() [must: should(series_title match.value varianter) + facettfilter]
       → POST /collections/koha-biblios/points/scroll [filter, no vector]
       → usort() by publication_year desc → formatBookResult() → JSON
```

Kräver inget OpenAI-anrop – använder Qdrant scroll med `should`-villkor på `series_title`-fältet för att matcha både rensade och orensade värden (t.ex. `"Familjen Lundell"` och `"Familjen Lundell ;"`). Sorterar resultaten efter utgivningsår (nyast först). Används av klickbar serietitel i bokmodalen.

`series_title` lagras som keyword-array i Qdrant – en bok kan tillhöra flera serier. `parseSeriesTitle()` normaliserar data från två format: strängar (Qdrant) och objekt med `name`/`volume` (Directus). Returnerar `[{name, display}]` där `name` används för sökning och `display` för visning (kan inkludera volymnummer).

### Autocomplete-förslag (search.php, mode=suggest)

```
q → suggestNormalize() [gemener, accentviking – men INTE å/ä/ö]
  → ensureSuggestIndex() [TTL 24h, flock-låsfil mot stampede, stale-fallback]
  → skanna cache/suggest_index.tsv radvis [populärast först, avbryt vid 200 kandidater]
  → score 2 (prefix) / 1 (ordstart mitt i) → usort → per-typ-tak → JSON
```

Kräver inget OpenAI-anrop – prefixmatchar mot ett cachat TSV-index (`typ<TAB>norm<TAB>label<TAB>count`, sorterat på count fallande så skanningen kan avbrytas tidigt). Qdrants titelindex saknar prefix-tokenizer, därför eget index. `buildSuggestIndex()` skrollar hela collectionen payload-only (`buildQdrantFilter([])` ger VISIBLE_BRANCHES-synlighet; titlar vars alla `media_types` är dolda hoppas över). Bygget tar ~3 s och sker inline max en gång per dygn – `flock(LOCK_EX|LOCK_NB)` på `cache/suggest_index.lock` gör att exakt en request bygger medan övriga serverar stale fil (eller `building:true` vid kall cache); skrivs atomiskt via tmp+`rename`, misslyckat bygge loggas och gamla filen fortsätter servera. Svar: `{success, suggestions:[{label, type:'title'|'author'|'series', count}]}`. Per-typ-tak `ceil(limit*0.6)` hindrar titlar från att tränga ut författare/serier. Frågor under 2 tecken ger tom lista. Används av sökbox-layouten.

Detaljer i indexbygget: författare lagras med inverterad namnvariant (`lindgren, astrid|astrid lindgren`, `|`-separerad i norm-kolumnen) så att både "astr" och "astrid lindgren" prefixmatchar MARC-formatet "Efternamn, Förnamn"; `suggestCleanAuthor()` slår ihop interpunktionsvarianter ("Lindgren, Astrid." / ",") men bevarar punkt efter initialer; `suggestNormalize()` viker latinska accenter (é→e) men **inte å/ä/ö** som är egna svenska bokstäver. Underhåll: inget – indexet förnyar sig självt var 24:e timme (nya titlar syns med max ett dygns fördröjning i förslagen); `rm -f cache/*.tsv` tvingar ombygge vid nästa anrop.

### Live-status (search.php, mode=availability)

```
biblio_id → loadKohaEnv() [koha_env.php: OAUTH_URL/API_BASE_URL/CLIENT_ID/CLIENT_SECRET ur integration-koha-web/.env]
          → cache-live/avail_{id}.json färsk (< 90 s)? → svara cached:true
          → circuit breaker öppen? → stale cache eller live:false
          → single-flight-lås (cache-live/lock_{id % 32}, LOCK_NB) → dubbelkolla cache
          → kvot (cache-live/ratelimit.json, glidande 60 s: 30 hämtningar/min globalt, 10 per IP-hash)
          → OAuth-token (cache-live/koha_token.json, 0600, förnyas 5 min före utgång)
          → GET /biblios/{id}/items + GET /holds?q={"biblio_id":id,"status":{"-in":["W","T"]}}
          → hold_status per exemplar → aggregateBranchAvailability() [samma som mode=book]
          → skriv cache + set_payload till Qdrant (available_branches/available_items/on_order_items/total_items) → JSON
```

Öppnas automatiskt av modalen: nattdatan visas direkt, sedan byts listan till "Status just nu (hämtad kl. HH:MM)" när Koha-svaret kommer (`loadLiveAvailability()` i JS, avbryts via `AbortController` när modalen stängs eller en annan titel öppnas). Svarar **alltid HTTP 200** (utom ogiltigt `biblio_id`): `{live:true, cached, stale, fetched_at, age, not_found?, availability}` eller `{live:false, reason:'disabled'|'rate_limited'|'busy'|'koha_unavailable'|'error'}` – frontend behåller då nattdatan tyst. Aldrig 429 (undviker retry-stormar).

**Varför så många spärrar:** Koha-API:t delas med nattsynken och tål inte täta anrop – live-status får aldrig konkurrera ut synken. `search.php` har dessutom `Access-Control-Allow-Origin: *`, så endpointen kan anropas från vilken sida som helst – alla gränser måste vara server-side. Lagren i ordning: resultatcache (`LIVE_CACHE_TTL` 90 s, stale-fallback upp till `LIVE_STALE_MAX` 1 h) → circuit breaker (`LIVE_FAIL_PAUSE` 60 s efter fel/timeout, `LIVE_BLOCK_PAUSE` 300 s efter 403/429) → single-flight per titel → kvoter (`LIVE_GLOBAL_PER_MIN` 30, `LIVE_CLIENT_PER_MIN` 10; misslyckat fillås = nekad, fail closed; klientnyckel = HMAC av `REMOTE_ADDR` med Joomlas `secret`, ingen IP i klartext). Kvoten reserveras först när ett Koha-anrop faktiskt ska ske – cache-träffar är gratis. Timeouts 3 s connect / 5 s totalt (`kohaRequest()`, kastar inte – code 0 vid nätverksfel). 401 kastar tokenfilen.

**Qdrant-återskrivningen** (`updateQdrantAvailability()`) gör att bokkort och "Finns inne på"-facetten stämmer resten av dagen för titlar som öppnats. `payload_hash` sätts till `live-<ts>` så att nattsynkens payload-diff alltid skriver över nästa morgon (annars kunde ett inaktuellt live-värde ligga kvar tills något annat ändras).

**Filer:** allt ligger i `cache-live/` i repo-roten – utanför webbroten eftersom OAuth-tokenen sparas där (bara `mod_fbg_kohasearch/` är symlänkad in). `mod_fbg_kohasearch/cache/` skyddas dessutom av `.htaccess` (`Require all denied`). Ingen Koha-konfiguration i modulparams: saknas `.env` eller någon variabel är live-status av (`liveAvailability: false` i `fbgKohasearchConfig`, JS skickar inget anrop). Underhåll: inget – `rm -rf cache-live` nollställer allt.

### Två layouter: default (fullmodul/SERP) och searchbox

Layout väljs per modulinstans i Joomla admin (fältet `layout` i XML:en, Avancerat-fliken). `mod_fbg_kohasearch.php` grenar på layoutvärdet och laddar olika assets. **OBS:** Joomla sparar värdet med template-prefix (`_:searchbox` = modulens egen layout, `mallnamn:searchbox` = template-override) – entry-filen strippar prefixet före jämförelsen (`$layoutName`), medan `ModuleHelper::getLayoutPath()` tar det råa värdet och hanterar prefixet själv.

- **`default`** – hela sök- och discovery-sidan (kohasearch.js + kohasearch.css, config-global `fbgKohasearchConfig`). Har **deep-link-stöd**: `?q=sökterm` i URL:en förifyller sökfältet och kör sökningen direkt vid sidladdning; browse-sliders laddas då lazy först vid "Tillbaka" (`state.browseLoaded`). `startSearch()`/`showBrowseMode()` synkar `?q=` via `history.replaceState` (`updateUrlQuery()`) så sökningar är delbara.
- **`searchbox`** – kompakt sökbox för t.ex. startsidan (`tmpl/searchbox.php`, kohasearch-box.js + kohasearch-box.css, config-global `fbgKohasearchBoxConfig`, namnrymd `fbg-ksb-*`). Riktigt GET-formulär med `action=<serp_url>` och `name=q` – Enter fungerar utan JS. JS:et lägger på debouncad (200 ms) autocomplete via `mode=suggest` med `AbortController`, tangentbordsnavigering (pil/Enter/Escape) och ARIA combobox-mönster. Params: `serp_url` (mål-sida, ren sökväg utan query-string – GET-submit tappar query-parametrar i action) och `suggest_limit`.

**Sökboxens CSS måste vinna över YOOtheme-temats sektionsregler.** Temat ger inputfält i färgade sektioner (`uk-section-primary`/`uk-section-secondary`/`uk-light`) halvtransparent bakgrund med vit text (t.ex. vitt på brunt `#8d734b` ≈ 3:1 – underkänt för WCAG) och släcker fokus-outline globalt (`outline:0`). Inputreglerna i kohasearch-box.css använder därför tre klasser (`.mod-fbg-kohasearch-box .fbg-ksb-search-row .uk-input`) – samma specificitet som temats `.uk-section-*:not(.uk-preserve-color) .uk-input`, och modulens CSS laddas efter temats så lika specificitet vinner. WCAG-val i CSS:en: vit inputbakgrund + `#202020`-text oavsett sektion, egen 3px fokus-outline, placeholder `#666` (5,7:1), typbadge `#595959` (7:1), tangentbordsmarkerad förslagsrad i temats primärgrön `#003322` med vit text (14:1 – enda fokusindikationen vid pilnavigering).

Flera modulinstanser fungerar: varje instans skickar sitt `module_id` i alla POST-anrop så `search.php` läser rätt params. De två layouterna har separata config-globaler och ID-namnrymder och kan samexistera på samma sida; två instanser av *samma* layout på en sida stöds däremot inte (hårdkodade DOM-id:n).

### Dolda filialer och låntyper

Vissa filialer och item types döljs från både filter och sökresultat via konstanter i `search.php`. Titlar som *enbart* finns på dolda filialer eller med dolda låntyper exkluderas helt.

**Dolda filialer** (`HIDDEN_BRANCHES`): GYMNASIET, FJARRLAN, LASFRAMJAN, SKOGSTORP, MUSEET
**Synliga filialer** (`VISIBLE_BRANCHES`): FALK, MOBILA, SLOINGE, ULLARED, VESSIGEBRO, ATRAN

**Dolda item types** (`HIDDEN_ITEM_TYPES`): LASARLAN, GYMNOVRIG, BOKLAS, TERMINLAN, EJHEMLAN, BARNLANG, FJARR, FJARRX, BOKLAD, DEPOSTION, KONTROLL, ELEK – plus deras svenska etiketter för robusthet.

Tre filtreringslager:
1. **Qdrant-nivå**: `buildQdrantFilter()` lägger alltid till `should` med `VISIBLE_BRANCHES` på `branches` (ägande filial) – titlar utan synlig filial returneras aldrig. Användarens filialfilter går däremot mot `available_branches` (se "Finns inne på" ovan); `stripHiddenFromResults()` rensar dolda koder från båda fälten
2. **PHP-postprocessing**: `filterHiddenResults()` tar bort titlar vars alla `media_types` är dolda; `stripHiddenFromResults()` rensar dolda värden från visningsdata och normaliserar `media_types` från Qdrant-etiketter till koder
3. **Facetter**: `handleFacets()` filtrerar bort dolda värden innan cachelagring

**OBS – media_types kod/etikett-mismatch**: Sync-pipelinen (`prepare_embedding_text.php`) lagrar media_types som svenska etiketter ("Bok") för typer i sin `$ITEM_TYPE_LABELS`-map, men som råkoder ("LASARLAN") för typer utanför mapen. Facetter och JS arbetar med koder. `buildQdrantFilter()` söker därför på *både* kod och etikett (via `should`) för media_types-filter. `stripHiddenFromResults()` normaliserar alla media_types till koder via `array_flip(getItemTypeLabels())`.

### Placering och hyllsignum

Bokmodalens "Finns inne"-lista visar var i huset boken står: `Falkenberg: Spänning · Hce — 1 av 2 inne`. Datan är per *exemplar*, inte per titel – `kft_koha_items` har `location`/`permanent_location` (Kohas placeringskod) och `callnumber` (hyllsignum). `aggregateBranchAvailability()` samlar distinkta `{location, callnumber}`-par per filial i `shelves`, max `SHELF_LIMIT` (3).

Regler i `aggregateBranchAvailability()`/`itemShelf()`:
- **Tillgängliga exemplar först.** `shelves` byggs på de exemplar som är inne (det är dem låntagaren kan hämta). Saknas tillgängliga exemplar helt används alla exemplar, så hyllan syns ändå för en utlånad titel.
- **Koden `X` = ingen särskild placering** (~43 000 exemplar, vanligast). Den saknar etikett i Koha och ger tom `location` – då visas bara signum. Har filialen *någon* riktig placering filtreras de placeringslösa raderna bort helt, annars blir det `Sv-språk · Hcf, Hcf`.
- Okända koder (finns inte i LOC-mappen) behandlas som `X` – hellre ingen placering än en obegriplig kod.

**Etiketterna är en statisk map: `data/locations.json`** (kod → svensk text, 139 st), läst av `getLocationLabels()` med `static`-cache eftersom den slås upp en gång per exemplar. Koderna är Kohas auktoriserade värden i kategorin `LOC`; sökmodulen har ingen egen Koha-anslutning (bara Qdrant + Directus), så filen genereras av `tools/fetch_locations.php` och checkas in. Nya hyllor i Koha syns först när skriptet körts om. I JS sätter `formatShelves()` ihop raden (`" · "` mellan placering och signum, `", "` mellan hyllor) och tål `shelves: undefined` från äldre cachade svar.

### Qdrant collection: `koha-biblios`

Använder **named vectors** – sökfrågor måste ange `"vector": {"name": "dense", "vector": [...]}`, inte bara `"vector": [...]`.

- `dense`: OpenAI text-embedding-3-large, 3072 dims, cosine distance
- `sparse`: BM25 via fastembed (sync) / ren PHP (sökning), modifier="idf"
- 14 payload-index för filtrering: `biblio_id` (int), `title` (fulltext/multilingual), `author`, `isbn`, `publication_year` (int), `publisher`, `media_types`, `target_audience`, `subjects`, `tags`, `branches`, `available_branches`, `series_title`, `language_code` (keyword). `branches` = hemfilial för alla aktiva exemplar; `available_branches` = hemfilial för exemplar som är tillgängliga och hemma (driver filialfacetten). (Sync-pipelinen indexerar även `genre_form`, `subjects_marc`, `sab_classification`, `contributors` – ännu oanvända av sökmodulen.)
- `language_code`: ISO 639-2 (MARC 008 pos 35-37, t.ex. `swe`/`eng`). Driver språkfacetten (`language`-filter → Qdrant-nyckel `language_code`, exakt match), språkraden i bokmodalen och en språkbadge på bokkorten (sök + browse-sliders). Kod→svenskt namn via `getLanguageLabels()` (PHP) / `LANGUAGE_LABELS` (JS). Visningsnamnet sätts av `languageDisplayName()` som returnerar **tom sträng för svenska** (`swe`), så språk visas bara för icke-svenska titlar i modal/kort; facetten listar dock alla språk inkl. svenska. Korten får `language` från både `formatBookResult()` (Qdrant-sök) och `formatDirectusBook()` (browse).
- Titelfält i payload: `title` (245$a), `part_number` (245$n), `part_name` (245$p), `subtitle` (245$b) – lagras separat, sammansätts i JS via `buildFullTitle()`

### Bildcachning via `cover.php`

On-demand bildproxy: `cover.php?isbn=X` serverar cachade bilder (37ms) eller laddar ner från Syndetics vid first miss (~500ms).

- Fysisk cache: `/home/httpd/fbg-intranet/integrationer/integration-koha-web/images/{isbn}.jpg` (samma katalog som integration-koha-web)
- Källa: Syndetics (`secure.syndetics.com`), klient: `bibfalken`
- Cachad bild serveras med `Cache-Control: public, max-age=604800` (7 dagar)
- 404 cachas i webbläsaren 1 dag (undviker upprepad nedladdning)
- Nedladdningar från Syndetics är kvoterade: `COVER_DOWNLOADS_PER_MIN` (60/min globalt, glidande fönster i `cache-live/cover_ratelimit.json`, fail closed). Över kvoten svarar `cover.php` 503 med `no-store` – JS visar placeholder och bilden hämtas vid nästa sidvisning. Cachade bilder räknas inte
- JS har `onerror`-fallback som visar mediatypanpassad SVG-placeholder om bild saknas (bok, hörlurar, filmrulle, tidning, spelkontroll, karta, noter, skärm)
- Alla `image_url` i JSON-svar pekar på `/modules/mod_fbg_kohasearch/cover.php?isbn=X`

### JavaScript (kohasearch.js)

Vanilla JS IIFE. State-objekt styr mode (`browse`/`search`). Flöde:
1. `init()` → ladda statiska facetter (`state.staticFacets`) + browse-sliders parallellt
2. Användare söker → `doSearch()` → POST `search.php` → `applyDynamicFacets()` (uppdaterar facett-panelen med resultatbaserade räknevärden) + `renderResults()` (CSS Grid med bokkort) + `renderPagination()`
3. Filter-ändring → uppdatera `state.activeFilters` → `doSearch(true)` (resetta sida)
4. Klick på bokkort → `openBookModal()` → POST `mode=book` → UIkit modal med detaljer, taggar, abstract och "Finns inne"-lista (`availability` per filial med placering + hyllsignum, och notis om dygnssynk). Därefter `loadLiveAvailability()` → POST `mode=availability` → listan byts till Koha-status just nu (`renderAvailabilityList()` delas av båda), notisen får klass `is-live`. "Reservera"-knapp (länk till Kohas `opac-reserve.pl`, öppnas i nytt fönster) renderas endast om svaret innehåller `reserve_link` – dvs. om boken har minst ett inregistrerat exemplar. "Öppna i bibliotekskatalogen"-länken visas alltid.
5. "Fler som denna" i modalen → stänger modal → `doRecommend()` → POST `mode=recommend` med bokens `biblio_id`
6. Klick på författarnamn i modalen → stänger modal → `doAuthorSearch()` → POST `mode=author` med exakt författarnamn. Använder Qdrant scroll (ingen embedding).
7. Klick på serietitel i modalen → stänger modal → `doSeriesSearch()` → POST `mode=series` med serienamn. Använder Qdrant scroll med should-varianter (ingen embedding). En bok kan ha flera serier, visas med ev. volymnummer.
8. Ny sökning via sökrutan rensar alla aktiva filter (inkl. de som sattes av tagg/ämne/serie-klick).
9. Klick på ämne/tagg i modalen → stänger modal → `doSearch()` med termen som query + payload-filter (`subjects`/`tags`). Prioriterar semantisk närhet till ämnet/taggen, inte vektornärhet till källboken.
10. Tillbaka till browse → `showBrowseMode()` → `restoreStaticFacets()` återställer facetter till databastotaler.

**Facett-state**: `state.staticFacets` (globala databastotaler, laddas vid init) och `state.facets` (aktuella facetter, dynamiska under sökning). `applyDynamicFacets()` uppdaterar `state.facets` med resultatbaserade räknevärden men behåller utgivningsår från statiska facetter. `restoreStaticFacets()` återställer vid browse-läge. En ny facettnyckel måste registreras på tre ställen: allow-listan i `applyDynamicFacets()`, if-kedjan i `renderFacets()` (bestämmer visningsordningen) och chip-listan i `renderActiveFilters()` (chips + mobilbadge).

**Omkörning vid filter-/sorteringsändring**: `rerunCurrentSearch()` kör om den aktuella sökningen från sidan 0 i rätt läge (serie → `doSeriesSearch`, författare → `doAuthorSearch`, rekommendation → `doRecommend`, annars `doSearch`). Alla facett-, år-, chip- och sorteringshändelser går via den – anropa aldrig `doSearch()` direkt därifrån, eftersom `state.query` är tom i författar-/serieläge (ger 400) och titeln i rekommendationsläge (byter tyst till semantisk sökning).

**Titelvisning**: `buildFullTitle(book)` sätter ihop MARC 245-delfält i korrekt ordning: title ($a) + part_number ($n) + part_name ($p). Subtitle ($b) visas separat i `<span class="fbg-ks-subtitle">`. Alla fyra fält lagras separat genom hela pipelinen (Koha → Directus → Qdrant → JSON) och sammansätts först i JS.

UIkit 3-beroende: sliders (`uk-slider`), grid, ikoner, alerts, modaler (`uk-modal`).

### Responsiv filterpanel

På mobil (< 960px) döljs facettfilter bakom en kompakt "Filter"-knapp med badge som visar antal aktiva filter. Togglear panelens synlighet via CSS-klass `fbg-ks-facets-open`. På desktop visas filterpanelen som vanligt i sidopanelen.

## Configuration

API-nycklar lagras i Joomla-modulens params (databasen, inte filer). Konfigureras i Joomla admin → Extensions → Modules → FBG Koha Search.

Nödvändiga params: `qdrant_url`, `qdrant_api_key`, `openai_api_key`, `directus_url`, `directus_token`, `image_base_url`

Koha-uppgifter för live-status (mode=availability) ligger **inte** i params utan läses ur `/home/httpd/fbg-intranet/integrationer/integration-koha-web/.env` (`OAUTH_URL`, `API_BASE_URL`, `CLIENT_ID`, `CLIENT_SECRET`) via `koha_env.php` – samma fil som nattsynken använder. Filen ska bara vara läsbar för det konto PHP kör som.

## Deployment

Symlänk från Joomla-installation till detta repo. Ändringar slår igenom direkt.

```
/path/to/joomla/modules/mod_fbg_kohasearch → .../koha-hybdrid-search/mod_fbg_kohasearch
```

CSS/JS-assets laddas med `?v=N` i `mod_fbg_kohasearch.php` – öka N vid ändringar.

`cache-live/` (live-statusens filer) skapas automatiskt av `search.php` med 0700 och är gitignorerad.

## Code Conventions

- Följer `mod_fbg_digitalguide`-mönstret för Joomla-specifika mönster
- AJAX-metoder slutar på `Ajax` (t.ex. `searchAjax`)
- Helper-klassen: `ModFbgKohasearchHelper`
- JS-config: globalt objekt `fbgKohasearchConfig` (default-layout) resp. `fbgKohasearchBoxConfig` (searchbox-layout) via `addScriptDeclaration()`
- Svenska kommentarer och UI-text
- **Felmeddelanden till klienten är generiska.** Undantag bär svar från Qdrant/Directus/OpenAI, PDO-fel och interna sökvägar – de går till `error_log()`, aldrig till JSON-svaret. Valideringsfel svaras direkt med `jsonResponse(false, …, 400)` i stället för att kastas
- `section` i `mode=browse` är en tillåt-lista (`latest`/`popular`) eftersom värdet hamnar i cachefilnamnet – nya sektioner läggs till där
- `JSON_UNESCAPED_UNICODE` i alla `json_encode()`
- **Alla `const` i `search.php` ska stå i blocket överst (före dispatchen, ~rad 20–60).** Funktioner hissas upp men toppnivå-`const` gör det inte: en konstant längre ned är odefinierad när `switch ($mode)` anropar funktionen. Det ger HTTP 500 i `mode=book`/`availability`. `php -l` fångar det inte, så röktesta efter driftsättning med `mode=book` och `mode=availability` (t.ex. biblio 76221, under inköp) och läs webbserverns fellogg.
- Qdrant filter: `should` inom `must` för OR-logik per facett
- `escapeHtml()` escapar alla fem farliga tecken (`<`, `>`, `&`, `"`, `'`) – säkert för både textinnehåll och attributvärden
- `placeholderHtml(title, mediaType)` returnerar ren HTML; `placeholderHtmlAttr()` gör extra `"`/`'`-escape för `onerror`-attribut

## Related Projects

- `/home/httpd/fbg-intranet/integrationer/integration-koha-web/` – Koha→Directus→Qdrant sync pipeline, bildcache, JS-widget (`koha-shelf.js`)
- `/home/httpd/fbg-intranet/joomlaextensions/buf-digitalguide/` – RAG-sökmodul (samma Joomla-modulsmönster)
