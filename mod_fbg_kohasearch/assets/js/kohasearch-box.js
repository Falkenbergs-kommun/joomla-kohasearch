/**
 * FBG Koha Search – Sökbox med autocomplete
 *
 * Kompakt sökbox-layout (tmpl/searchbox.php). Hämtar snabba förslag
 * (titlar/författare/serier) via mode=suggest i search.php – inget
 * OpenAI-anrop. Vid Enter eller klick på förslag submittas GET-formuläret
 * som skickar användaren till sökresultatsidan med ?q=sökterm.
 */
(function () {
    'use strict';

    var config = window.fbgKohasearchBoxConfig || {};

    var SEARCH_URL    = config.searchUrl || '/modules/mod_fbg_kohasearch/search.php';
    var MODULE_ID     = config.moduleId || 0;
    var SUGGEST_LIMIT = config.suggestLimit || 8;
    var MIN_CHARS     = config.minChars || 2;
    var DEBOUNCE_MS   = config.debounceMs || 200;

    var TYPE_LABELS = {
        title: 'Titel',
        author: 'Författare',
        series: 'Serie'
    };

    var els = {};
    var debounceTimer = null;
    var abortController = null;
    var activeIndex = -1;
    var suggestionCount = 0;

    function init() {
        els.form    = document.getElementById('fbg-ksb-form');
        els.input   = document.getElementById('fbg-ksb-input');
        els.listbox = document.getElementById('fbg-ksb-listbox');

        if (!els.form || !els.input || !els.listbox) return;

        els.input.addEventListener('input', onInput);
        els.input.addEventListener('keydown', onKeydown);

        // Klick utanför stänger förslagslistan
        document.addEventListener('click', function (e) {
            if (!els.form.contains(e.target)) {
                closeList();
            }
        });

        // Musklick på förslag (event delegation – li:na återskapas vid varje render)
        els.listbox.addEventListener('click', function (e) {
            var option = e.target.closest('li[role="option"]');
            if (option) {
                selectSuggestion(option);
            }
        });
    }

    // ── Förslagshämtning ─────────────────────────────────────────────

    function onInput() {
        var q = els.input.value.trim();

        if (debounceTimer) clearTimeout(debounceTimer);

        if (q.length < MIN_CHARS) {
            closeList();
            return;
        }

        debounceTimer = setTimeout(function () {
            fetchSuggestions(q);
        }, DEBOUNCE_MS);
    }

    function fetchSuggestions(q) {
        // Avbryt föregående request – bara senaste tangenttryckets svar är relevant
        if (abortController) abortController.abort();
        abortController = new AbortController();

        fetch(SEARCH_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                mode: 'suggest',
                q: q,
                limit: SUGGEST_LIMIT,
                module_id: MODULE_ID
            }),
            signal: abortController.signal
        })
            .then(function (resp) { return resp.json(); })
            .then(function (data) {
                if (data && data.success && data.suggestions && data.suggestions.length) {
                    renderSuggestions(data.suggestions);
                } else {
                    closeList();
                }
            })
            .catch(function (err) {
                if (err.name === 'AbortError') return;
                // Tyst fel – formuläret fungerar ändå utan förslag
                closeList();
            });
    }

    // ── Rendering ────────────────────────────────────────────────────

    function renderSuggestions(suggestions) {
        var html = '';
        suggestions.forEach(function (s, i) {
            var typeLabel = TYPE_LABELS[s.type] || '';
            html += '<li role="option" id="fbg-ksb-opt-' + i + '"' +
                ' data-label="' + escapeHtml(s.label) + '">' +
                '<span class="fbg-ksb-opt-label">' + escapeHtml(s.label) + '</span>' +
                (typeLabel ? '<span class="fbg-ksb-opt-type">' + typeLabel + '</span>' : '') +
                '</li>';
        });
        els.listbox.innerHTML = html;
        els.listbox.hidden = false;
        els.input.setAttribute('aria-expanded', 'true');
        activeIndex = -1;
        suggestionCount = suggestions.length;
    }

    function closeList() {
        els.listbox.hidden = true;
        els.listbox.innerHTML = '';
        els.input.setAttribute('aria-expanded', 'false');
        els.input.removeAttribute('aria-activedescendant');
        activeIndex = -1;
        suggestionCount = 0;
    }

    function selectSuggestion(option) {
        els.input.value = option.getAttribute('data-label') || '';
        closeList();
        els.form.submit();
    }

    // ── Tangentbordsnavigering ───────────────────────────────────────

    function onKeydown(e) {
        if (els.listbox.hidden) {
            // Enter utan öppen lista → nativ formulär-submit
            return;
        }

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActive((activeIndex + 1) % suggestionCount);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActive(activeIndex <= 0 ? suggestionCount - 1 : activeIndex - 1);
        } else if (e.key === 'Enter') {
            if (activeIndex >= 0) {
                e.preventDefault();
                var option = document.getElementById('fbg-ksb-opt-' + activeIndex);
                if (option) selectSuggestion(option);
            }
            // Utan aktivt val: låt formuläret submittas nativt
        } else if (e.key === 'Escape') {
            closeList();
        }
    }

    function setActive(index) {
        var prev = document.getElementById('fbg-ksb-opt-' + activeIndex);
        if (prev) prev.classList.remove('fbg-ksb-active');

        activeIndex = index;
        var current = document.getElementById('fbg-ksb-opt-' + activeIndex);
        if (current) {
            current.classList.add('fbg-ksb-active');
            els.input.setAttribute('aria-activedescendant', current.id);
            if (current.scrollIntoView) {
                current.scrollIntoView({ block: 'nearest' });
            }
        }
    }

    // ── Hjälpare ─────────────────────────────────────────────────────

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(String(str)));
        return div.innerHTML
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    // ── Boot ─────────────────────────────────────────────────────────

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
