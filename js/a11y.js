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

        container.setAttribute('role', 'listbox');
        container.setAttribute('tabindex', '-1');
        if (opts.label) container.setAttribute('aria-label', opts.label);

        var getItems = function() {
            return Array.prototype.slice.call(container.querySelectorAll(itemSel));
        };
        var setActive = function(el, doFocus) {
            getItems().forEach(function(it) {
                var on = it === el;
                it.setAttribute('tabindex', on ? '0' : '-1');
                it.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            if (el && doFocus !== false) el.focus();
        };

        getItems().forEach(function(it, i) {
            it.setAttribute('role', 'option');
            it.setAttribute('aria-selected', 'false');
            it.setAttribute('tabindex', i === 0 ? '0' : '-1');
        });

        container.addEventListener('keydown', function(e) {
            var items = getItems();
            if (!items.length) return;
            var cur = document.activeElement.closest ? document.activeElement.closest(itemSel) : null;
            var idx = items.indexOf(cur);
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
        // Klick / programmatischer Fokus -> Roving-Tabindex synchron halten
        container.addEventListener('focusin', function(e) {
            var it = e.target.closest && e.target.closest(itemSel);
            if (it && container.contains(it)) setActive(it, false);
        });
    },

    /** Fokussiert nach einem Re-Render wieder das Item mit [attr="value"]. */
    focusItemByAttr: function(container, attr, value) {
        container = (container && container.jquery) ? container[0] : container;
        if (!container || value == null) return false;
        var el = container.querySelector('[' + attr + '="' + String(value).replace(/"/g, '\\"') + '"]');
        if (el) { el.setAttribute('tabindex', '0'); el.focus(); return true; }
        return false;
    }
};
