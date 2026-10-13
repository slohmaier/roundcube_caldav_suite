/**
 * CalDAV Suite - Shared foundation for Calendar and Tasks views.
 * Provides AJAX helpers, dialog utilities, and settings test connection.
 */

window.caldav_suite = {
    labels: {},

    label: function(key) {
        return rcmail.get_label(key, 'caldav_suite') || key;
    },

    // Current locale (from the document language Roundcube sets, e.g. "de"),
    // used for locale-aware date formatting. Falls back to English.
    locale: function() {
        return (document.documentElement.lang || 'en').replace(/_/g, '-');
    },

    ajax: function(action, data, callback) {
        rcmail.http_post('plugin.' + action, { _data: JSON.stringify(data) }, rcmail.set_busy(true, 'loading'));
        if (callback) {
            rcmail.addEventListener('plugin.' + action + '-response', function(response) {
                callback(response);
            });
        }
    },

    dialog: function(title, contentHtml, buttons) {
        var dlg = $('<div>').html(contentHtml);
        var opts = {
            title: title,
            modal: true,
            width: 500,
            maxHeight: Math.max(400, Math.round(window.innerHeight * 0.9)),
            close: function() { $(this).dialog('destroy').remove(); },
            buttons: {}
        };

        if (buttons) {
            buttons.forEach(function(btn) {
                opts.buttons[btn.label] = function() {
                    if (btn.action) btn.action(dlg);
                    if (btn.close !== false) $(this).dialog('close');
                };
            });
        }

        dlg.dialog(opts);
        return dlg;
    },

    announce: function(message) {
        var region = document.getElementById('aria-live-region');
        if (region) {
            region.textContent = '';
            setTimeout(function() { region.textContent = message; }, 100);
        }
    },

    // ---- Loading overlay + screenreader announcement ----
    _loading: {},

    // Places a spinner overlay over the container and periodically announces "Loading...".
    // key: unique identifier (e.g. 'calendar', 'tasks'). containerSel: e.g. '#calendar-grid'.
    showLoading: function(key, containerSel) {
        if (this._loading[key]) return; // already active
        var container = $(containerSel);
        if (!container.length) return;
        var overlay = $('<div class="caldav-loading" role="status" aria-live="polite"></div>')
            .append('<span class="caldav-spinner"></span>')
            .append('<span class="caldav-loading-text">' + this.label('loading') + '</span>');
        container.append(overlay);
        var t0 = Date.now();
        // Repeat the loading announcement every ~2.5s (aria-live) until done.
        var timer = setInterval(function() {
            caldav_suite.announce(caldav_suite.label('loading'));
        }, 2500);
        this._loading[key] = { overlay: overlay, timer: timer, t0: t0 };
    },

    hideLoading: function(key) {
        var l = this._loading[key];
        if (!l) return;
        clearInterval(l.timer);
        l.overlay.remove();
        delete this._loading[key];
    },

    formatTime: function(dateStr) {
        if (!dateStr) return '';
        var d = new Date(dateStr);
        var fmt = rcmail.env.caldav_time_format || '24';
        if (fmt === '12') {
            var h = d.getHours(), m = d.getMinutes();
            var ampm = h >= 12 ? 'PM' : 'AM';
            h = h % 12 || 12;
            return h + ':' + (m < 10 ? '0' : '') + m + ' ' + ampm;
        }
        return d.getHours() + ':' + (d.getMinutes() < 10 ? '0' : '') + d.getMinutes();
    },

    formatDate: function(dateStr) {
        if (!dateStr) return '';
        var d = new Date(dateStr);
        return new Intl.DateTimeFormat(this.locale(), { day: '2-digit', month: '2-digit', year: 'numeric' }).format(d);
    },

    formatDateLong: function(dateStr) {
        if (!dateStr) return '';
        var d = new Date(dateStr);
        return new Intl.DateTimeFormat(this.locale(), { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }).format(d);
    },

    // Localized weekday name; index follows Date.getDay() (0 = Sunday).
    weekdayShort: function(dayIndex) {
        var d = new Date(2024, 0, 7 + dayIndex); // 2024-01-07 is a Sunday
        return new Intl.DateTimeFormat(this.locale(), { weekday: 'short' }).format(d);
    },

    // Localized month name; 0 = January.
    monthShort: function(monthIndex) {
        return new Intl.DateTimeFormat(this.locale(), { month: 'short' }).format(new Date(2024, monthIndex, 1));
    },

    monthLong: function(monthIndex) {
        return new Intl.DateTimeFormat(this.locale(), { month: 'long' }).format(new Date(2024, monthIndex, 1));
    }
};

// Settings: test connection button
window.caldav_suite_test_connection = function() {
    var url = $('#caldav-url').val();
    var username = $('#caldav-username').val();
    var password = $('#caldav-password').val();

    if (!url || !username) {
        $('#caldav-test-result').text('URL and username required').css('color', 'red');
        return;
    }

    $('#caldav-test-result').text(caldav_suite.label('testing')).css('color', '');
    $('#caldav-test-btn').prop('disabled', true);

    rcmail.http_post('plugin.caldav-test-connection', {
        _url: url, _username: username, _password: password
    });
};

if (window.rcmail) {
    rcmail.addEventListener('init', function() {
        rcmail.addEventListener('plugin.caldav-test-result', function(data) {
            $('#caldav-test-btn').prop('disabled', false);
            if (data.success) {
                var msg = '✓ ' + data.calendars + ' ' + caldav_suite.label('calendars')
                    + ', ' + data.tasklists + ' ' + caldav_suite.label('task_lists')
                    + ', ' + (data.addressbooks || 0) + ' ' + caldav_suite.label('addressbooks');
                $('#caldav-test-result').text(msg).css('color', 'green');
            } else {
                $('#caldav-test-result').text('✗ ' + caldav_suite.label('connection_failed')).css('color', 'red');
            }
        });
    });
}
