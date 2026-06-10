/**
 * CalDAV Suite - Calendar View
 * Renders Month, Week, Day, and accessible List views.
 */

(function() {
    if (!window.rcmail) return;

    var state = {
        currentView: 'month',
        currentDate: new Date(),
        calendars: [],
        visibleCalendars: {},
        events: [],
        loading: false
    };

    var DAYS_SHORT = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
    var DAYS_LONG = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];
    var MONTHS = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];

    rcmail.addEventListener('init', function() {
        if (rcmail.task !== 'calendar') return;
        if (!rcmail.env.caldav_configured) {
            $('#calendar-grid').html('<p class="hint">' + caldav_suite.label('no_caldav_configured') + '</p>');
            return;
        }

        state.currentView = rcmail.env.caldav_default_view || 'month';

        // Button handlers
        $('#btn-prev').click(function() { navigate(-1); });
        $('#btn-next').click(function() { navigate(1); });
        $('#btn-today').click(function() { state.currentDate = new Date(); loadAndRender(); });
        $('#btn-new-event').click(function() { caldav_event_dialog.open(null, state.calendars); });

        $('.view-btn').click(function() {
            switchView($(this).data('view'));
        });

        // Keyboard shortcuts
        $(document).on('keydown', function(e) {
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
            if (e.key === 'ArrowLeft') { navigate(-1); e.preventDefault(); }
            if (e.key === 'ArrowRight') { navigate(1); e.preventDefault(); }
            if (e.key === 't' || e.key === 'T') { state.currentDate = new Date(); loadAndRender(); }
            if (e.key === 'm' || e.key === 'M') switchView('month');
            if (e.key === 'w' || e.key === 'W') switchView('week');
            if (e.key === 'd' || e.key === 'D') switchView('day');
            if (e.key === 'l' || e.key === 'L') switchView('list');
            if (e.key === 'n' || e.key === 'N') caldav_event_dialog.open(null, state.calendars);
        });

        // AJAX response handlers
        rcmail.addEventListener('plugin.caldav-calendars-response', function(data) {
            if (data.calendars) {
                state.calendars = data.calendars;
                data.calendars.forEach(function(cal) { state.visibleCalendars[cal.id] = true; });
                renderCalendarList();
                loadEvents();
            }
        });

        rcmail.addEventListener('plugin.caldav-events-response', function(data) {
            state.loading = false;
            if (data.events) {
                state.events = data.events;
                renderCurrentView();
            }
        });

        rcmail.addEventListener('plugin.caldav-event-saved', function(data) {
            if (data.success) loadEvents();
        });
        rcmail.addEventListener('plugin.caldav-event-deleted', function(data) {
            if (data.success) loadEvents();
        });

        // Initial load
        highlightActiveView();
        rcmail.http_post('plugin.caldav-calendars');
    });

    function switchView(view) {
        state.currentView = view;
        highlightActiveView();
        loadAndRender();
        caldav_suite.announce(caldav_suite.label('view_' + view));
    }

    function highlightActiveView() {
        $('.view-btn').removeClass('active');
        $('.view-btn[data-view="' + state.currentView + '"]').addClass('active');
    }

    function navigate(direction) {
        var d = state.currentDate;
        switch (state.currentView) {
            case 'month': d = new Date(d.getFullYear(), d.getMonth() + direction, 1); break;
            case 'week': d = new Date(d.getTime() + direction * 7 * 86400000); break;
            case 'day': d = new Date(d.getTime() + direction * 86400000); break;
            case 'list': d = new Date(d.getFullYear(), d.getMonth() + direction, 1); break;
        }
        state.currentDate = d;
        loadAndRender();
    }

    function loadAndRender() {
        updateTitle();
        loadEvents();
    }

    function loadEvents() {
        var range = getViewRange();
        state.loading = true;
        var visibleIds = Object.keys(state.visibleCalendars).filter(function(id) { return state.visibleCalendars[id]; });
        rcmail.http_post('plugin.caldav-events', {
            _start: range.start.toISOString(),
            _end: range.end.toISOString(),
            _calendars: visibleIds
        });
    }

    function getViewRange() {
        var d = state.currentDate;
        var start, end;
        switch (state.currentView) {
            case 'month':
                start = new Date(d.getFullYear(), d.getMonth(), 1);
                end = new Date(d.getFullYear(), d.getMonth() + 1, 0, 23, 59, 59);
                // Extend to full weeks
                var firstDay = rcmail.env.caldav_first_day || 1;
                while (start.getDay() !== firstDay) start.setDate(start.getDate() - 1);
                while (end.getDay() !== (firstDay + 6) % 7) end.setDate(end.getDate() + 1);
                break;
            case 'week':
                start = new Date(d);
                var dow = start.getDay();
                var firstDay = rcmail.env.caldav_first_day || 1;
                var diff = (dow - firstDay + 7) % 7;
                start.setDate(start.getDate() - diff);
                start.setHours(0, 0, 0, 0);
                end = new Date(start);
                end.setDate(end.getDate() + 6);
                end.setHours(23, 59, 59);
                break;
            case 'day':
                start = new Date(d.getFullYear(), d.getMonth(), d.getDate());
                end = new Date(d.getFullYear(), d.getMonth(), d.getDate(), 23, 59, 59);
                break;
            case 'list':
                start = new Date(d.getFullYear(), d.getMonth(), 1);
                end = new Date(d.getFullYear(), d.getMonth() + 1, 0, 23, 59, 59);
                break;
        }
        return { start: start, end: end };
    }

    function updateTitle() {
        var d = state.currentDate;
        var title = '';
        switch (state.currentView) {
            case 'month':
            case 'list':
                title = MONTHS[d.getMonth()] + ' ' + d.getFullYear();
                break;
            case 'week':
                var range = getViewRange();
                title = range.start.getDate() + '.' + (range.start.getMonth()+1) + '. – ' +
                        range.end.getDate() + '.' + (range.end.getMonth()+1) + '.' + range.end.getFullYear();
                break;
            case 'day':
                title = caldav_suite.formatDateLong(d.toISOString());
                break;
        }
        $('#calendar-title').text(title);
    }

    function renderCurrentView() {
        updateTitle();
        var container = $('#calendar-grid');
        switch (state.currentView) {
            case 'month': renderMonth(container); break;
            case 'week':  renderWeek(container);  break;
            case 'day':   renderDay(container);   break;
            case 'list':  renderList(container);  break;
        }
    }

    // ---- Calendar Sidebar ----

    function renderCalendarList() {
        var html = '<ul class="calendar-list" role="list">';
        state.calendars.forEach(function(cal) {
            var checked = state.visibleCalendars[cal.id] ? 'checked' : '';
            html += '<li>'
                + '<label class="calendar-item">'
                + '<input type="checkbox" ' + checked + ' data-cal-id="' + cal.id + '" aria-label="' + cal.name + '" />'
                + '<span class="cal-color" style="background:' + cal.color + '"></span>'
                + '<span class="cal-name">' + rcmail.quote_html(cal.name) + '</span>'
                + '</label></li>';
        });
        html += '</ul>';
        $('#calendar-list').html(html);

        $('#calendar-list input[type="checkbox"]').change(function() {
            var id = $(this).data('cal-id');
            state.visibleCalendars[id] = this.checked;
            renderCurrentView();
        });
    }

    function getCalendarColor(calendarId) {
        for (var i = 0; i < state.calendars.length; i++) {
            if (state.calendars[i].id === calendarId) return state.calendars[i].color;
        }
        return '#4fc3f7';
    }

    function getVisibleEvents() {
        return state.events.filter(function(ev) {
            return state.visibleCalendars[ev.calendarId] !== false;
        });
    }

    // ---- Month View ----

    function renderMonth(container) {
        var d = state.currentDate;
        var range = getViewRange();
        var today = new Date();
        today.setHours(0, 0, 0, 0);
        var events = getVisibleEvents();

        var html = '<table class="calendar-month" role="grid" aria-label="' + MONTHS[d.getMonth()] + ' ' + d.getFullYear() + '">';
        html += '<thead><tr>';
        var firstDay = rcmail.env.caldav_first_day || 1;
        for (var i = 0; i < 7; i++) {
            var dayIdx = (firstDay + i) % 7;
            var dayNames = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
            html += '<th scope="col">' + dayNames[dayIdx] + '</th>';
        }
        html += '</tr></thead><tbody>';

        var current = new Date(range.start);
        while (current <= range.end) {
            html += '<tr>';
            for (var i = 0; i < 7; i++) {
                var isToday = current.getTime() === today.getTime();
                var isCurrentMonth = current.getMonth() === d.getMonth();
                var cls = 'month-cell';
                if (isToday) cls += ' today';
                if (!isCurrentMonth) cls += ' other-month';

                var dayEvents = getEventsForDate(events, current);
                html += '<td class="' + cls + '" role="gridcell" data-date="' + current.toISOString().substr(0, 10) + '">';
                html += '<span class="day-num">' + current.getDate() + '</span>';
                dayEvents.forEach(function(ev) {
                    var color = getCalendarColor(ev.calendarId);
                    var time = ev.allDay ? '' : caldav_suite.formatTime(ev.start) + ' ';
                    html += '<div class="month-event" style="border-left:3px solid ' + color + '" '
                        + 'data-url="' + ev.url + '" data-etag="' + (ev.etag || '') + '" '
                        + 'tabindex="0" role="button" aria-label="' + rcmail.quote_html(ev.summary) + '">'
                        + '<span class="event-time">' + time + '</span>'
                        + '<span class="event-title">' + rcmail.quote_html(ev.summary) + '</span>'
                        + '</div>';
                });
                html += '</td>';
                current.setDate(current.getDate() + 1);
            }
            html += '</tr>';
        }
        html += '</tbody></table>';
        container.html(html);

        // Click handlers
        container.find('.month-cell').click(function(e) {
            if ($(e.target).closest('.month-event').length) return;
            var date = $(this).data('date');
            caldav_event_dialog.open({ start: date + 'T09:00', end: date + 'T10:00' }, state.calendars);
        });
        container.find('.month-event').click(function() {
            var url = $(this).data('url');
            var ev = state.events.find(function(e) { return e.url === url; });
            if (ev) caldav_event_dialog.open(ev, state.calendars);
        });
    }

    // ---- Week View ----

    function renderWeek(container) {
        var range = getViewRange();
        var events = getVisibleEvents();
        var today = new Date();

        var html = '<div class="week-view" role="grid" aria-label="Week view">';
        html += '<div class="week-header"><div class="time-gutter"></div>';
        var cur = new Date(range.start);
        for (var i = 0; i < 7; i++) {
            var isToday = cur.toDateString() === today.toDateString();
            html += '<div class="week-day-header' + (isToday ? ' today' : '') + '" role="columnheader">'
                + DAYS_SHORT[i] + ' ' + cur.getDate() + '.' + (cur.getMonth()+1)
                + '</div>';
            cur.setDate(cur.getDate() + 1);
        }
        html += '</div>';

        html += '<div class="week-body">';
        for (var h = 0; h < 24; h++) {
            html += '<div class="week-row">';
            html += '<div class="time-gutter">' + (h < 10 ? '0' : '') + h + ':00</div>';
            cur = new Date(range.start);
            for (var d = 0; d < 7; d++) {
                var cellDate = new Date(cur);
                cellDate.setHours(h, 0, 0, 0);
                html += '<div class="week-cell" data-date="' + cellDate.toISOString() + '"></div>';
                cur.setDate(cur.getDate() + 1);
            }
            html += '</div>';
        }
        html += '</div>';

        // Overlay events
        html += '<div class="week-events">';
        cur = new Date(range.start);
        for (var d = 0; d < 7; d++) {
            var dayEvents = getEventsForDate(events, cur).filter(function(e) { return !e.allDay; });
            dayEvents.forEach(function(ev) {
                var s = new Date(ev.start);
                var e = new Date(ev.end);
                var top = (s.getHours() * 60 + s.getMinutes()) / (24 * 60) * 100;
                var height = ((e - s) / 3600000) / 24 * 100;
                var left = d * (100 / 7);
                var color = getCalendarColor(ev.calendarId);
                html += '<div class="week-event" style="top:' + top + '%;height:' + Math.max(height, 2) + '%;left:calc(' + left + '% + 3em);width:calc(' + (100/7) + '% - 3.2em);background:' + color + '30;border-left:3px solid ' + color + '" '
                    + 'data-url="' + ev.url + '" tabindex="0" role="button">'
                    + caldav_suite.formatTime(ev.start) + ' ' + rcmail.quote_html(ev.summary)
                    + '</div>';
            });
            cur.setDate(cur.getDate() + 1);
        }
        html += '</div></div>';

        container.html(html);
        container.find('.week-event').click(function() {
            var url = $(this).data('url');
            var ev = state.events.find(function(e) { return e.url === url; });
            if (ev) caldav_event_dialog.open(ev, state.calendars);
        });
    }

    // ---- Day View ----

    function renderDay(container) {
        var d = state.currentDate;
        var events = getVisibleEvents();
        var dayEvents = getEventsForDate(events, d);

        var html = '<div class="day-view" role="grid" aria-label="Day view">';
        for (var h = 0; h < 24; h++) {
            html += '<div class="day-row">';
            html += '<div class="time-gutter">' + (h < 10 ? '0' : '') + h + ':00</div>';
            html += '<div class="day-cell" data-hour="' + h + '"></div>';
            html += '</div>';
        }

        dayEvents.filter(function(e) { return !e.allDay; }).forEach(function(ev) {
            var s = new Date(ev.start);
            var e = new Date(ev.end);
            var top = (s.getHours() * 60 + s.getMinutes()) / (24 * 60) * 100;
            var height = ((e - s) / 3600000) / 24 * 100;
            var color = getCalendarColor(ev.calendarId);
            html += '<div class="day-event" style="top:' + top + '%;height:' + Math.max(height, 2) + '%;background:' + color + '30;border-left:3px solid ' + color + '" '
                + 'data-url="' + ev.url + '" tabindex="0" role="button">'
                + caldav_suite.formatTime(ev.start) + ' – ' + caldav_suite.formatTime(ev.end) + ' '
                + rcmail.quote_html(ev.summary)
                + (ev.location ? ' (' + rcmail.quote_html(ev.location) + ')' : '')
                + '</div>';
        });

        html += '</div>';
        container.html(html);

        container.find('.day-event').click(function() {
            var url = $(this).data('url');
            var ev = state.events.find(function(e) { return e.url === url; });
            if (ev) caldav_event_dialog.open(ev, state.calendars);
        });
    }

    // ---- List/Agenda View (accessible) ----

    function renderList(container) {
        var events = getVisibleEvents();
        events.sort(function(a, b) { return (a.start || '') < (b.start || '') ? -1 : 1; });

        if (events.length === 0) {
            container.html('<p class="hint">' + caldav_suite.label('no_events') + '</p>');
            return;
        }

        var html = '<div class="list-view" role="list" aria-label="Event list">';
        var lastDate = '';

        events.forEach(function(ev) {
            var dateStr = ev.start ? ev.start.substr(0, 10) : '';
            if (dateStr !== lastDate) {
                if (lastDate) html += '</ul>';
                html += '<h3 class="list-date">' + caldav_suite.formatDateLong(ev.start) + '</h3>';
                html += '<ul class="list-events" role="list">';
                lastDate = dateStr;
            }

            var color = getCalendarColor(ev.calendarId);
            var time = ev.allDay ? 'Ganztägig' : caldav_suite.formatTime(ev.start) + ' – ' + caldav_suite.formatTime(ev.end);
            html += '<li class="list-event" data-url="' + ev.url + '" tabindex="0" role="listitem">'
                + '<span class="event-color-dot" style="background:' + color + '" aria-hidden="true"></span>'
                + '<span class="event-time">' + time + '</span>'
                + '<span class="event-summary">' + rcmail.quote_html(ev.summary) + '</span>'
                + (ev.location ? '<span class="event-location">' + rcmail.quote_html(ev.location) + '</span>' : '')
                + '</li>';
        });
        html += '</ul></div>';

        container.html(html);
        container.find('.list-event').click(function() {
            var url = $(this).data('url');
            var ev = state.events.find(function(e) { return e.url === url; });
            if (ev) caldav_event_dialog.open(ev, state.calendars);
        }).on('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') { $(this).click(); e.preventDefault(); }
        });
    }

    // ---- Helpers ----

    function getEventsForDate(events, date) {
        var dayStart = new Date(date.getFullYear(), date.getMonth(), date.getDate()).getTime();
        var dayEnd = dayStart + 86400000;
        return events.filter(function(ev) {
            var evStart = new Date(ev.start).getTime();
            var evEnd = new Date(ev.end || ev.start).getTime();
            return evStart < dayEnd && evEnd > dayStart;
        });
    }
})();
