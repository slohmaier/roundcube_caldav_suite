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
        var calAria = function(cal, on) {
            return cal.name + ', ' + (on ? 'eingeblendet' : 'ausgeblendet');
        };
        // Navigierbare Liste wie Aufgabenlisten-Sidebar: role=option-Items, KEINE
        // fokussierbaren Kinder (kein <input>). Status via .checked-Klasse + aria-label.
        var html = '<ul id="calendar-ul" class="calendar-list">';
        state.calendars.forEach(function(cal) {
            var on = !!state.visibleCalendars[cal.id];
            html += '<li class="calendar-item' + (on ? ' checked' : '') + '" data-cal-id="' + cal.id + '"'
                + ' aria-label="' + rcmail.quote_html(calAria(cal, on)) + '">'
                + '<span class="calendar-check" aria-hidden="true"></span>'
                + '<span class="cal-color" aria-hidden="true" style="background:' + cal.color + '"></span>'
                + '<span class="cal-name" aria-hidden="true">' + rcmail.quote_html(cal.name) + '</span>'
                + '</li>';
        });
        html += '</ul>';
        $('#calendar-list').html(html);

        var toggleCal = function(item) {
            if (!item) return;
            var id = item.getAttribute('data-cal-id');
            var on = !item.classList.contains('checked');
            state.visibleCalendars[id] = on;
            item.classList.toggle('checked', on);
            var cal = state.calendars.find(function(c) { return String(c.id) === String(id); });
            if (cal) item.setAttribute('aria-label', calAria(cal, on));
            renderCurrentView();
        };

        $('#calendar-list .calendar-item').click(function() { toggleCal(this); });

        caldav_a11y.makeListNavigable(document.getElementById('calendar-ul'), {
            itemSelector: '.calendar-item',
            label: caldav_suite.label('calendars'),
            onToggle: toggleCal,
            onActivate: toggleCal
        });
    }

    function getCalendarColor(calendarId) {
        for (var i = 0; i < state.calendars.length; i++) {
            if (state.calendars[i].id === calendarId) return state.calendars[i].color;
        }
        return '#4fc3f7';
    }

    function getCalendarName(calendarId) {
        for (var i = 0; i < state.calendars.length; i++) {
            if (state.calendars[i].id === calendarId) return state.calendars[i].name;
        }
        return '';
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

        // Header with day names
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

        // All-day events row
        var hasAllDay = false;
        cur = new Date(range.start);
        var allDayHtml = '<div class="week-allday"><div class="time-gutter">ganzt.</div>';
        for (var d = 0; d < 7; d++) {
            var allDayEvents = getEventsForDate(events, cur).filter(function(e) { return e.allDay; });
            allDayHtml += '<div class="week-allday-cell">';
            allDayEvents.forEach(function(ev) {
                hasAllDay = true;
                var color = getCalendarColor(ev.calendarId);
                allDayHtml += '<div class="week-allday-event" style="background:' + color + '30;border-left:3px solid ' + color + '" '
                    + 'data-url="' + ev.url + '" tabindex="0" role="button">'
                    + rcmail.quote_html(ev.summary) + '</div>';
            });
            allDayHtml += '</div>';
            cur.setDate(cur.getDate() + 1);
        }
        allDayHtml += '</div>';
        if (hasAllDay) html += allDayHtml;

        // Time grid with events inline
        html += '<div class="week-body">';

        // Build per-day event lists for positioning
        var dayColumns = [];
        cur = new Date(range.start);
        for (var d = 0; d < 7; d++) {
            dayColumns.push(getEventsForDate(events, cur).filter(function(e) { return !e.allDay; }));
            cur.setDate(cur.getDate() + 1);
        }

        // Pre-compute column assignments for overlapping events
        var dayEventColumns = [];
        for (var d = 0; d < 7; d++) {
            dayEventColumns.push(layoutOverlapping(dayColumns[d]));
        }

        for (var h = 0; h < 24; h++) {
            html += '<div class="week-row">';
            html += '<div class="time-gutter">' + (h < 10 ? '0' : '') + h + ':00</div>';
            for (var d = 0; d < 7; d++) {
                html += '<div class="week-cell">';
                dayEventColumns[d].forEach(function(item) {
                    var s = new Date(item.ev.start);
                    var travelMin = parseTravelMinutes(item.ev.travel_mode);
                    var travelStartH = travelMin ? s.getHours() - (travelMin / 60) : -1;
                    var travelHour = travelMin ? Math.floor((s.getTime() - travelMin * 60000) / 3600000 % 24) : -1;

                    // Travel time block (rendered at the hour the travel starts)
                    if (travelMin && Math.floor(travelStartH) === h) {
                        var travelStart = new Date(s.getTime() - travelMin * 60000);
                        var travelOffsetMin = travelStart.getMinutes();
                        var travelTopPx = Math.round(travelOffsetMin * 40 / 60);
                        var travelHeightPx = Math.round(travelMin * 40 / 60);
                        var widthPct = Math.floor(100 / item.totalCols);
                        var leftPct = item.col * widthPct;
                        html += '<div class="week-travel-block" style="height:' + travelHeightPx + 'px;'
                            + 'top:' + travelTopPx + 'px;left:' + leftPct + '%;width:' + widthPct + '%;'
                            + '" aria-label="' + travelMin + ' Minuten Wegzeit">'
                            + travelMin + ' Min. Wegzeit'
                            + '</div>';
                    }

                    if (s.getHours() === h) {
                        var e = new Date(item.ev.end || item.ev.start);
                        var durationH = Math.max((e - s) / 3600000, 0.5);
                        var heightPx = Math.round(durationH * 40);
                        var topPx = Math.round(s.getMinutes() * 40 / 60);
                        var color = getCalendarColor(item.ev.calendarId);
                        var widthPct = Math.floor(100 / item.totalCols);
                        var leftPct = item.col * widthPct;
                        html += '<div class="week-event-inline" style="height:' + heightPx + 'px;'
                            + 'top:' + topPx + 'px;'
                            + 'left:' + leftPct + '%;width:' + widthPct + '%;'
                            + 'background:' + color + '30;border-left:3px solid ' + color + '" '
                            + 'data-url="' + item.ev.url + '" tabindex="0" role="button">'
                            + caldav_suite.formatTime(item.ev.start) + ' ' + rcmail.quote_html(item.ev.summary)
                            + '</div>';
                    }
                });
                html += '</div>';
            }
            html += '</div>';
        }
        html += '</div></div>';

        container.html(html);
        container.find('.week-event-inline, .week-allday-event').click(function() {
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
        var timedEvents = dayEvents.filter(function(e) { return !e.allDay; });
        var allDayEvents = dayEvents.filter(function(e) { return e.allDay; });

        var html = '<div class="day-view" role="grid" aria-label="Day view">';

        // All-day events
        if (allDayEvents.length) {
            html += '<div class="day-allday">';
            allDayEvents.forEach(function(ev) {
                var color = getCalendarColor(ev.calendarId);
                html += '<div class="day-allday-event" style="background:' + color + '30;border-left:3px solid ' + color + '" '
                    + 'data-url="' + ev.url + '" tabindex="0" role="button">'
                    + rcmail.quote_html(ev.summary) + '</div>';
            });
            html += '</div>';
        }

        // Time grid with inline events (column layout for overlaps)
        var dayLayout = layoutOverlapping(timedEvents);
        html += '<div class="day-body">';
        for (var h = 0; h < 24; h++) {
            html += '<div class="day-row">';
            html += '<div class="time-gutter">' + (h < 10 ? '0' : '') + h + ':00</div>';
            html += '<div class="day-cell">';
            dayLayout.forEach(function(item) {
                var s = new Date(item.ev.start);
                var travelMin = parseTravelMinutes(item.ev.travel_mode);
                var travelStartH = travelMin ? s.getHours() - (travelMin / 60) : -1;

                if (travelMin && Math.floor(travelStartH) === h) {
                    var travelStart = new Date(s.getTime() - travelMin * 60000);
                    var travelOffsetMin = travelStart.getMinutes();
                    var travelTopPx = Math.round(travelOffsetMin * 40 / 60);
                    var travelHeightPx = Math.round(travelMin * 40 / 60);
                    var widthPct = Math.floor(100 / item.totalCols);
                    var leftPct = item.col * widthPct;
                    html += '<div class="week-travel-block" style="height:' + travelHeightPx + 'px;'
                        + 'top:' + travelTopPx + 'px;left:' + leftPct + '%;width:' + widthPct + '%;'
                        + '" aria-label="' + travelMin + ' Minuten Wegzeit">'
                        + travelMin + ' Min. Wegzeit'
                        + '</div>';
                }

                if (s.getHours() === h) {
                    var e = new Date(item.ev.end || item.ev.start);
                    var durationH = Math.max((e - s) / 3600000, 0.5);
                    var heightPx = Math.round(durationH * 40);
                    var topPx = Math.round(s.getMinutes() * 40 / 60);
                    var color = getCalendarColor(item.ev.calendarId);
                    var widthPct = Math.floor(100 / item.totalCols);
                    var leftPct = item.col * widthPct;
                    html += '<div class="day-event-inline" style="height:' + heightPx + 'px;'
                        + 'top:' + topPx + 'px;'
                        + 'left:' + leftPct + '%;width:' + widthPct + '%;'
                        + 'background:' + color + '30;border-left:3px solid ' + color + '" '
                        + 'data-url="' + item.ev.url + '" tabindex="0" role="button">'
                        + caldav_suite.formatTime(item.ev.start) + ' – ' + caldav_suite.formatTime(item.ev.end) + ' '
                        + rcmail.quote_html(item.ev.summary)
                        + (item.ev.location ? ' (' + rcmail.quote_html(item.ev.location) + ')' : '')
                        + '</div>';
                }
            });
            html += '</div></div>';
        }
        html += '</div></div>';

        container.html(html);

        container.find('.day-event-inline, .day-allday-event').click(function() {
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

        var openEv = function(url) {
            var ev = state.events.find(function(e) { return e.url === url; });
            if (ev) caldav_event_dialog.open(ev, state.calendars);
        };

        var html = '<div class="list-view">';
        var lastDate = '';

        events.forEach(function(ev) {
            var dateStr = ev.start ? ev.start.substr(0, 10) : '';
            if (dateStr !== lastDate) {
                // Datum-Trenner nur visuell -- das Datum steckt im aria-label jedes Items.
                html += '<div class="list-date" role="presentation">' + caldav_suite.formatDateLong(ev.start) + '</div>';
                lastDate = dateStr;
            }

            var color = getCalendarColor(ev.calendarId);
            var calName = getCalendarName(ev.calendarId);
            var time = ev.allDay ? 'Ganztägig' : caldav_suite.formatTime(ev.start) + ' – ' + caldav_suite.formatTime(ev.end);
            var travelHtml = '', travelLbl = '';
            if (ev.travel_mode) {
                travelLbl = ev.travel_mode === 'auto' ? 'Fahrzeit automatisch' : 'Fahrzeit ' + ev.travel_mode + ' Minuten';
                travelHtml = '<span class="event-travel" aria-hidden="true">🚗 ' + (ev.travel_mode === 'auto' ? 'Auto' : ev.travel_mode + ' min') + '</span>';
            }
            // aria-label: Datum, Zeit, Titel, Ort, KALENDER, Fahrzeit -> NVDA liest alles am Item.
            var aria = [caldav_suite.formatDateLong(ev.start), time, ev.summary, ev.location || '',
                        (calName ? 'Kalender ' + calName : ''), travelLbl]
                .filter(function(s) { return s; }).join(', ');
            // role/tabindex setzt makeListNavigable; Inhalt aria-hidden -> NVDA liest nur das aria-label
            html += '<div class="list-event" data-url="' + ev.url + '" aria-label="' + rcmail.quote_html(aria) + '">'
                + '<span class="event-color-dot" style="background:' + color + '" aria-hidden="true"></span>'
                + '<span class="event-time" aria-hidden="true">' + time + '</span>'
                + '<span class="event-summary" aria-hidden="true">' + rcmail.quote_html(ev.summary) + '</span>'
                + (ev.location ? '<span class="event-location" aria-hidden="true">' + rcmail.quote_html(ev.location) + '</span>' : '')
                + travelHtml
                + '</div>';
        });
        html += '</div>';

        container.html(html);
        container.find('.list-event').click(function() { openEv($(this).data('url')); });
        caldav_a11y.makeListNavigable(container.find('.list-view')[0], {
            itemSelector: '.list-event',
            label: 'Terminliste',
            onActivate: function(item) { openEv(item.getAttribute('data-url')); }
        });
    }

    // ---- Helpers ----

    /**
     * Assign columns to overlapping events so they render side by side.
     * Returns array of {ev, col, totalCols} objects.
     */
    function layoutOverlapping(events) {
        if (!events.length) return [];

        // Sort by start time
        var sorted = events.slice().sort(function(a, b) {
            return new Date(a.start).getTime() - new Date(b.start).getTime();
        });

        var columns = []; // array of arrays, each column has events
        var results = [];

        sorted.forEach(function(ev) {
            var evStart = new Date(ev.start).getTime();
            var placed = false;

            // Try to place in existing column (first one where it doesn't overlap)
            for (var c = 0; c < columns.length; c++) {
                var lastInCol = columns[c][columns[c].length - 1];
                var lastEnd = new Date(lastInCol.end || lastInCol.start).getTime();
                if (evStart >= lastEnd) {
                    columns[c].push(ev);
                    placed = true;
                    results.push({ ev: ev, col: c, totalCols: 0 });
                    break;
                }
            }

            if (!placed) {
                columns.push([ev]);
                results.push({ ev: ev, col: columns.length - 1, totalCols: 0 });
            }
        });

        // Set totalCols for each event based on the max columns needed in its time range
        results.forEach(function(item) {
            item.totalCols = columns.length;
        });

        // Optimize: compute actual overlap groups for tighter columns
        // For now, use total columns count which is safe
        return results;
    }

    function getEventsForDate(events, date) {
        var dayStr = date.getFullYear() + '-' + pad2(date.getMonth() + 1) + '-' + pad2(date.getDate());
        var dayStart = new Date(date.getFullYear(), date.getMonth(), date.getDate()).getTime();
        var dayEnd = dayStart + 86400000;

        return events.filter(function(ev) {
            if (ev.allDay) {
                // All-day: dates come as "YYYY-MM-DD" strings, DTEND is exclusive
                // e.g. start=2026-06-10, end=2026-06-11 means only June 10
                var startStr = (ev.start || '').substr(0, 10);
                var endStr = (ev.end || ev.start || '').substr(0, 10);
                return dayStr >= startStr && dayStr < endStr;
            }
            var evStart = new Date(ev.start).getTime();
            var evEnd = new Date(ev.end || ev.start).getTime();
            return evStart < dayEnd && evEnd > dayStart;
        });
    }

    function parseTravelMinutes(mode) {
        if (!mode) return 0;
        if (mode === 'auto') return 0;
        var n = parseInt(mode, 10);
        return isNaN(n) ? 0 : n;
    }

    function pad2(n) { return n < 10 ? '0' + n : '' + n; }
})();
