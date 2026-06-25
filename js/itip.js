/**
 * CalDAV Suite — iMIP/iTIP Einladungs-UI in der Mailansicht.
 *
 * Die Einladungs-Box wird serverseitig ueber den Mailtext gerendert
 * (template_object_messagebody). Hier verdrahten wir Annehmen / Mit Vorbehalt /
 * Ablehnen und "Anderen Termin vorschlagen" (modaler Dialog). Antworten gehen per
 * AJAX an plugin.caldav-itip-reply bzw. plugin.caldav-itip-counter.
 *
 * a11y: Status-Updates ueber die boxeigene aria-live-Region; der Vorschlag-Dialog
 * nutzt caldav_suite.dialog() (jQuery-UI, Fokus-Management + Esc wie Event-Dialog).
 */
(function() {
    var box = null;

    function L(k) {
        return (window.caldav_suite && caldav_suite.label) ? caldav_suite.label(k) : k;
    }

    function announce(msg) {
        if (!box || !msg) return;
        var live = box.querySelector('.caldav-itip-live');
        if (live) {
            live.textContent = '';
            setTimeout(function() { live.textContent = msg; }, 80);
        }
    }

    function setStatus(text) {
        if (!box) return;
        var st = box.querySelector('#caldav-itip-status');
        if (st && text) st.textContent = text;
    }

    function disableAll(disabled) {
        if (!box) return;
        box.querySelectorAll('button').forEach(function(b) { b.disabled = disabled; });
    }

    function lockReplies() {
        if (!box) return;
        box.querySelectorAll('.caldav-itip-reply, .caldav-itip-propose').forEach(function(b) { b.disabled = true; });
    }

    function postReply(partstat) {
        if (!box) return;
        var calSel = box.querySelector('#caldav-itip-cal');
        disableAll(true);
        rcmail.http_post('plugin.caldav-itip-reply', {
            _partstat:     partstat,
            _uid:          box.getAttribute('data-msg-uid'),
            _mbox:         box.getAttribute('data-mbox'),
            _mime_id:      box.getAttribute('data-mime-id'),
            _calendar_url: calSel ? calSel.value : ''
        }, rcmail.set_busy(true, 'loading'));
    }

    function openProposeDialog() {
        if (!box) return;
        var start = box.getAttribute('data-start') || '';
        var end   = box.getAttribute('data-end') || '';
        var html =
              '<div class="prop"><label for="itip-cn-start">' + rcmail.quote_html(L('itip_new_start')) + '</label>'
            + '<input type="datetime-local" id="itip-cn-start" class="form-control" value="' + rcmail.quote_html(start) + '"></div>'
            + '<div class="prop"><label for="itip-cn-end">' + rcmail.quote_html(L('itip_new_end')) + '</label>'
            + '<input type="datetime-local" id="itip-cn-end" class="form-control" value="' + rcmail.quote_html(end) + '"></div>'
            + '<div class="prop"><label for="itip-cn-comment">' + rcmail.quote_html(L('itip_comment')) + '</label>'
            + '<textarea id="itip-cn-comment" class="form-control" rows="3"></textarea></div>';

        var dlg = caldav_suite.dialog(L('itip_propose_title'), html, [
            { label: L('itip_send_proposal'), action: function(d) {
                rcmail.http_post('plugin.caldav-itip-counter', {
                    _uid:     box.getAttribute('data-msg-uid'),
                    _mbox:    box.getAttribute('data-mbox'),
                    _mime_id: box.getAttribute('data-mime-id'),
                    _start:   d.find('#itip-cn-start').val(),
                    _end:     d.find('#itip-cn-end').val(),
                    _comment: d.find('#itip-cn-comment').val()
                }, rcmail.set_busy(true, 'loading'));
            } },
            { label: L('cancel'), action: function() {} }
        ]);
        setTimeout(function() { dlg.find('#itip-cn-start').focus(); }, 50);
    }

    function onResponse(data) {
        if (!data) return;
        if (data.success) {
            setStatus(data.status);
            if (data.lock) lockReplies(); else disableAll(false);
            announce(data.message || data.status || '');
        } else {
            disableAll(false);
            announce(data.message || L('itip_error'));
            rcmail.display_message(data.message || L('itip_error'), 'error');
        }
    }

    function init() {
        box = document.querySelector('.caldav-itip');
        if (!box) return;
        box.querySelectorAll('.caldav-itip-reply').forEach(function(btn) {
            btn.addEventListener('click', function() { postReply(btn.getAttribute('data-partstat')); });
        });
        var prop = box.querySelector('.caldav-itip-propose');
        if (prop) prop.addEventListener('click', openProposeDialog);
    }

    if (window.rcmail) {
        rcmail.addEventListener('init', init);
        rcmail.addEventListener('plugin.caldav-itip-response', onResponse);
    }
})();
