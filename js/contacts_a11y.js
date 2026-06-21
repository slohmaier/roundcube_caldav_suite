/**
 * CalDAV Suite - Kontaktliste (Roundcube-Core) screenreader-tauglich machen.
 *
 * Roundcubes Kontaktliste (#contacts-table) ist role=listbox mit role=option-Zeilen
 * und aria-selected, fuehrt aber KEIN aria-activedescendant. Folge: beim Pfeilen
 * aendert sich nur aria-selected, NVDA verfolgt aber nicht, welche Zeile gerade
 * aktiv ist -> die Liste fuehlt sich "tot" an (wie bei unseren eigenen Listen vor
 * der activedescendant-Umstellung).
 *
 * Fix: aria-activedescendant auf der Liste mitfuehren, sobald Roundcube die
 * Auswahl/den Fokus aendert. Wir fassen Roundcubes Selektion/Tastatur NICHT an
 * (rein additiv), damit Klick/Mehrfachauswahl/Loeschen unveraendert bleiben.
 */
(function() {
    if (!window.rcmail) return;

    rcmail.addEventListener('init', function() {
        if (rcmail.task !== 'addressbook') return;
        var list = rcmail.contact_list;
        var table = document.getElementById('contacts-table');
        if (!list || !table) return;

        var sync = function() {
            var id = list.get_single_selection ? list.get_single_selection() : null;
            var row = (id != null && list.rows && list.rows[id]) ? list.rows[id].obj : null;
            if (row) {
                if (!row.id) row.id = 'rcmrow' + id;
                table.setAttribute('aria-activedescendant', row.id);
            } else {
                table.removeAttribute('aria-activedescendant');
            }
        };

        // Auswahlwechsel (Pfeiltasten/Klick) und Listen-Neuaufbau abdecken.
        list.addEventListener('select', sync);
        list.addEventListener('listupdate', sync);
        rcmail.addEventListener('listupdate', sync);
        sync();
    });
})();
