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
            + '<div class="location-wrap">'
            + '<input type="text" id="ev-location" class="form-control" value="' + rcmail.quote_html(ev.location || '') + '" autocomplete="off" />'
            + '<input type="hidden" id="ev-location-geo" value="' + (ev.location_geo || '') + '" />'
            + '<ul id="ev-location-results" class="location-results" role="listbox" aria-label="' + caldav_suite.label('location_results') + '"></ul>'
            + '</div></div>'
            + '<div class="prop"><label for="ev-allday">'
            + '<input type="checkbox" id="ev-allday"' + (ev.allDay ? ' checked' : '') + ' /> '
            + caldav_suite.label('allday') + '</label></div>'
            + '<div class="prop"><label for="ev-start">' + caldav_suite.label('start') + '</label>'
            + '<input type="datetime-local" id="ev-start" class="form-control" value="' + ev.start + '" /></div>'
            + '<div class="prop"><label for="ev-end">' + caldav_suite.label('end') + '</label>'
            + '<input type="datetime-local" id="ev-end" class="form-control" value="' + ev.end + '" /></div>'
            + '<div class="prop"><label for="ev-travel">' + caldav_suite.label('travel_time') + '</label>'
            + '<select id="ev-travel" class="form-control">'
            + '<option value="">' + caldav_suite.label('travel_none') + '</option>'
            + '<option value="auto"' + (ev.travel_mode === 'auto' ? ' selected' : '') + '>' + caldav_suite.label('travel_auto') + '</option>'
            + '<option value="15"' + (ev.travel_mode === '15' ? ' selected' : '') + '>15 min</option>'
            + '<option value="30"' + (ev.travel_mode === '30' ? ' selected' : '') + '>30 min</option>'
            + '<option value="45"' + (ev.travel_mode === '45' ? ' selected' : '') + '>45 min</option>'
            + '<option value="60"' + (ev.travel_mode === '60' ? ' selected' : '') + '>1 h</option>'
            + '<option value="90"' + (ev.travel_mode === '90' ? ' selected' : '') + '>1,5 h</option>'
            + '<option value="120"' + (ev.travel_mode === '120' ? ' selected' : '') + '>2 h</option>'
            + '</select></div>'
            + '<div class="prop"><label for="ev-reminder">' + caldav_suite.label('reminder') + '</label>'
            + '<select id="ev-reminder" class="form-control">'
            + '<option value="">' + caldav_suite.label('reminder_none') + '</option>'
            + '<option value="0"' + (ev.reminder_minutes === '0' ? ' selected' : '') + '>' + caldav_suite.label('reminder_at_time') + '</option>'
            + '<option value="5"' + (ev.reminder_minutes === '5' ? ' selected' : '') + '>5 min</option>'
            + '<option value="10"' + (ev.reminder_minutes === '10' ? ' selected' : '') + '>10 min</option>'
            + '<option value="15"' + (ev.reminder_minutes === '15' ? ' selected' : '') + '>15 min</option>'
            + '<option value="30"' + (ev.reminder_minutes === '30' ? ' selected' : '') + '>30 min</option>'
            + '<option value="60"' + (ev.reminder_minutes === '60' ? ' selected' : '') + '>1 h</option>'
            + '<option value="120"' + (ev.reminder_minutes === '120' ? ' selected' : '') + '>2 h</option>'
            + '<option value="1440"' + (ev.reminder_minutes === '1440' ? ' selected' : '') + '>1 Tag</option>'
            + '</select></div>'
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
                        location_geo: dlg.find('#ev-location-geo').val(),
                        description: dlg.find('#ev-desc').val(),
                        travel_mode: dlg.find('#ev-travel').val(),
                        reminder_minutes: dlg.find('#ev-reminder').val(),
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

        // Location autocomplete (Photon or Nominatim)
        var searchTimer = null;
        dlg.find('#ev-location').on('input', function() {
            var q = $(this).val();
            clearTimeout(searchTimer);
            if (q.length < 3) { dlg.find('#ev-location-results').empty().hide(); return; }
            searchTimer = setTimeout(function() { caldav_geocode.search(q, dlg); }, 300);
        }).on('keydown', function(e) {
            var results = dlg.find('#ev-location-results li');
            if (!results.length) return;
            var active = results.filter('.active');
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                var next = active.length ? active.removeClass('active').next() : results.first();
                if (!next.length) next = results.first();
                next.addClass('active').focus();
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                var prev = active.length ? active.removeClass('active').prev() : results.last();
                if (!prev.length) prev = results.last();
                prev.addClass('active').focus();
            } else if (e.key === 'Enter' && active.length) {
                e.preventDefault();
                active.click();
            } else if (e.key === 'Escape') {
                dlg.find('#ev-location-results').empty().hide();
            }
        });

        dlg.find('#ev-title').focus();
    }
};

