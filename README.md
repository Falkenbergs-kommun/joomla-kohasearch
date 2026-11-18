# FBG Koha Search – Bibliotekskatalog discovery

Joomla 5-modul för att söka och botanisera i Falkenbergs bibliotekskatalog. Semantisk sökning med AI-embeddings mot ~68 000 titlar.

## Funktioner

- **Semantisk sökning** – förstår innebörden, inte bara nyckelord ("mysiga böcker om djur" fungerar)
- **Browse-läge** – startsidan visar "Senaste nyförvärv" och "Populärast just nu" som sliders
- **Bokmodal** – klick på en bok visar detaljer, AI-berikad beskrivning, ämnen och taggar i en modal
- **"Finns inne"** – modalen visar per filial hur många exemplar som är inne och var de står, t.ex. `Falkenberg: Spänning · Hce — 1 av 2 inne`. Nattsynkens data visas direkt och byts sedan mot **status just nu** från Kohas API (exemplar på reservationshyllan räknas inte som inne). Live-anropen är kvoterade och cachade så att Koha aldrig kan överbelastas via sidan
- **"Under inköp"**: beställda exemplar (Kohas NOT_LOAN `-1`) visas som "Under inköp" på bokkortet och som "1 av 2 inne · 1 under inköp" i modalen, i stället för att se ut som "ej tillgänglig" (sedan 2026-11-13, ärende APP-26110005)
- **"Fler som denna"** – sök efter liknande böcker direkt från bokmodalen
- **Facettfiltrering** – filtrera på mediatyp, "Finns inne på" (filialer med tillgängligt exemplar på sin hemfilial), målgrupp, språk och utgivningsår
- **Språkpreferens** – titlar på svenska och på webbläsarens språk visas först; övriga språk flyttas ned men filtreras inte bort. "på kurdiska" i sökfrågan eller språkfiltret lyfter fram ett visst språk
- **Bokomslagsbilder** – on-demand bildproxy (`cover.php`) med lokal Syndetics-cachning
- **Responsivt** – 4→3→2 kolumner beroende på skärmbredd, dark mode-stöd

## Teknikstack

| Komponent | Teknik |
|-----------|--------|
| Sökning | OpenAI `text-embedding-3-large` → Qdrant vektordatabas |
| Metadata | Directus (facetter, browse-data) |
| Live-status | Koha REST API (OAuth2, uppgifter ur `integration-koha-web/.env`) |
| Frontend | Vanilla JS, UIkit 3, CSS Grid |
| Backend | PHP (direkt endpoint, ingen build-process) |
| Bilder | Syndetics → lokal cache i `integration-koha-web/images/` |

## Installation

1. **Symlänk** modulkatalogen till Joomla:
   ```bash
   ln -s /home/httpd/fbg-intranet/joomlaextensions/koha-hybdrid-search/mod_fbg_kohasearch \
         /path/to/joomla/modules/mod_fbg_kohasearch
   ```

2. **Discover & install** i Joomla admin → Extensions → Manage → Discover

3. **Konfigurera** API-nycklar i modulens inställningar:
   - Qdrant URL + API-nyckel
   - OpenAI API-nyckel
   - Directus URL + token

4. **Placera** modulen på önskad sida via Joomla admin → Modules

## Beroenden

- **Qdrant** med collection `koha-biblios` (synkas dagligen via [integration-koha-web](https://github.com/Falkenbergs-kommun/integration-koha-web))
- **Directus** med kollektionerna `kft_koha_biblios`, `kft_koha_items`, `kft_koha_branches`, `kft_koha_enriched`, `kft_koha_hold_counts`
- **OpenAI API** för att generera sök-embeddings
- **UIkit 3** (ingår i Joomla/YOOtheme Pro)

Placeringsetiketterna (Kohas `LOC`-koder → svensk text) ligger som en statisk map i
`mod_fbg_kohasearch/data/locations.json`. Kör `php tools/fetch_locations.php` för att
uppdatera den från Koha-API:t när nya hyllor lagts upp.
