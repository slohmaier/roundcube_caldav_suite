/**
 * CalDAV Suite - Accessibility helpers
 */

window.caldav_a11y = {
    announce: function(message) {
        caldav_suite.announce(message);
    },

    trapFocus: function(container) {
        var focusable = container.find('a, button, input, select, textarea, [tabindex]:not([tabindex="-1"])').filter(':visible');
        if (focusable.length === 0) return;

        var first = focusable.first();
        var last = focusable.last();

        container.on('keydown.focustrap', function(e) {
            if (e.key !== 'Tab') return;
            if (e.shiftKey) {
                if (document.activeElement === first[0]) {
                    last.focus();
                    e.preventDefault();
                }
            } else {
                if (document.activeElement === last[0]) {
                    first.focus();
                    e.preventDefault();
                }
            }
        });
    },

    releaseFocus: function(container) {
        container.off('keydown.focustrap');
    },

    manageFocus: function(element) {
        this._previousFocus = document.activeElement;
        if (element && element.length) {
            element.focus();
        }
    },

    restoreFocus: function() {
        if (this._previousFocus) {
            this._previousFocus.focus();
            this._previousFocus = null;
        }
    },

    /**
     * Macht eine Liste screenreader-navigierbar: ARIA-Listbox + Roving-Tabindex +
     * Pfeil-Navigation. Der Fokus liegt direkt auf dem aktiven role="option" ->
     * NVDA schaltet automatisch in den Fokus-Modus und Pfeil-hoch/runter springt
     * sauber von Item zu Item (wie Mail-/Kontaktliste).
     *
     * container: jQuery- oder DOM-Element (wird zur Listbox).
     * opts:
     *   itemSelector  CSS-Selektor der Items (default '[role="option"]')
     *   label         aria-label der Liste
     *   onActivate(item, index)  Enter (und Leertaste, falls kein onToggle)
     *   onToggle(item, index)    Leertaste (optional, z.B. Aufgabe ab-/anhaken)
     */
    makeListNavigable: function(container, opts) {
        container = (container && container.jquery) ? container[0] : container;
        if (!container) return;
        opts = opts || {};
        var itemSel = opts.itemSelector || '[role="option"]';

        // aria-activedescendant-Muster: GENAU EIN Tab-Stopp (der Container). Pfeiltasten
        // bewegen die aktive Option, der Fokus bleibt auf dem Container. Funktioniert in
        // Firefox+NVDA und Chromium gleich (Roving-Tabindex blieb in Firefox haengen).
        container.setAttribute('role', 'listbox');
        container.setAttribute('tabindex', '0');
        if (opts.label) container.setAttribute('aria-label', opts.label);

        var getItems = function() {
            return Array.prototype.slice.call(container.querySelectorAll(itemSel));
        };
        var activeItem = function() {
            var id = container.getAttribute('aria-activedescendant');
            return id ? container.querySelector('#' + (window.CSS && CSS.escape ? CSS.escape(id) : id)) : null;
        };
        var setActive = function(el, scroll) {
            if (!el) return;
            getItems().forEach(function(it) { it.setAttribute('aria-selected', it === el ? 'true' : 'false'); });
            container.setAttribute('aria-activedescendant', el.id);
            if (scroll !== false && el.scrollIntoView) el.scrollIntoView({ block: 'nearest' });
        };

        getItems().forEach(function(it, i) {
            it.setAttribute('role', 'option');
            if (!it.id) it.id = (container.id || 'lb') + '-opt-' + i;
            it.setAttribute('aria-selected', 'false');
            it.removeAttribute('tabindex');
        });

        // Beim Fokussieren der Liste die erste Option aktiv markieren (falls keine aktiv).
        container.addEventListener('focus', function() {
            if (!activeItem()) { var items = getItems(); if (items.length) setActive(items[0], false); }
        });

        container.addEventListener('keydown', function(e) {
            var items = getItems();
            if (!items.length) return;
            var idx = items.indexOf(activeItem());
            if (idx < 0) idx = 0;
            switch (e.key) {
                case 'ArrowDown': e.preventDefault(); setActive(items[Math.min(idx + 1, items.length - 1)]); break;
                case 'ArrowUp':   e.preventDefault(); setActive(items[Math.max(idx - 1, 0)]); break;
                case 'Home':      e.preventDefault(); setActive(items[0]); break;
                case 'End':       e.preventDefault(); setActive(items[items.length - 1]); break;
                case 'Enter':     if (opts.onActivate) { e.preventDefault(); opts.onActivate(items[idx], idx); } break;
                case ' ':
                case 'Spacebar':
                    e.preventDefault();
                    if (opts.onToggle) opts.onToggle(items[idx], idx);
                    else if (opts.onActivate) opts.onActivate(items[idx], idx);
                    break;
            }
        });
        // Maus-Klick auf eine Option -> aktiv setzen (Fokus bleibt am Container).
        container.addEventListener('click', function(e) {
            var it = e.target.closest && e.target.closest(itemSel);
            if (it && container.contains(it)) setActive(it, false);
        });
    },

    /** Setzt nach einem Re-Render wieder die aktive Option mit [attr="value"]
     *  (activedescendant) und fokussiert den Listbox-Container. */
    focusItemByAttr: function(container, attr, value) {
        container = (container && container.jquery) ? container[0] : container;
        if (!container || value == null) return false;
        var el = container.querySelector('[' + attr + '="' + String(value).replace(/"/g, '\\"') + '"]');
        if (!el) return false;
        if (!el.id) el.id = (container.id || 'lb') + '-opt-active';
        Array.prototype.forEach.call(container.querySelectorAll('[role="option"]'), function(it) {
            it.setAttribute('aria-selected', it === el ? 'true' : 'false');
        });
        container.setAttribute('aria-activedescendant', el.id);
        container.focus();
        return true;
    }
};