/**
 * Geocoding module - supports Photon and Nominatim backends.
 */
window.caldav_geocode = {
    search: function(query, dlg) {
        var provider = rcmail.env.caldav_geocode_provider || 'photon';
        var baseUrl = rcmail.env.caldav_geocode_url || '';
        var url;

        if (provider === 'nominatim') {
            url = (baseUrl || 'https://nominatim.openstreetmap.org') + '/search';
            url += '?format=json&addressdetails=1&limit=5&q=' + encodeURIComponent(query);
            if (rcmail.env.caldav_geocode_lang) url += '&accept-language=' + rcmail.env.caldav_geocode_lang;
        } else {
            url = (baseUrl || 'https://photon.komoot.io') + '/api';
            url += '?limit=5&q=' + encodeURIComponent(query);
            if (rcmail.env.caldav_geocode_lang) url += '&lang=' + rcmail.env.caldav_geocode_lang;
        }

        $.getJSON(url, function(data) {
            var results = caldav_geocode.parse(data, provider);
            caldav_geocode.renderResults(results, dlg);
        }).fail(function() {
            dlg.find('#ev-location-results').html('<li class="hint">Suche fehlgeschlagen</li>').show();
        });
    },

    parse: function(data, provider) {
        var results = [];
        if (provider === 'nominatim') {
            (data || []).forEach(function(item) {
                results.push({
                    name: item.display_name,
                    lat: parseFloat(item.lat),
                    lng: parseFloat(item.lon)
                });
            });
        } else {
            // Photon
            ((data && data.features) || []).forEach(function(f) {
                var p = f.properties || {};
                var parts = [];
                if (p.name) parts.push(p.name);
                if (p.street) {
                    var street = p.street;
                    if (p.housenumber) street += ' ' + p.housenumber;
                    parts.push(street);
                }
                if (p.postcode || p.city) {
                    parts.push((p.postcode ? p.postcode + ' ' : '') + (p.city || ''));
                }
                if (p.country && p.country !== 'Deutschland' && p.country !== 'Germany') {
                    parts.push(p.country);
                }
                var name = parts.join(', ') || p.name || 'Unbekannt';
                var coords = f.geometry && f.geometry.coordinates;
                results.push({
                    name: name,
                    lat: coords ? coords[1] : null,
                    lng: coords ? coords[0] : null
                });
            });
        }
        return results;
    },

    renderResults: function(results, dlg) {
        var list = dlg.find('#ev-location-results');
        list.empty();
        if (!results.length) { list.hide(); return; }

        results.forEach(function(r) {
            var li = $('<li>')
                .attr('role', 'option')
                .attr('tabindex', '-1')
                .text(r.name)
                .data('geo', r.lat && r.lng ? r.lat + ',' + r.lng : '')
                .click(function() {
                    dlg.find('#ev-location').val(r.name);
                    dlg.find('#ev-location-geo').val(r.lat && r.lng ? r.lat + ',' + r.lng : '');
                    list.empty().hide();
                    dlg.find('#ev-location').focus();
                    caldav_suite.announce(r.name + ' ausgewählt');
                })
                .on('keydown', function(e) {
                    if (e.key === 'Enter' || e.key === ' ') { $(this).click(); e.preventDefault(); }
                });
            list.append(li);
        });
        list.show();
    }
};
