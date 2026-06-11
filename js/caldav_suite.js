/**
 * CalDAV Suite - Shared foundation for Calendar and Tasks views.
 * Provides AJAX helpers, dialog utilities, and settings test connection.
 */

window.caldav_suite = {
    labels: {},

    label: function(key) {
        return rcmail.get_label(key, 'caldav_suite') || key;
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
        return d.getDate() + '.' + (d.getMonth() + 1) + '.' + d.getFullYear();
    },

    formatDateLong: function(dateStr) {
        if (!dateStr) return '';
        var d = new Date(dateStr);
        var days = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
        var months = ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];
        return days[d.getDay()] + ', ' + d.getDate() + '. ' + months[d.getMonth()] + ' ' + d.getFullYear();
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

    $('#caldav-test-result').text('Testing...').css('color', '');
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
                var msg = '✓ ' + data.calendars + ' Kalender, ' + data.tasklists + ' Aufgabenlisten, ' + (data.addressbooks || 0) + ' Adressbücher';
                $('#caldav-test-result').text(msg).css('color', 'green');
            } else {
                $('#caldav-test-result').text('✗ Verbindung fehlgeschlagen').css('color', 'red');
            }
        });
    });
}
