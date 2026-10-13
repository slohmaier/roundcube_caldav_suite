/**
 * CalDAV Suite - makes the address book/group sidebar (#directorylist) navigable + speaking.
 *
 * Roundcube's #directorylist is <ul role="tree"> with <li role="treeitem">, but WITHOUT
 * tabindex -> nothing is focusable, NVDA shows only braille, does not speak. Also
 * Roundcube's rcube_treelist rebuilds the list after loading (and on group changes),
 * and this script runs in the <head> BEFORE `rcmail` exists.
 *
 * Therefore: poll for rcmail+address book task, then apply our aria-activedescendant
 * pattern (caldav_a11y.makeListNavigable) until the list is built; a
 * MutationObserver re-applies the items after every rebuild.
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
        if (!ul.querySelectorAll('li').length) return false; // not built yet
        setupItems(ul);
        if (bound) return true;
        bound = true;
        caldav_a11y.makeListNavigable(ul, {
            itemSelector: 'li',
            label: (window.caldav_suite && caldav_suite.label) ? caldav_suite.label('addressbooks') : 'Address books',
            onActivate: function(li) { var a = li.querySelector('a'); if (a) a.click(); },
            onToggle:   function(li) { var a = li.querySelector('a'); if (a) a.click(); }
        });
        try { new MutationObserver(function() { setupItems(ul); }).observe(ul, { childList: true }); } catch (e) {}
        return true;
    }

    var tries = 0;
    (function boot() {
        // Wait for rcmail + address book task (script runs early in the <head>).
        if (!window.rcmail || !rcmail.env || rcmail.env.task !== 'addressbook') {
            if (++tries < 60) window.setTimeout(boot, 100);
            return;
        }
        // Retry until the treelist widget has built the list and enhance applies.
        var n = 0;
        (function attempt() { if (!enhance() && ++n < 20) window.setTimeout(attempt, 250); })();
    })();
})();
