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
     * Makes a list screenreader-navigable: ARIA listbox + roving tabindex +
     * arrow navigation. Focus sits directly on the active role="option" ->
     * NVDA automatically switches into focus mode and arrow up/down moves
     * cleanly from item to item (like the mail/contact list).
     *
     * container: jQuery or DOM element (becomes the listbox).
     * opts:
     *   itemSelector  CSS selector of the items (default '[role="option"]')
     *   label         aria-label of the list
     *   onActivate(item, index)  Enter (and Space, if no onToggle)
     *   onToggle(item, index)    Space (optional, e.g. check off a task)
     */
    makeListNavigable: function(container, opts) {
        container = (container && container.jquery) ? container[0] : container;
        if (!container) return;
        opts = opts || {};
        var itemSel = opts.itemSelector || '[role="option"]';

        // aria-activedescendant pattern: EXACTLY ONE tab stop (the container). Arrow keys
        // move the active option, focus stays on the container. Works the same in
        // Firefox+NVDA and Chromium (roving tabindex got stuck in Firefox).
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
            getItems().forEach(function(it) {
                var sel = it === el;
                it.setAttribute('aria-selected', sel ? 'true' : 'false');
                it.classList.toggle('selected', sel);
            });
            container.setAttribute('aria-activedescendant', el.id);
            // Optional announcement on selection change (in addition to aria-activedescendant,
            // because NVDA does not always announce the latter reliably when arrowing).
            if (typeof opts.onChange === 'function') {
                opts.onChange(el);
            }
            // Scroll only the local scroller (nearest .scroller container), NOT the
            // whole page layout. Otherwise the list view shifts the layout
            // (sidebar disappears, list becomes 100% wide).
            if (scroll !== false) {
                var sc = el.closest && el.closest('.scroller');
                if (sc) {
                    var top = el.offsetTop - sc.offsetTop;
                    if (top < sc.scrollTop) sc.scrollTop = top;
                    else if (top + el.offsetHeight > sc.scrollTop + sc.clientHeight) {
                        sc.scrollTop = top + el.offsetHeight - sc.clientHeight;
                    }
                }
            }
        };

        getItems().forEach(function(it, i) {
            it.setAttribute('role', 'option');
            if (!it.id) it.id = (container.id || 'lb') + '-opt-' + i;
            it.setAttribute('aria-selected', 'false');
            it.classList.remove('selected');
            it.removeAttribute('tabindex');
        });

        // When the list is focused, mark the (first, or the one chosen via
        // opts.initialIndex) option active (if none is active).
        container.addEventListener('focus', function() {
            if (!activeItem()) {
                var items = getItems();
                if (!items.length) return;
                var idx = (typeof opts.initialIndex === 'function') ? opts.initialIndex(items) : 0;
                if (idx < 0) idx = 0;
                setActive(items[idx], false);
            }
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
        // Mouse click on an option -> set it active (focus stays on the container).
        container.addEventListener('click', function(e) {
            var it = e.target.closest && e.target.closest(itemSel);
            if (it && container.contains(it)) setActive(it, false);
        });
    },

    /** After a re-render, restores the active option with [attr="value"]
     *  (activedescendant) and focuses the listbox container. */
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
