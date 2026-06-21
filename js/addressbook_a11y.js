/**
 * CalDAV Suite - Adressbuch-/Gruppen-Sidebar (#directorylist) navigierbar + sprechend.
 *
 * Roundcubes #directorylist ist <ul role="tree"> mit <li role="treeitem">, aber OHNE
 * tabindex -> nichts ist fokussierbar, NVDA zeigt nur Braille, spricht nicht. Zudem
 * baut Roundcubes rcube_treelist die Liste nach dem Laden (und bei Gruppen-Aenderungen)
 * neu auf, und dieses Skript laeuft im <head> BEVOR `rcmail` existiert.
 *
 * Darum: auf rcmail+Adressbuch-Task pollen, dann unser aria-activedescendant-Muster
 * (caldav_a11y.makeListNavigable) anwenden, bis die Liste aufgebaut ist; ein
 * MutationObserver setzt die Items nach jedem Neuaufbau erneut auf.
 */
(function() {
    var bound = false;

    function setupItems(ul) {
        var idx = 0;
        ul.querySelectorAll('li').forEach(function(li) {
            li.setAttribute('role', 'option');
            if (!li.id) li.id = 'directorylist-opt-' + (idx++);
            if (!li.hasAttribute('aria-selected')) li.setAttribute('aria-selected', 'false');
            var a = li.querySelector('a');
            if (a) {
                li.setAttribute('aria-label', (a.textContent || '').trim());
                a.setAttribute('tabindex', '-1');
                a.setAttribute('aria-hidden', 'true');
            }
        });
        ul.setAttribute('role', 'listbox');
        if (ul.getAttribute('tabindex') === null) ul.setAttribute('tabindex', '0');
    }

    function enhance() {
        var ul = document.getElementById('directorylist');
        if (!ul || !window.caldav_a11y || !caldav_a11y.makeListNavigable) return false;
        if (!ul.querySelectorAll('li').length) return false; // noch nicht aufgebaut
        setupItems(ul);
        if (bound) return true;
        bound = true;
        caldav_a11y.makeListNavigable(ul, {
            itemSelector: 'li',
            label: (window.caldav_suite && caldav_suite.label) ? caldav_suite.label('task_lists') : 'Adressbücher',
            onActivate: function(li) { var a = li.querySelector('a'); if (a) a.click(); },
            onToggle:   function(li) { var a = li.querySelector('a'); if (a) a.click(); }
        });
        try { new MutationObserver(function() { setupItems(ul); }).observe(ul, { childList: true }); } catch (e) {}
        return true;
    }

    var tries = 0;
    (function boot() {
        // Auf rcmail + Adressbuch-Task warten (Skript laeuft frueh im <head>).
        if (!window.rcmail || !rcmail.env || rcmail.env.task !== 'addressbook') {
            if (++tries < 60) window.setTimeout(boot, 100);
            return;
        }
        // Retry, bis das treelist-Widget die Liste aufgebaut hat und enhance greift.
        var n = 0;
        (function attempt() { if (!enhance() && ++n < 20) window.setTimeout(attempt, 250); })();
    })();
})();
