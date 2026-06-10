/**
 * CalDAV Suite - Event create/edit dialog
 */

window.caldav_event_dialog = {
    open: function(eventData, calendars) {
        var isEdit = eventData && eventData.url;
        var title = isEdit ? caldav_suite.label('edit_event') : caldav_suite.label('new_event');

        var now = new Date();
        var defaults = {
            summary: '',
            location: '',
            description: '',
            start: now.toISOString().substr(0, 16),
            end: new Date(now.getTime() + 3600000).toISOString().substr(0, 16),
            allDay: false,
            calendarId: calendars.length ? calendars[0].id : ''
        };

        var ev = Object.assign({}, defaults, eventData || {});

        // Format dates for input fields
        if (ev.start && ev.start.length > 16) ev.start = ev.start.substr(0, 16);
        if (ev.end && ev.end.length > 16) ev.end = ev.end.substr(0, 16);

        var calOptions = '';
        calendars.forEach(function(cal) {
            var sel = cal.id === ev.calendarId ? ' selected' : '';
            calOptions += '<option value="' + cal.url + '"' + sel + '>' + rcmail.quote_html(cal.name) + '</option>';
        });

        var html = '<form class="propform" id="event-form">'
            + '<div class="prop"><label for="ev-title">' + caldav_suite.label('title') + '</label>'
            + '<input type="text" id="ev-title" class="form-control" value="' + rcmail.quote_html(ev.summary) + '" required /></div>'
            + '<div class="prop"><label for="ev-location">' + caldav_suite.label('location') + '</label>'
            + '<input type="text" id="ev-location" class="form-control" value="' + rcmail.quote_html(ev.location || '') + '" /></div>'
            + '<div class="prop"><label for="ev-allday">'
            + '<input type="checkbox" id="ev-allday"' + (ev.allDay ? ' checked' : '') + ' /> '
            + caldav_suite.label('allday') + '</label></div>'
            + '<div class="prop"><label for="ev-start">' + caldav_suite.label('start') + '</label>'
            + '<input type="datetime-local" id="ev-start" class="form-control" value="' + ev.start + '" /></div>'
            + '<div class="prop"><label for="ev-end">' + caldav_suite.label('end') + '</label>'
            + '<input type="datetime-local" id="ev-end" class="form-control" value="' + ev.end + '" /></div>'
            + '<div class="prop"><label for="ev-calendar">' + caldav_suite.label('select_calendar') + '</label>'
            + '<select id="ev-calendar" class="form-control">' + calOptions + '</select></div>'
            + '<div class="prop"><label for="ev-desc">' + caldav_suite.label('description') + '</label>'
            + '<textarea id="ev-desc" class="form-control" rows="3">' + rcmail.quote_html(ev.description || '') + '</textarea></div>'
            + '</form>';

        var buttons = [
            {
                label: caldav_suite.label('save'),
                action: function(dlg) {
                    var formData = {
                        title: dlg.find('#ev-title').val(),
                        location: dlg.find('#ev-location').val(),
                        description: dlg.find('#ev-desc').val(),
                        start: dlg.find('#ev-start').val(),
                        end: dlg.find('#ev-end').val(),
                        allday: dlg.find('#ev-allday').is(':checked'),
                        uid: ev.uid || ''
                    };
                    rcmail.http_post('plugin.caldav-event-save', {
                        _event: JSON.stringify(formData),
                        _calendar_url: dlg.find('#ev-calendar').val(),
                        _url: ev.url || '',
                        _etag: ev.etag || ''
                    });
                }
            },
            { label: caldav_suite.label('cancel'), action: null }
        ];

        if (isEdit) {
            buttons.splice(1, 0, {
                label: caldav_suite.label('delete'),
                action: function() {
                    if (confirm(caldav_suite.label('confirm_delete_event'))) {
                        rcmail.http_post('plugin.caldav-event-delete', {
                            _url: ev.url,
                            _etag: ev.etag || ''
                        });
                    }
                }
            });
        }

        var dlg = caldav_suite.dialog(title, html, buttons);

        // Toggle time inputs for all-day events
        dlg.find('#ev-allday').change(function() {
            var isAllDay = this.checked;
            var startInput = dlg.find('#ev-start');
            var endInput = dlg.find('#ev-end');
            if (isAllDay) {
                startInput.attr('type', 'date').val(startInput.val().substr(0, 10));
                endInput.attr('type', 'date').val(endInput.val().substr(0, 10));
            } else {
                startInput.attr('type', 'datetime-local');
                endInput.attr('type', 'datetime-local');
            }
        });

        dlg.find('#ev-title').focus();
    }
};
