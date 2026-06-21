/**
 * CalDAV Suite - Kontaktliste (Roundcube-Core): Sprachausgabe beim Navigieren.
 *
 * Roundcubes Kontaktliste aktualisiert beim Pfeilen die Braillezeile (folgt der
 * Auswahl), loest aber KEINE Sprachausgabe aus. Wir spiegeln den Namen der gerade
 * ausgewaehlten Zeile in eine versteckte aria-live-Region -> NVDA spricht ihn.
 *
 * Bewusst KEIN aria-activedescendant (das unterdrueckte bei NVDA die Sprache und
 * liess nur Braille). Rein additiv: Fokus, Tastatur, Selektion und Braille bleiben
 * voellig unangetastet -- es kommt nur eine gesprochene Ansage dazu.
 */
(function() {
    if (!window.rcmail) return;

    rcmail.addEventListener('init', function() {
        if (rcmail.task !== 'addressbook') return;
        var list = rcmail.contact_list;
        if (!list) return;

        // Versteckte Live-Region (elastic .voice = screenreader-only).
        var live = document.getElementById('caldav-contact-live');
        if (!live) {
            live = document.createElement('div');
            live.id = 'caldav-contact-live';
            live.className = 'voice';
            live.setAttribute('aria-live', 'assertive');
            live.setAttribute('aria-atomic', 'true');
            document.body.appendChild(live);
        }

        var lastId = null;
        var announce = function() {
            var id = list.get_single_selection ? list.get_single_selection() : null;
            if (id == null || id === lastId) return;
            lastId = id;
            var row = (list.rows && list.rows[id]) ? list.rows[id].obj : null;
            if (!row) return;
            var name = (row.textContent || '').replace(/\s+/g, ' ').trim();
            if (!name) return;
            // Erst leeren, dann mit kleinem Delay setzen -> NVDA erkennt die Aenderung
            // auch bei identischem Folgewert zuverlaessig.
            live.textContent = '';
            window.setTimeout(function() { live.textContent = name; }, 30);
        };

        list.addEventListener('select', announce);
    });
})();
