/**
 * CalDAV Suite - Kontaktliste (Roundcube-Core): Sprachausgabe beim Navigieren.
 *
 * Roundcube fokussiert bei Auswahl die Zelle in subject_column() der Zeile -> DAS
 * loest NVDAs Sprachausgabe aus (so spricht auch die Mail-Liste). Bei manchen
 * Adressbuechern (z.B. CardDAV ueber dieses Plugin) passt subject_column() nicht
 * zur tatsaechlichen Zellenstruktur -> Roundcube fokussiert eine leere/falsche
 * Zelle, NVDA spricht nichts (Braille folgt aber der Zeile).
 *
 * Fix: bei Auswahl gezielt die Namens-Zelle (td.name) der Zeile fokussieren --
 * exakt das, was Roundcube im funktionierenden Fall selbst tut. Rein additiv,
 * korrigiert nur die fokussierte Zelle; fasst Selektion/Tastatur/Braille nicht an.
 */
(function() {
    if (!window.rcmail) return;

    rcmail.addEventListener('init', function() {
        if (rcmail.task !== 'addressbook') return;
        var list = rcmail.contact_list;
        if (!list || !list.addEventListener) return;

        list.addEventListener('select', function() {
            var id = list.get_single_selection ? list.get_single_selection() : null;
            var row = (id != null && list.rows && list.rows[id]) ? list.rows[id].obj : null;
            if (!row) return;
            // Namens-Zelle robust finden (td.name; sonst erste Nicht-Auswahl-Zelle mit Text).
            var cell = row.querySelector('td.name');
            if (!cell) {
                var tds = row.querySelectorAll('td');
                for (var i = 0; i < tds.length; i++) {
                    if (!tds[i].classList.contains('selection') && (tds[i].textContent || '').trim()) { cell = tds[i]; break; }
                }
            }
            // Nur korrigieren, wenn Roundcube NICHT schon die richtige Zelle fokussiert hat.
            if (cell && document.activeElement !== cell) {
                cell.setAttribute('tabindex', '0');
                try { cell.focus({ preventScroll: true }); } catch (e) { cell.focus(); }
            }
        });
    });
})();
