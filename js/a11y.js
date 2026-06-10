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
    }
};
