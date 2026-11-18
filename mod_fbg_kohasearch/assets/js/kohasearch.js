/**
 * FBG Koha Search – Bibliotekskatalog discovery
 *
 * Semantisk sökning i Qdrant med facetter, browse-läge och bokkort.
 * Vanilla JS, UIkit 3-kompatibel, ingen build-process.
 *
 * @version 1.0.0
 * @license MIT
 */
(function () {
    'use strict';

    // ── Konfiguration ────────────────────────────────────────────────
    var config = window.fbgKohasearchConfig || {};
    var SEARCH_URL     = config.searchUrl || '/modules/mod_fbg_kohasearch/search.php';
    var IMAGE_BASE_URL = config.imageBaseUrl || 'https://bibliotek.falkenberg.se/fbg_apps/services/koha/images/';
    var PER_PAGE       = config.resultsPerPage || 24;
    var SHOW_FACETS    = config.showFacets !== false;
    var SHOW_AVAIL     = config.showAvailability !== false;
    var LIVE_AVAIL     = config.liveAvailability === true; // mode=availability påslaget (Koha-uppgifter finns)
    var MODULE_ID      = config.moduleId || 0;

    var PLACEHOLDER_SVGS = {
        // Bok (standard)
        book: '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="#ccc" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>',
        // Hörlurar (ljudbok, musik, CD, språkkurs)
        headphones: '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="#ccc" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 18v-6a9 9 0 0 1 18 0v6"/><path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"/></svg>',
        // Film (DVD, Blu-ray, film)
        film: '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="#ccc" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="20" rx="2.18" ry="2.18"/><line x1="7" y1="2" x2="7" y2="22"/><line x1="17" y1="2" x2="17" y2="22"/><line x1="2" y1="12" x2="22" y2="12"/><line x1="2" y1="7" x2="7" y2="7"/><line x1="2" y1="17" x2="7" y2="17"/><line x1="17" y1="17" x2="22" y2="17"/><line x1="17" y1="7" x2="22" y2="7"/></svg>',
        // Tidskrift / dagstidning / seriepublikation
        newspaper: '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="#ccc" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 22h16a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2H8a2 2 0 0 0-2 2v16a2 2 0 0 1-2 2zm0 0a2 2 0 0 1-2-2v-9c0-1.1.9-2 2-2h2"/><line x1="10" y1="6" x2="18" y2="6"/><line x1="10" y1="10" x2="18" y2="10"/><line x1="10" y1="14" x2="14" y2="14"/></svg>',
        // Spel / barnspel
        gamepad: '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="#ccc" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><line x1="6" y1="12" x2="10" y2="12"/><line x1="8" y1="10" x2="8" y2="14"/><line x1="15" y1="13" x2="15.01" y2="13"/><line x1="18" y1="11" x2="18.01" y2="11"/><path d="M17.32 5H6.68a4 4 0 0 0-3.978 3.59c-.006.052-.01.101-.017.152C2.604 9.416 2 14.456 2 16a3 3 0 0 0 3 3c1 0 1.5-.5 2-1l1.414-1.414A2 2 0 0 1 9.828 16h4.344a2 2 0 0 1 1.414.586L17 18c.5.5 1 1 2 1a3 3 0 0 0 3-3c0-1.544-.604-6.584-.685-7.258-.007-.05-.011-.1-.017-.151A4 4 0 0 0 17.32 5z"/></svg>',
        // Karta
        map: '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="#ccc" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/><line x1="8" y1="2" x2="8" y2="18"/><line x1="16" y1="6" x2="16" y2="22"/></svg>',
        // Noter
        music: '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="#ccc" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>',
        // E-bok / databas / digitalt
        monitor: '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="#ccc" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>'
    };

    // Mappar mediatyp till placeholder-ikon. Matchar BÅDE råkoder (facetter)
    // och svenska labels (Qdrant-payload/sökresultat).
    var MEDIA_TYPE_SVG_MAP = {
        // Böcker (kod + label)
        'BOK': 'book', 'Bok': 'book',
        'BARNBOK': 'book', 'Barnbok': 'book',
        'REF': 'book', 'Referens': 'book',
        'DEPOSTION': 'book', 'Deposition': 'book',
        'PUNKT': 'book', 'Punktskrift': 'book',
        'LASARLAN': 'book', 'Läsårsexemplar': 'book',
        'GYMNOVRIG': 'book', 'Gymnasiet övrigt': 'book',
        'BOKLAS': 'book', 'Bokläsning': 'book',
        'TERMINLAN': 'book', 'Terminslån': 'book',
        'EJHEMLAN': 'book', 'Ej hemlån': 'book',
        'BARNLANG': 'book', 'Barn-lång': 'book',
        'BOKLAD': 'book', 'Boklåda': 'book',
        'BOKCIRKEL': 'book', 'Bokcirkel': 'book',
        'CDBOK': 'book', 'CD-bok': 'book',
        // Ljud (kod + label)
        'LJUDCPMP3': 'headphones', 'Ljudbok MP3': 'headphones',
        'BARNLJUDBO': 'headphones', 'Barn-ljudbok': 'headphones',
        'TAL': 'headphones', 'Talbok': 'headphones',
        'SPRAKKEDJA': 'headphones', 'Språkkedja': 'headphones',
        // Film (kod + label)
        'DVD': 'film', 'DVD-film': 'film',
        'BARNDVD': 'film', 'Barn-DVD': 'film',
        'MIKROFILM': 'film', 'Mikrofilm': 'film',
        'MIKROFICHE': 'film', 'Mikrofiche': 'film',
        // Tidskrift (kod + label)
        'PER': 'newspaper', 'Tidskrift': 'newspaper',
        'BARNPER': 'newspaper', 'Barntidskrift': 'newspaper',
        // Spel (kod + label)
        'TVSPEL': 'gamepad', 'TV-spel': 'gamepad',
        // Karta (kod + label)
        'KART': 'map', 'Karta': 'map',
        // Digitalt (kod + label)
        'ELEK': 'monitor', 'Elektronisk resurs': 'monitor',
        'MULTI': 'monitor', 'Multimedia': 'monitor'
    };

    // Labels för facett-badges (kod → visningsnamn)
    var ITEM_TYPE_LABELS = {
        'BOK':'Bok','BARNBOK':'Barnbok','LASARLAN':'Läsårsexemplar',
        'GYMNOVRIG':'Gymnasiet övrigt','BOKLAS':'Bokläsning',
        'ELEK':'Elektronisk resurs','PER':'Tidskrift',
        'LJUDCPMP3':'Ljudbok MP3','TERMINLAN':'Terminslån',
        'TAL':'Talbok','REF':'Referens','MIKROFILM':'Mikrofilm',
        'BARNPER':'Barntidskrift','BARNLJUDBO':'Barn-ljudbok',
        'CDBOK':'CD-bok','DVD':'DVD-film','FOREMAL':'Föremål',
        'BARNDVD':'Barn-DVD','EJHEMLAN':'Ej hemlån',
        'BARNLANG':'Barn-lång','TVSPEL':'TV-spel',
        'BOKLAD':'Boklåda','BOKCIRKEL':'Bokcirkel',
        'SPRAKKEDJA':'Språkkedja','MULTI':'Multimedia',
        'DEPOSTION':'Deposition','KART':'Karta','PUNKT':'Punktskrift'
    };

    // Språk-etiketter (ISO 639-2 → svenskt namn) – speglar getLanguageLabels() i search.php.
    // Används för filter-chip-etiketten (facettlistan får färdiga etiketter från backend).
    var LANGUAGE_LABELS = {
        'swe':'Svenska','eng':'Engelska','dan':'Danska','nor':'Norska',
        'fin':'Finska','ger':'Tyska','deu':'Tyska','fre':'Franska','fra':'Franska',
        'spa':'Spanska','ita':'Italienska','por':'Portugisiska',
        'nld':'Nederländska','dut':'Nederländska','ara':'Arabiska',
        'per':'Persiska','fas':'Persiska','rus':'Ryska','pol':'Polska',
        'ukr':'Ukrainska','som':'Somaliska','kur':'Kurdiska','tur':'Turkiska',
        'gre':'Grekiska','ell':'Grekiska','chi':'Kinesiska','zho':'Kinesiska',
        'jpn':'Japanska','kor':'Koreanska','tha':'Thailändska','vie':'Vietnamesiska',
        'hin':'Hindi','urd':'Urdu','ben':'Bengali','tir':'Tigrinja','amh':'Amhariska',
        'sqi':'Albanska','alb':'Albanska','srp':'Serbiska','hrv':'Kroatiska',
        'bos':'Bosniska','hun':'Ungerska','ron':'Rumänska','rum':'Rumänska',
        'ces':'Tjeckiska','cze':'Tjeckiska','slk':'Slovakiska','slo':'Slovakiska',
        'bul':'Bulgariska','isl':'Isländska','ice':'Isländska','lat':'Latin',
        'heb':'Hebreiska','est':'Estniska','lav':'Lettiska','lit':'Litauiska',
        'smi':'Samiska','mul':'Flera språk','und':'Obestämt'
    };

    // Korta filialnamn för bokkorten ("Inne på: Falkenberg, Slöinge").
    // Fullständiga namn kommer från facetterna (kft_koha_branches via backend).
    var BRANCH_SHORT_LABELS = {
        'FALK':'Falkenberg','MOBILA':'Bussen','SLOINGE':'Slöinge',
        'ULLARED':'Ullared','VESSIGEBRO':'Vessigebro','ATRAN':'Ätran'
    };

    // Fullständigt filialnamn från statiska facetter, fallback kortnamn/kod
    function branchLabel(code) {
        var list = (state.staticFacets && state.staticFacets.branches) || [];
        for (var i = 0; i < list.length; i++) {
            if (list[i].value === code && list[i].label) return list[i].label;
        }
        return BRANCH_SHORT_LABELS[code] || code;
    }

    // ── State ────────────────────────────────────────────────────────
    var state = {
        query: '',
        results: [],
        facets: null,
        staticFacets: null, // Databasomfattande facetter (laddas vid init)
        activeFilters: {},
        page: 0,
        totalFound: 0,
        loading: false,
        mode: 'browse',  // 'browse' | 'search'
        recommendBiblioId: null,
        recommendLabel: '',
        authorName: null,
        seriesName: null,
        sort: 'relevance_desc',
        browseLoaded: false, // Browse-sliders laddas lazy vid deep-link (?q=...)
        modalBiblioId: null, // Titel som är öppen i modalen (guard för sena live-svar)
        liveAbort: null      // AbortController för pågående mode=availability-anrop
    };

    // ── DOM-refs ─────────────────────────────────────────────────────
    var els = {};

    function initRefs() {
        els.input          = document.getElementById('fbg-ks-input');
        els.submit         = document.getElementById('fbg-ks-submit');
        els.browse         = document.getElementById('fbg-ks-browse');
        els.searchContent  = document.getElementById('fbg-ks-search-content');
        els.results        = document.getElementById('fbg-ks-results');
        els.resultHeader   = document.getElementById('fbg-ks-result-header');
        els.resultCount    = document.getElementById('fbg-ks-result-count');
        els.pagination     = document.getElementById('fbg-ks-pagination');
        els.loading        = document.getElementById('fbg-ks-loading');
        els.error          = document.getElementById('fbg-ks-error');
        els.errorText      = document.getElementById('fbg-ks-error-text');
        els.facetsPanel    = document.getElementById('fbg-ks-facets-panel');
        els.facetsContent  = document.getElementById('fbg-ks-facets-content');
        els.clearFilters   = document.getElementById('fbg-ks-clear-filters');
        els.backBrowse     = document.getElementById('fbg-ks-back-browse');
        els.activeFilters  = document.getElementById('fbg-ks-active-filters');
        els.filterToggle   = document.getElementById('fbg-ks-filter-toggle');
        els.filterCountBadge = document.getElementById('fbg-ks-filter-count-badge');
        els.sortSelect     = document.getElementById('fbg-ks-sort');
    }

    // ── Init ─────────────────────────────────────────────────────────

    function init() {
        initRefs();
        if (!els.input) return;

        // Events
        els.input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                startSearch();
            }
        });

        els.submit.addEventListener('click', function () {
            startSearch();
        });

        if (els.backBrowse) {
            els.backBrowse.addEventListener('click', function () {
                showBrowseMode();
            });
        }

        if (els.clearFilters) {
            els.clearFilters.addEventListener('click', function () {
                state.activeFilters = {};
                renderActiveFilters();
                renderFacetSelections();
                rerunCurrentSearch();
            });
        }

        // Klick/Enter/Space på bokkort → öppna modal (event delegation)
        function handleCardActivation(e) {
            var card = e.target.closest('.fbg-ks-book-card[data-biblio-id]');
            if (card) {
                e.preventDefault();
                var biblioId = card.getAttribute('data-biblio-id');
                var title = card.getAttribute('data-title');
                if (biblioId) openBookModal(parseInt(biblioId), title);
            }
        }
        var moduleEl = document.querySelector('.mod-fbg-kohasearch');
        moduleEl.addEventListener('click', handleCardActivation);
        moduleEl.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                handleCardActivation(e);
            }
        });

        // Modal stängs (X, Escape, "Fler som denna" osv.) → avbryt pågående live-statusanrop
        var modalEl = document.getElementById('fbg-ks-modal');
        if (modalEl && window.UIkit) {
            UIkit.util.on(modalEl, 'hide', abortLiveAvailability);
        }

        // Sorteringsväljare
        if (els.sortSelect) {
            els.sortSelect.addEventListener('change', function () {
                state.sort = els.sortSelect.value;
                rerunCurrentSearch();
            });
        }

        // Mobilknapp för att visa/dölja filter
        if (els.filterToggle) {
            els.filterToggle.addEventListener('click', function () {
                var panel = els.facetsPanel;
                if (!panel) return;
                var isOpen = panel.classList.toggle('fbg-ks-facets-open');
                els.filterToggle.setAttribute('aria-expanded', String(isOpen));
            });
        }

        // Deep-link: ?q=sökterm i URL:en (t.ex. från sökbox-layouten) →
        // kör sökningen direkt och hoppa över browse-laddningen
        var urlQ = new URLSearchParams(window.location.search).get('q');
        if (urlQ && urlQ.trim()) {
            els.input.value = urlQ.trim();
            startSearch();
        } else {
            // Ladda browse-innehåll parallellt
            loadBrowseSection('latest');
            loadBrowseSection('popular');
            state.browseLoaded = true;
        }

        if (SHOW_FACETS) {
            loadFacets();
        }
    }

    // ── Sökning ──────────────────────────────────────────────────────

    function startSearch() {
        var query = els.input.value.trim();
        if (!query) {
            els.input.classList.add('fbg-ks-shake');
            setTimeout(function () { els.input.classList.remove('fbg-ks-shake'); }, 300);
            return;
        }
        state.query = query;
        updateUrlQuery(query);
        state.page = 0;
        state.recommendBiblioId = null;
        state.recommendLabel = '';
        state.recommendTagFilter = null;
        state.authorName = null;
        state.seriesName = null;
        state.activeFilters = {};
        state.sort = 'relevance_desc';
        if (els.sortSelect) els.sortSelect.value = 'relevance_desc';
        showSearchMode();
        renderActiveFilters();
        renderFacetSelections();
        doSearch(true);
    }

    /**
     * Kör om den aktuella sökningen (från sidan 0) med nuvarande filter/sortering,
     * i rätt läge: serie-, författar-, rekommendations- eller vanlig semantisk sökning.
     * Används av facett-, år- och sorteringsändringar. Gör inget i browse-läge.
     */
    function rerunCurrentSearch() {
        if (state.mode !== 'search') return;
        state.page = 0;
        if (state.seriesName) {
            doSeriesSearch(state.seriesName);
        } else if (state.authorName) {
            doAuthorSearch(state.authorName);
        } else if (state.recommendBiblioId) {
            doRecommend(state.recommendBiblioId);
        } else {
            doSearch(true);
        }
    }

    function doSearch(resetPage) {
        if (state.loading) return;
        if (resetPage) state.page = 0;

        state.loading = true;
        showLoading(true);
        hideError();

        var body = {
            query: state.query,
            limit: PER_PAGE,
            offset: state.page * PER_PAGE,
            filters: state.activeFilters,
            sort: state.sort,
            mode: 'search',
            module_id: MODULE_ID,
            // Webbläsarens språk – backend flyttar ned träffar på övriga språk (svenska föredras alltid)
            languages: (navigator.languages || [navigator.language || 'sv']).slice(0, 5)
        };

        fetch(SEARCH_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        })
        .then(function (resp) { return resp.json(); })
        .then(function (data) {
            state.loading = false;
            showLoading(false);

            if (!data.success) {
                showError(data.message || 'Ett okänt fel uppstod.');
                return;
            }

            state.results = data.results || [];
            state.totalFound = data.total_found || 0;

            if (data.facets) applyDynamicFacets(data.facets);
            renderResults();
            renderResultCount();
            renderPagination();

            // Scrolla till resultat (bara vid sidnavigering)
            if (!resetPage) {
                var target = els.resultHeader || els.results;
                if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        })
        .catch(function (err) {
            state.loading = false;
            showLoading(false);
            showError('Nätverksfel: ' + err.message);
        });
    }

    // ── Rekommendationer (liknande böcker) ───────────────────────────

    function doRecommend(biblioId) {
        if (state.loading) return;

        state.loading = true;
        showLoading(true);
        hideError();

        // Slå ihop aktiva facettfilter med eventuellt tag-filter
        var filters = Object.assign({}, state.activeFilters);
        if (state.recommendTagFilter) {
            Object.keys(state.recommendTagFilter).forEach(function (key) {
                filters[key] = state.recommendTagFilter[key];
            });
        }

        var body = {
            mode: 'recommend',
            biblio_id: biblioId,
            limit: PER_PAGE,
            offset: state.page * PER_PAGE,
            filters: filters,
            sort: state.sort,
            module_id: MODULE_ID
        };

        fetch(SEARCH_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        })
        .then(function (resp) { return resp.json(); })
        .then(function (data) {
            state.loading = false;
            showLoading(false);

            if (!data.success) {
                showError(data.message || 'Kunde inte hämta rekommendationer.');
                return;
            }

            state.results = data.results || [];
            state.totalFound = data.total_found || 0;

            if (data.facets) applyDynamicFacets(data.facets);
            renderResults();
            renderResultCount();
            renderPagination();
        })
        .catch(function (err) {
            state.loading = false;
            showLoading(false);
            showError('Nätverksfel: ' + err.message);
        });
    }

    // ── Författarsökning (exakt match) ─────────────────────────────

    function doAuthorSearch(author) {
        if (state.loading) return;

        state.loading = true;
        showLoading(true);
        hideError();

        var body = {
            mode: 'author',
            author: author,
            limit: PER_PAGE,
            offset: state.page * PER_PAGE,
            filters: state.activeFilters,
            sort: state.sort,
            module_id: MODULE_ID
        };

        fetch(SEARCH_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        })
        .then(function (resp) { return resp.json(); })
        .then(function (data) {
            state.loading = false;
            showLoading(false);

            if (!data.success) {
                showError(data.message || 'Kunde inte söka efter författare.');
                return;
            }

            state.results = data.results || [];
            state.totalFound = data.total_found || 0;

            if (data.facets) applyDynamicFacets(data.facets);
            renderResults();
            renderResultCount();
            renderPagination();
        })
        .catch(function (err) {
            state.loading = false;
            showLoading(false);
            showError('Nätverksfel: ' + err.message);
        });
    }

    // ── Seriesökning (exakt match) ──────────────────────────────────

    function doSeriesSearch(series) {
        if (state.loading) return;

        state.loading = true;
        showLoading(true);
        hideError();

        var body = {
            mode: 'series',
            series: series,
            limit: PER_PAGE,
            offset: state.page * PER_PAGE,
            filters: state.activeFilters,
            sort: state.sort,
            module_id: MODULE_ID
        };

        fetch(SEARCH_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        })
        .then(function (resp) { return resp.json(); })
        .then(function (data) {
            state.loading = false;
            showLoading(false);

            if (!data.success) {
                showError(data.message || 'Kunde inte söka efter serie.');
                return;
            }

            state.results = data.results || [];
            state.totalFound = data.total_found || 0;

            if (data.facets) applyDynamicFacets(data.facets);
            renderResults();
            renderResultCount();
            renderPagination();
        })
        .catch(function (err) {
            state.loading = false;
            showLoading(false);
            showError('Nätverksfel: ' + err.message);
        });
    }

    // ── Browse-läge ──────────────────────────────────────────────────

    function loadBrowseSection(section) {
        var container = document.querySelector('.fbg-ks-browse-slider[data-section="' + section + '"]');
        if (!container) return;

        fetch(SEARCH_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ mode: 'browse', section: section, limit: 12, module_id: MODULE_ID })
        })
        .then(function (resp) { return resp.json(); })
        .then(function (data) {
            if (!data.success || !data.books || data.books.length === 0) {
                container.innerHTML = '<p class="uk-text-muted uk-text-small">Kunde inte ladda böcker.</p>';
                return;
            }
            renderBrowseSlider(container, data.books);
        })
        .catch(function () {
            container.innerHTML = '<p class="uk-text-muted uk-text-small">Kunde inte ladda böcker.</p>';
        });
    }

    function renderBrowseSlider(container, books) {
        var html = '<div uk-slider="autoplay: true; autoplay-interval: 4000; pause-on-hover: true">';
        html += '<div class="uk-position-relative uk-visible-toggle" tabindex="-1">';
        html += '<div class="uk-slider-items uk-child-width-1-6@xl uk-child-width-1-5@l uk-child-width-1-4@m uk-child-width-1-3@s uk-child-width-1-2 uk-grid uk-grid-small">';

        books.forEach(function (book) {
            html += '<div>' + renderBookCard(book, true) + '</div>';
        });

        html += '</div>';
        html += '<a class="uk-position-center-left uk-position-small uk-hidden-hover" href uk-slidenav-previous uk-slider-item="previous" aria-label="Föregående" style="background:rgba(255,255,255,0.9);border-radius:50%;width:40px;height:40px;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 8px rgba(0,0,0,0.15);"></a>';
        html += '<a class="uk-position-center-right uk-position-small uk-hidden-hover" href uk-slidenav-next uk-slider-item="next" aria-label="Nästa" style="background:rgba(255,255,255,0.9);border-radius:50%;width:40px;height:40px;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 8px rgba(0,0,0,0.15);"></a>';
        html += '</div></div>';

        container.innerHTML = html;

        // Reinitiera UIkit-komponenter
        if (window.UIkit) {
            UIkit.update(container);
        }
    }

    // ── Facetter ─────────────────────────────────────────────────────

    function loadFacets() {
        if (!els.facetsContent) return;

        fetch(SEARCH_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ mode: 'facets', module_id: MODULE_ID })
        })
        .then(function (resp) { return resp.json(); })
        .then(function (data) {
            if (!data.success || !data.facets) return;
            state.staticFacets = data.facets;
            state.facets = data.facets;
            renderFacets();
        })
        .catch(function () {
            els.facetsContent.innerHTML = '<p class="uk-text-muted uk-text-small">Kunde inte ladda filter.</p>';
        });
    }

    /**
     * Uppdatera facetter med dynamiska värden från sökresultat.
     * Behåller utgivningsår från statiska facetter (range, inte count-baserad).
     */
    function applyDynamicFacets(facets) {
        if (!facets) return;
        state.facets = {
            media_types: facets.media_types || [],
            author: facets.author || [],
            branches: facets.branches || [],
            target_audience: facets.target_audience || [],
            language: facets.language || [],
            publication_year: state.staticFacets ? state.staticFacets.publication_year : { min: 1900, max: new Date().getFullYear() }
        };
        renderFacets();
    }

    /**
     * Återställ statiska facetter (vid övergång till browse-läge).
     */
    function restoreStaticFacets() {
        if (state.staticFacets) {
            state.facets = state.staticFacets;
            renderFacets();
        }
    }

    function renderFacets() {
        if (!state.facets || !els.facetsContent) return;

        var html = '';

        // Mediatyp
        if (state.facets.media_types && state.facets.media_types.length > 0) {
            html += renderFacetGroup('media_types', 'Mediatyp', state.facets.media_types);
        }

        // Författare – endast dynamisk (beräknad från träffmängden, saknas i statiska facetter);
        // döljs i författarläge där den bara skulle innehålla den redan valda författaren
        if (!state.authorName && state.facets.author && state.facets.author.length > 0) {
            html += renderFacetGroup('author', 'Författare', state.facets.author);
        }

        // Finns inne på – filialer med minst ett tillgängligt exemplar (backend: available_branches)
        if (state.facets.branches && state.facets.branches.length > 0) {
            html += renderFacetGroup('branches', 'Finns inne på', state.facets.branches);
        }

        // Målgrupp
        if (state.facets.target_audience && state.facets.target_audience.length > 0) {
            html += renderFacetGroup('target_audience', 'Målgrupp', state.facets.target_audience);
        }

        // Språk
        if (state.facets.language && state.facets.language.length > 0) {
            html += renderFacetGroup('language', 'Språk', state.facets.language);
        }

        // Utgivningsår
        if (state.facets.publication_year) {
            html += renderYearFilter(state.facets.publication_year);
        }

        els.facetsContent.innerHTML = html;

        // Binder events
        bindFacetEvents();
    }

    function renderFacetGroup(name, title, options) {
        var html = '<div class="fbg-ks-facet-group" data-facet="' + name + '">';
        html += '<div class="fbg-ks-facet-title" data-toggle="' + name + '" role="button" tabindex="0" aria-expanded="true" aria-controls="fbg-ks-facet-' + name + '">' + escapeHtml(title) + '</div>';
        html += '<div class="fbg-ks-facet-options" id="fbg-ks-facet-' + name + '">';

        options.forEach(function (opt) {
            var checked = isFilterActive(name, opt.value) ? ' checked' : '';
            html += '<label class="fbg-ks-facet-option">';
            html += '<input type="checkbox" data-facet="' + name + '" value="' + escapeHtml(opt.value) + '"' + checked + '>';
            html += '<span>' + escapeHtml(opt.label || opt.value) + '</span>';
            if (opt.count !== undefined) {
                html += '<span class="fbg-ks-facet-count">' + formatNumber(opt.count) + '</span>';
            }
            html += '</label>';
        });

        html += '</div></div>';
        return html;
    }

    function renderYearFilter(yearData) {
        var minVal = (state.activeFilters.publication_year_min) || '';
        var maxVal = (state.activeFilters.publication_year_max) || '';

        var html = '<div class="fbg-ks-facet-group" data-facet="publication_year">';
        html += '<div class="fbg-ks-facet-title" data-toggle="publication_year" role="button" tabindex="0" aria-expanded="true" aria-controls="fbg-ks-facet-publication_year">Utgivningsår</div>';
        html += '<div class="fbg-ks-facet-options" id="fbg-ks-facet-publication_year">';
        html += '<div class="fbg-ks-year-inputs">';
        html += '<input type="number" id="fbg-ks-year-min" placeholder="' + yearData.min + '" min="' + yearData.min + '" max="' + yearData.max + '" value="' + minVal + '">';
        html += '<span>&ndash;</span>';
        html += '<input type="number" id="fbg-ks-year-max" placeholder="' + yearData.max + '" min="' + yearData.min + '" max="' + yearData.max + '" value="' + maxVal + '">';
        html += '</div>';
        html += '</div></div>';
        return html;
    }

    function bindFacetEvents() {
        // Toggle collapse
        var titles = document.querySelectorAll('.fbg-ks-facet-title[data-toggle]');
        titles.forEach(function (el) {
            function toggleFacet() {
                var target = document.getElementById('fbg-ks-facet-' + el.getAttribute('data-toggle'));
                if (target) {
                    el.classList.toggle('collapsed');
                    target.classList.toggle('collapsed');
                    var expanded = !el.classList.contains('collapsed');
                    el.setAttribute('aria-expanded', String(expanded));
                }
            }
            el.addEventListener('click', toggleFacet);
            el.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    toggleFacet();
                }
            });
        });

        // Checkbox-filter
        var checkboxes = document.querySelectorAll('.fbg-ks-facet-options input[type="checkbox"]');
        checkboxes.forEach(function (cb) {
            cb.addEventListener('change', function () {
                var facet = cb.getAttribute('data-facet');
                var value = cb.value;
                toggleFilter(facet, value, cb.checked);
            });
        });

        // År-filter med debounce
        var yearMin = document.getElementById('fbg-ks-year-min');
        var yearMax = document.getElementById('fbg-ks-year-max');
        var yearTimer = null;

        function onYearChange() {
            clearTimeout(yearTimer);
            yearTimer = setTimeout(function () {
                var minV = yearMin ? yearMin.value : '';
                var maxV = yearMax ? yearMax.value : '';

                if (minV) {
                    state.activeFilters.publication_year_min = parseInt(minV);
                } else {
                    delete state.activeFilters.publication_year_min;
                }
                if (maxV) {
                    state.activeFilters.publication_year_max = parseInt(maxV);
                } else {
                    delete state.activeFilters.publication_year_max;
                }

                renderActiveFilters();
                rerunCurrentSearch();
            }, 600);
        }

        if (yearMin) yearMin.addEventListener('input', onYearChange);
        if (yearMax) yearMax.addEventListener('input', onYearChange);
    }

    function toggleFilter(facet, value, isActive) {
        if (!state.activeFilters[facet]) {
            state.activeFilters[facet] = [];
        }

        if (isActive) {
            if (state.activeFilters[facet].indexOf(value) === -1) {
                state.activeFilters[facet].push(value);
            }
        } else {
            state.activeFilters[facet] = state.activeFilters[facet].filter(function (v) { return v !== value; });
            if (state.activeFilters[facet].length === 0) {
                delete state.activeFilters[facet];
            }
        }

        renderActiveFilters();
        rerunCurrentSearch();
    }

    function isFilterActive(facet, value) {
        return state.activeFilters[facet] && state.activeFilters[facet].indexOf(value) !== -1;
    }

    function renderFacetSelections() {
        var checkboxes = document.querySelectorAll('.fbg-ks-facet-options input[type="checkbox"]');
        checkboxes.forEach(function (cb) {
            cb.checked = isFilterActive(cb.getAttribute('data-facet'), cb.value);
        });
        var yearMin = document.getElementById('fbg-ks-year-min');
        var yearMax = document.getElementById('fbg-ks-year-max');
        if (yearMin) yearMin.value = state.activeFilters.publication_year_min || '';
        if (yearMax) yearMax.value = state.activeFilters.publication_year_max || '';
    }

    function renderActiveFilters() {
        if (!els.activeFilters) return;

        var chips = [];
        var hasFilters = false;

        ['media_types', 'author', 'branches', 'target_audience', 'language'].forEach(function (facet) {
            if (state.activeFilters[facet]) {
                state.activeFilters[facet].forEach(function (val) {
                    hasFilters = true;
                    var label = val;
                    if (facet === 'media_types') label = ITEM_TYPE_LABELS[val] || val;
                    else if (facet === 'language') label = LANGUAGE_LABELS[val] || val;
                    else if (facet === 'branches') label = 'Inne på ' + branchLabel(val);
                    chips.push('<button class="fbg-ks-filter-chip" data-facet="' + facet + '" data-value="' + escapeHtml(val) + '">' +
                        escapeHtml(label) + ' <span class="chip-remove">&times;</span></button>');
                });
            }
        });

        if (state.activeFilters.publication_year_min || state.activeFilters.publication_year_max) {
            hasFilters = true;
            var yearLabel = (state.activeFilters.publication_year_min || '...') + ' – ' + (state.activeFilters.publication_year_max || '...');
            chips.push('<button class="fbg-ks-filter-chip" data-facet="year_range">' +
                escapeHtml(yearLabel) + ' <span class="chip-remove">&times;</span></button>');
        }

        els.activeFilters.innerHTML = chips.join('');
        els.activeFilters.classList.toggle('uk-hidden', !hasFilters);

        if (els.clearFilters) {
            els.clearFilters.classList.toggle('uk-hidden', !hasFilters);
        }

        // Uppdatera mobilknappens räknare
        if (els.filterCountBadge) {
            var filterCount = chips.length;
            els.filterCountBadge.textContent = filterCount;
            els.filterCountBadge.classList.toggle('uk-hidden', filterCount === 0);
        }

        // Binder borttagning
        els.activeFilters.querySelectorAll('.fbg-ks-filter-chip').forEach(function (chip) {
            chip.addEventListener('click', function () {
                var facet = chip.getAttribute('data-facet');
                if (facet === 'year_range') {
                    delete state.activeFilters.publication_year_min;
                    delete state.activeFilters.publication_year_max;
                    var ym = document.getElementById('fbg-ks-year-min');
                    var yx = document.getElementById('fbg-ks-year-max');
                    if (ym) ym.value = '';
                    if (yx) yx.value = '';
                } else {
                    toggleFilter(facet, chip.getAttribute('data-value'), false);
                    return; // toggleFilter kör redan doSearch
                }
                renderActiveFilters();
                renderFacetSelections();
                rerunCurrentSearch();
            });
        });
    }

    // ── Rendering ────────────────────────────────────────────────────

    function renderResults() {
        if (!els.results) return;

        if (state.results.length === 0) {
            els.results.innerHTML = '<div class="uk-text-center uk-padding"><p class="uk-text-muted">Inga resultat hittades. Försök med andra sökord eller färre filter.</p></div>';
            return;
        }

        var html = '<div class="fbg-ks-results-grid">';
        state.results.forEach(function (book) {
            html += renderBookCard(book, false);
        });
        html += '</div>';

        els.results.innerHTML = html;
    }

    function renderBookCard(book, isSlider) {
        var imageUrl = buildImageUrl(book);
        var title    = buildFullTitle(book);
        var author   = book.author || '';
        var link     = book.catalog_link || '#';
        var year     = book.publication_year || '';
        var mediaTypes = book.media_types || [];

        var coverHtml;
        var primaryMedia = mediaTypes[0] || '';
        if (imageUrl) {
            coverHtml = '<div class="fbg-ks-cover"><img src="' + escapeHtml(imageUrl) + '" alt="' + escapeHtml(title) + '" loading="lazy" onerror="this.parentElement.innerHTML=\'' + placeholderHtmlAttr(title, primaryMedia) + '\'"></div>';
        } else {
            coverHtml = '<div class="fbg-ks-cover">' + placeholderHtml(title, primaryMedia) + '</div>';
        }

        var metaHtml = '';
        if (mediaTypes.length > 0) {
            var label = ITEM_TYPE_LABELS[mediaTypes[0]] || mediaTypes[0];
            metaHtml += '<span class="fbg-ks-badge fbg-ks-badge-media">' + escapeHtml(label) + '</span>';
        }
        if (year) {
            metaHtml += '<span class="fbg-ks-badge fbg-ks-badge-year">' + escapeHtml(String(year)) + '</span>';
        }
        // Språk – visas bara för icke-svenska titlar (backend skickar tom 'language' för svenska)
        if (book.language) {
            metaHtml += '<span class="fbg-ks-badge fbg-ks-badge-lang">' + escapeHtml(book.language) + '</span>';
        }

        var availHtml = '';
        if (SHOW_AVAIL && !isSlider && book.total_items !== undefined) {
            var avail = book.available_items || 0;
            var total = book.total_items || 0;
            var availBranches = (book.available_branches || []).map(function (c) { return BRANCH_SHORT_LABELS[c] || c; });
            var onOrder = book.on_order_items || 0;
            var countText = avail + '/' + total + ' tillgängliga';
            if (availBranches.length > 0) {
                // Var titeln finns inne just nu (enligt senaste dygnssynk)
                availHtml = '<div class="fbg-ks-availability fbg-ks-avail-yes" title="' + escapeHtml(countText) + '">Inne på: ' + escapeHtml(availBranches.join(', ')) + '</div>';
            } else if (avail === 0 && onOrder > 0) {
                // Beställd (Kohas NOT_LOAN -1) – kommer, är inte "ej tillgänglig"
                availHtml = '<div class="fbg-ks-availability fbg-ks-avail-order" title="' + escapeHtml(onOrder + ' av ' + total + ' under inköp') + '">Under inköp</div>';
            } else {
                var cls = avail > 0 ? 'fbg-ks-avail-yes' : 'fbg-ks-avail-no';
                availHtml = '<div class="fbg-ks-availability ' + cls + '">' + countText + '</div>';
            }
        }

        var biblioId = book.biblio_id || '';

        return '<div class="fbg-ks-book-card" data-biblio-id="' + biblioId + '" data-title="' + escapeHtml(title) + '" role="button" tabindex="0">' +
            coverHtml +
            '<div class="fbg-ks-book-info">' +
            '<h4 class="fbg-ks-book-title">' + escapeHtml(title) + (book.subtitle ? ' <span class="fbg-ks-subtitle">' + escapeHtml(book.subtitle) + '</span>' : '') + '</h4>' +
            (author ? '<p class="fbg-ks-book-author">' + escapeHtml(author) + '</p>' : '') +
            '<div class="fbg-ks-book-meta">' + metaHtml + '</div>' +
            availHtml +
            '</div>' +
            '</div>';
    }

    function buildImageUrl(book) {
        // 1. Använd image_url från backend (pekar på cover.php-proxy)
        if (book.image_url) return book.image_url;

        // 2. Bygg cover.php-URL från ISBN
        var isbn = book.isbn_clean || book.isbn || '';
        if (isbn) {
            var clean = isbn.replace(/[^0-9X]/gi, '').split(/[,|]/)[0];
            if (clean) return '/modules/mod_fbg_kohasearch/cover.php?isbn=' + encodeURIComponent(clean);
        }

        return null;
    }

    function getPlaceholderSvg(mediaType) {
        var key = MEDIA_TYPE_SVG_MAP[mediaType] || 'book';
        return PLACEHOLDER_SVGS[key];
    }

    function placeholderHtml(title, mediaType) {
        return '<div class="fbg-ks-cover-placeholder">' + getPlaceholderSvg(mediaType) +
            '<span class="placeholder-title">' + escapeHtml(title) + '</span></div>';
    }

    function placeholderHtmlAttr(title, mediaType) {
        // Escaped för användning i onerror-attribut (inuti "...'...'...")
        return placeholderHtml(title, mediaType).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function renderResultCount() {
        if (!els.resultCount) return;
        if (!els.resultHeader) return;

        els.resultHeader.classList.remove('uk-hidden');

        var text = '';
        if (state.seriesName) {
            // Serieläge
            if (state.totalFound <= 0) {
                text = 'Inga böcker hittades i serien "' + escapeHtml(state.seriesName) + '"';
            } else if (state.totalFound === 1) {
                text = '1 bok i serien "' + escapeHtml(state.seriesName) + '"';
            } else {
                text = formatNumber(state.totalFound) + '+ böcker i serien "' + escapeHtml(state.seriesName) + '"';
            }
        } else if (state.authorName) {
            // Författarläge
            if (state.totalFound <= 0) {
                text = 'Inga böcker hittades av ' + escapeHtml(state.authorName);
            } else if (state.totalFound === 1) {
                text = '1 bok av ' + escapeHtml(state.authorName);
            } else {
                text = formatNumber(state.totalFound) + '+ böcker av ' + escapeHtml(state.authorName);
            }
        } else if (state.recommendBiblioId) {
            // Recommend-läge
            if (state.totalFound <= 0) {
                text = 'Inga liknande böcker hittades';
            } else if (state.recommendLabel) {
                text = '"' + escapeHtml(state.recommendLabel) + '" – liknande "' + escapeHtml(state.query) + '"';
            } else {
                text = 'Liknande böcker som "' + escapeHtml(state.query) + '"';
            }
        } else {
            if (state.totalFound <= 0) {
                text = 'Inga träffar';
            } else if (state.totalFound === 1) {
                text = '1 träff';
            } else {
                text = formatNumber(state.totalFound) + '+ träffar';
            }
            text += ' för "' + escapeHtml(state.query) + '"';
        }

        els.resultCount.textContent = text;
    }

    function renderPagination() {
        if (!els.pagination) return;

        var totalPages = Math.ceil(state.totalFound / PER_PAGE);
        if (totalPages <= 1) {
            els.pagination.innerHTML = '';
            return;
        }

        var current = state.page;
        var html = '<div class="fbg-ks-pagination">';

        // Föregående
        html += '<button class="fbg-ks-page-btn" data-page="' + (current - 1) + '"' + (current === 0 ? ' disabled' : '') + '>&lsaquo; Föregående</button>';

        // Sidnummer
        var startPage = Math.max(0, current - 2);
        var endPage   = Math.min(totalPages - 1, current + 2);

        if (startPage > 0) {
            html += '<button class="fbg-ks-page-btn" data-page="0">1</button>';
            if (startPage > 1) html += '<span class="fbg-ks-page-btn" style="border:none;cursor:default;">&hellip;</span>';
        }

        for (var i = startPage; i <= endPage; i++) {
            html += '<button class="fbg-ks-page-btn' + (i === current ? ' active' : '') + '" data-page="' + i + '"' + (i === current ? ' aria-current="page"' : '') + '>' + (i + 1) + '</button>';
        }

        if (endPage < totalPages - 1) {
            if (endPage < totalPages - 2) html += '<span class="fbg-ks-page-btn" style="border:none;cursor:default;">&hellip;</span>';
            html += '<button class="fbg-ks-page-btn" data-page="' + (totalPages - 1) + '">' + totalPages + '</button>';
        }

        // Nästa
        html += '<button class="fbg-ks-page-btn" data-page="' + (current + 1) + '"' + (current >= totalPages - 1 ? ' disabled' : '') + '>Nästa &rsaquo;</button>';

        html += '</div>';
        els.pagination.innerHTML = html;

        // Binder events
        els.pagination.querySelectorAll('.fbg-ks-page-btn[data-page]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (btn.disabled) return;
                state.page = parseInt(btn.getAttribute('data-page'));
                if (state.seriesName) {
                    doSeriesSearch(state.seriesName);
                } else if (state.authorName) {
                    doAuthorSearch(state.authorName);
                } else if (state.recommendBiblioId) {
                    doRecommend(state.recommendBiblioId);
                } else {
                    doSearch(false);
                }
            });
        });
    }

    // ── Bokmodal ─────────────────────────────────────────────────────

    function openBookModal(biblioId, fallbackTitle) {
        var modal = document.getElementById('fbg-ks-modal');
        var modalLoading = document.getElementById('fbg-ks-modal-loading');
        var modalContent = document.getElementById('fbg-ks-modal-content');

        if (!modal || !window.UIkit) return;

        // Snabba klick på flera kort: släng ev. pågående live-anrop för förra titeln
        abortLiveAvailability();

        // Visa modal med spinner
        modalLoading.classList.remove('uk-hidden');
        modalContent.classList.add('uk-hidden');
        modalContent.innerHTML = '';
        UIkit.modal(modal).show();

        // Hämta bokdata
        fetch(SEARCH_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ mode: 'book', biblio_id: biblioId, module_id: MODULE_ID })
        })
        .then(function (resp) { return resp.json(); })
        .then(function (data) {
            modalLoading.classList.add('uk-hidden');
            modalContent.classList.remove('uk-hidden');

            if (!data.success) {
                modalContent.innerHTML = '<p class="uk-text-danger">Kunde inte ladda bokdetaljer.</p>';
                return;
            }

            modalContent.innerHTML = renderBookModal(data);
            bindModalEvents(data);
            if (LIVE_AVAIL) loadLiveAvailability(biblioId);
        })
        .catch(function () {
            modalLoading.classList.add('uk-hidden');
            modalContent.classList.remove('uk-hidden');
            modalContent.innerHTML = '<p class="uk-text-danger">Nätverksfel.</p>';
        });
    }

    /**
     * Placering + hyllsignum per filial: [{location, callnumber}] -> "Spänning · Hce, Magasin · Hce".
     * Delarna separeras med mittpunkt, hyllorna med komma. Tomma fält utelämnas.
     */
    function formatShelves(shelves) {
        if (!Array.isArray(shelves)) return '';
        return shelves.map(function (s) {
            return [s.location, s.callnumber].filter(function (part) {
                return part;
            }).join(' \u00b7 ');
        }).filter(function (text) {
            return text;
        }).join(', ');
    }

    var AVAIL_NOTE_NIGHTLY = 'Status från senaste dygnssynk';

    /**
     * <li>-rader för "Finns inne": "Falkenberg: Hce — 0 av 1 inne". Reserverade
     * exemplar (hold_status) räknas inte som inne men pekas inte ut särskilt –
     * `reserved` i svaret används inte i visningen.
     */
    function renderAvailabilityList(availability) {
        return availability.map(function (a) {
            // on_order saknas i äldre cachade svar → 0
            var onOrder = a.on_order || 0;
            var cls = a.available > 0 ? 'fbg-ks-avail-yes'
                : (onOrder > 0 ? 'fbg-ks-avail-order' : 'fbg-ks-avail-no');
            var row = '<li class="' + cls + '">' + escapeHtml(a.label || a.code) + ': ';
            var shelfText = formatShelves(a.shelves);
            if (shelfText) {
                row += '<span class="fbg-ks-shelf">' + escapeHtml(shelfText) + '</span> &mdash; ';
            }
            if (onOrder > 0 && onOrder === a.total) {
                // Alla exemplar är beställda – "0 av 1 inne" vore missvisande
                row += 'Under inköp';
            } else {
                row += escapeHtml(String(a.available)) + ' av ' + escapeHtml(String(a.total)) + ' inne';
                if (onOrder > 0) {
                    row += ' &middot; ' + escapeHtml(String(onOrder)) + ' under inköp';
                }
            }
            return row + '</li>';
        }).join('');
    }

    /**
     * Hämta "Finns inne" just nu från Koha (mode=availability) och byt ut
     * nattlistan i modalen. Backend svarar alltid 200; live:false (avstängt,
     * kvot, Koha nere) → nattdatan står kvar tyst. Svar för en annan titel än
     * den öppna (snabba klick) ignoreras via state.modalBiblioId.
     */
    function loadLiveAvailability(biblioId) {
        var note = document.getElementById('fbg-ks-modal-avail-note');
        var list = document.getElementById('fbg-ks-modal-avail-list');
        var box  = document.getElementById('fbg-ks-modal-availability');
        if (!note || !list || !box) return;

        var ctrl = ('AbortController' in window) ? new AbortController() : null;
        state.liveAbort = ctrl;
        state.modalBiblioId = biblioId;
        note.textContent = AVAIL_NOTE_NIGHTLY + ' \u00b7 h\u00e4mtar aktuell status\u2026';

        fetch(SEARCH_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ mode: 'availability', biblio_id: biblioId, module_id: MODULE_ID }),
            signal: ctrl ? ctrl.signal : undefined
        })
        .then(function (resp) { return resp.json(); })
        .then(function (data) {
            if (state.modalBiblioId !== biblioId) return;
            if (!data || !data.success || !data.live) {
                note.textContent = AVAIL_NOTE_NIGHTLY;
                return;
            }
            var availability = Array.isArray(data.availability) ? data.availability : [];
            list.innerHTML = availability.length > 0
                ? renderAvailabilityList(availability)
                : '<li class="fbg-ks-avail-no">Inga exemplar registrerade just nu</li>';
            box.classList.remove('uk-hidden');
            var time = formatClock(data.fetched_at);
            note.textContent = data.stale
                ? 'Status kl. ' + time
                : 'Status just nu (h\u00e4mtad kl. ' + time + ')';
            note.classList.add('is-live');
        })
        .catch(function (err) {
            if (err && err.name === 'AbortError') return;
            if (state.modalBiblioId !== biblioId) return;
            note.textContent = AVAIL_NOTE_NIGHTLY;
        });
    }

    function abortLiveAvailability() {
        if (state.liveAbort) state.liveAbort.abort();
        state.liveAbort = null;
        state.modalBiblioId = null;
    }

    /** ISO-tidsstämpel → "HH:MM" (lokal tid). */
    function formatClock(iso) {
        var d = iso ? new Date(iso) : new Date();
        if (isNaN(d.getTime())) d = new Date();
        return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2);
    }

    function renderBookModal(book) {
        var imageUrl = book.image_url;
        var title = buildFullTitle(book);
        var subtitle = book.subtitle || '';
        var author = book.author || '';
        var abstract = book.abstract || '';
        var year = book.publication_year || '';
        var publisher = book.publisher || '';
        // series_title: array av {name, display} objekt eller strängar (bakåtkompatibelt)
        var seriesList = (Array.isArray(book.series_title) ? book.series_title : []).map(function (s) {
            if (typeof s === 'string') return { name: s, display: s };
            return { name: s.name || '', display: s.display || s.name || '' };
        }).filter(function (s) { return s.name !== ''; });
        var pages = book.pages || '';
        var language = book.language || '';
        var subjects = book.subjects || [];
        var tags = book.tags || [];
        var audience = book.target_audience || '';
        var catalogLink = book.catalog_link || '';
        var reserveLink = book.reserve_link || '';

        var html = '<div class="fbg-ks-modal-book">';

        // Övre del: bild + info sida vid sida
        html += '<div class="fbg-ks-modal-top">';

        if (imageUrl) {
            html += '<div class="fbg-ks-modal-cover">';
            html += '<img src="' + escapeHtml(imageUrl) + '" alt="' + escapeHtml(title) + '">';
            html += '</div>';
        }

        html += '<div class="fbg-ks-modal-info">';
        html += '<h3 class="fbg-ks-modal-title">' + escapeHtml(title);
        if (subtitle) html += ' <span class="fbg-ks-subtitle">' + escapeHtml(subtitle) + '</span>';
        html += '</h3>';

        if (author) html += '<p class="fbg-ks-modal-author"><a href="#" class="fbg-ks-author-link" data-author="' + escapeHtml(author) + '" title="Visa alla böcker av ' + escapeHtml(author) + '">' + escapeHtml(author) + '</a></p>';

        // Metadata-rad
        var metaParts = [];
        if (year) metaParts.push(escapeHtml(String(year)));
        if (publisher) metaParts.push(escapeHtml(publisher));
        if (pages) metaParts.push(escapeHtml(pages));
        if (seriesList.length > 0) {
            var seriesLinks = seriesList.map(function (s) {
                return '<a href="#" class="fbg-ks-series-link" data-series="' + escapeHtml(s.name) + '" title="Visa alla böcker i serien ' + escapeHtml(s.name) + '">' + escapeHtml(s.display) + '</a>';
            }).join(', ');
            metaParts.push('Serie: ' + seriesLinks);
        }
        if (audience) metaParts.push(escapeHtml(audience));
        if (language) metaParts.push('Språk: ' + escapeHtml(language));
        if (metaParts.length > 0) {
            html += '<p class="fbg-ks-modal-meta">' + metaParts.join(' &middot; ') + '</p>';
        }

        // Finns inne – tillgängliga exemplar per filial (från senaste dygnssynk).
        // Med live-status renderas blocket även utan nattdata (dolt) så att
        // Koha-svaret kan fylla det.
        var availability = Array.isArray(book.availability) ? book.availability : [];
        if (availability.length > 0 || LIVE_AVAIL) {
            html += '<div class="fbg-ks-modal-availability' + (availability.length === 0 ? ' uk-hidden' : '') + '" id="fbg-ks-modal-availability">';
            html += '<strong>Finns inne</strong>';
            html += '<ul id="fbg-ks-modal-avail-list">' + renderAvailabilityList(availability) + '</ul>';
            html += '<span class="fbg-ks-modal-avail-note" id="fbg-ks-modal-avail-note" aria-live="polite">' + AVAIL_NOTE_NIGHTLY + '</span>';
            html += '</div>';
        }

        html += '</div>'; // modal-info
        html += '</div>'; // modal-top

        // Abstract
        if (abstract) {
            html += '<div class="fbg-ks-modal-abstract">';
            html += '<p>' + escapeHtml(abstract) + '</p>';
            html += '</div>';
        }

        // Ämnen
        if (subjects.length > 0) {
            html += '<div class="fbg-ks-modal-tags">';
            html += '<strong>Ämnen:</strong> ';
            subjects.forEach(function (s) {
                html += '<span class="fbg-ks-tag fbg-ks-tag-subject fbg-ks-tag-clickable" role="button" tabindex="0" data-search-term="' + escapeHtml(s) + '" data-filter-field="subjects">' + escapeHtml(s) + '</span>';
            });
            html += '</div>';
        }

        // Taggar
        if (tags.length > 0) {
            html += '<div class="fbg-ks-modal-tags">';
            html += '<strong>Taggar:</strong> ';
            tags.forEach(function (t) {
                html += '<span class="fbg-ks-tag fbg-ks-tag-tag fbg-ks-tag-clickable" role="button" tabindex="0" data-search-term="' + escapeHtml(t) + '" data-filter-field="tags">' + escapeHtml(t) + '</span>';
            });
            html += '</div>';
        }

        // Knappar
        html += '<div class="fbg-ks-modal-actions">';
        html += '<button class="uk-button uk-button-primary fbg-ks-btn-similar" data-title="' + escapeHtml(title) + '">';
        html += '<span uk-icon="icon: search; ratio: 0.85"></span> Fler som denna</button>';
        if (reserveLink) {
            html += '<a href="' + escapeHtml(reserveLink) + '" target="_blank" rel="noopener" class="uk-button uk-button-primary fbg-ks-btn-reserve">';
            html += '<span uk-icon="icon: bookmark; ratio: 0.85"></span> Reservera</a>';
        }
        html += '<a href="' + escapeHtml(catalogLink) + '" target="_blank" rel="noopener" class="uk-button uk-button-default">';
        html += '<span uk-icon="icon: link; ratio: 0.85"></span> Öppna i bibliotekskatalogen</a>';
        html += '</div>';

        html += '</div>'; // modal-book
        return html;
    }

    function bindModalEvents(book) {
        // "Fler som denna" → Qdrant recommend
        var similarBtn = document.querySelector('.fbg-ks-btn-similar');
        if (similarBtn) {
            similarBtn.addEventListener('click', function () {
                var biblioId = book.biblio_id;
                var title = book.title || '';
                var modal = document.getElementById('fbg-ks-modal');
                if (window.UIkit) UIkit.modal(modal).hide();

                els.input.value = '';
                state.query = title;
                state.page = 0;
                state.recommendBiblioId = biblioId;
                state.recommendLabel = '';
                state.recommendTagFilter = null;
                state.authorName = null;
                state.seriesName = null;
                showSearchMode();
                doRecommend(biblioId);
            });
        }

        // Klickbar författare → exakt författarsökning via Qdrant scroll
        var authorLink = document.querySelector('.fbg-ks-author-link');
        if (authorLink) {
            authorLink.addEventListener('click', function (e) {
                e.preventDefault();
                var authorName = authorLink.getAttribute('data-author');
                if (!authorName) return;
                var modal = document.getElementById('fbg-ks-modal');
                if (window.UIkit) UIkit.modal(modal).hide();

                els.input.value = '';
                state.query = '';
                state.page = 0;
                state.recommendBiblioId = null;
                state.recommendLabel = '';
                state.recommendTagFilter = null;
                state.authorName = authorName;
                state.seriesName = null;
                state.sort = 'year_desc';
                if (els.sortSelect) els.sortSelect.value = 'year_desc';
                showSearchMode();
                doAuthorSearch(authorName);
            });
        }

        // Klickbar serie → exakt seriesökning via Qdrant scroll
        var seriesLinks = document.querySelectorAll('.fbg-ks-series-link');
        seriesLinks.forEach(function (link) {
            link.addEventListener('click', function (e) {
                e.preventDefault();
                var seriesName = link.getAttribute('data-series');
                if (!seriesName) return;
                var modal = document.getElementById('fbg-ks-modal');
                if (window.UIkit) UIkit.modal(modal).hide();

                els.input.value = '';
                state.query = '';
                state.page = 0;
                state.recommendBiblioId = null;
                state.recommendLabel = '';
                state.recommendTagFilter = null;
                state.authorName = null;
                state.seriesName = seriesName;
                state.sort = 'year_desc';
                if (els.sortSelect) els.sortSelect.value = 'year_desc';
                showSearchMode();
                doSeriesSearch(seriesName);
            });
        });

        // Klickbara ämnen och taggar → semantisk sökning på termen + payload-filter
        // Prioriterar att taggen/ämnet finns (filter) och semantisk närhet till termen (sökning),
        // inte vektornärhet till källboken.
        var clickableTags = document.querySelectorAll('.fbg-ks-tag-clickable');
        clickableTags.forEach(function (tag) {
            function activateTag() {
                var term = tag.getAttribute('data-search-term');
                var filterField = tag.getAttribute('data-filter-field');
                if (!term || !filterField) return;
                var modal = document.getElementById('fbg-ks-modal');
                if (window.UIkit) UIkit.modal(modal).hide();

                // Sök på termen som query – rankar efter semantisk närhet till ämnet/taggen
                els.input.value = term;
                state.query = term;
                state.page = 0;
                state.recommendBiblioId = null;
                state.recommendLabel = '';
                state.recommendTagFilter = null;
                state.authorName = null;
                state.seriesName = null;

                // Lägg till payload-filter som kräver att taggen/ämnet finns
                state.activeFilters[filterField] = [term];
                renderFacetSelections();

                showSearchMode();
                renderActiveFilters();
                doSearch(true);
            }
            tag.addEventListener('click', activateTag);
            tag.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    activateTag();
                }
            });
        });
    }

    // ── Mode-switching ───────────────────────────────────────────────

    function showBrowseMode() {
        state.mode = 'browse';
        state.query = '';
        state.results = [];
        state.page = 0;
        state.authorName = null;
        state.seriesName = null;
        els.input.value = '';
        updateUrlQuery(null);

        // Sliders laddas lazy om sidan öppnades via deep-link (?q=...)
        if (!state.browseLoaded) {
            loadBrowseSection('latest');
            loadBrowseSection('popular');
            state.browseLoaded = true;
        }

        if (els.browse) els.browse.classList.remove('uk-hidden');
        if (els.searchContent) els.searchContent.classList.add('uk-hidden');
        if (els.resultHeader) els.resultHeader.classList.add('uk-hidden');
        if (els.pagination) els.pagination.innerHTML = '';
        if (els.results) els.results.innerHTML = '';
        hideError();
        restoreStaticFacets();
    }

    function showSearchMode() {
        state.mode = 'search';
        if (els.browse) els.browse.classList.add('uk-hidden');
        if (els.searchContent) els.searchContent.classList.remove('uk-hidden');
    }

    // ── UI-hjälpare ──────────────────────────────────────────────────

    // Håll ?q=sökterm i URL:en synkad med aktiv sökning så att resultat
    // går att dela/bokmärka. null tar bort parametern (browse-läge).
    function updateUrlQuery(query) {
        try {
            var url = new URL(window.location.href);
            if (query) {
                url.searchParams.set('q', query);
            } else {
                url.searchParams.delete('q');
            }
            window.history.replaceState(null, '', url.toString());
        } catch (e) {
            // Äldre webbläsare utan URL/replaceState – ignorera tyst
        }
    }

    function showLoading(show) {
        if (els.loading) els.loading.classList.toggle('uk-hidden', !show);
        if (show && els.results) els.results.innerHTML = '';
        if (show && els.pagination) els.pagination.innerHTML = '';
    }

    function showError(msg) {
        if (els.error) els.error.classList.remove('uk-hidden');
        if (els.errorText) els.errorText.textContent = msg;
    }

    function hideError() {
        if (els.error) els.error.classList.add('uk-hidden');
    }

    function escapeHtml(text) {
        if (!text) return '';
        var div = document.createElement('div');
        div.textContent = String(text);
        return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    /**
     * Bygg fullständig titel från MARC 245-delfält.
     * Ordning: title ($a) + part_number ($n) + part_name ($p) + subtitle ($b)
     */
    function buildFullTitle(book) {
        var parts = [book.title || 'Okänd titel'];
        if (book.part_number) parts.push(book.part_number);
        if (book.part_name) parts.push(book.part_name);
        return parts.join(' ');
    }

    function formatNumber(n) {
        return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '\u00A0');
    }

    // ── Start ────────────────────────────────────────────────────────

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
