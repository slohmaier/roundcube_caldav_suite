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
        loading: false,
        // Infinite-scroll window of the list view (loaded period)
        listStart: null,
        listEnd: null,
        listLoadingMore: false,
        listPendingAppend: false,   // on the next response: APPEND events
        listPendingPrepend: false,   // on the next response: PREPEND events
        listScrollBound: false,
        listScrolledToToday: false
    };

    rcmail.addEventListener('init', function() {
        if (rcmail.task !== 'calendar') return;
        if (!rcmail.env.caldav_configured) {
            $('#calendar-grid').html('<p class="hint">' + caldav_suite.label('no_caldav_configured') + '</p>');
            return;
        }

        state.currentView = rcmail.env.caldav_default_view || 'month';

        // Button handlers
        $('#btn-new-event').click(function() { caldav_event_dialog.open(null, state.calendars); });
        $('#btn-prev').click(function() { navigate(-1); });
        $('#btn-next').click(function() { navigate(1); });
        $('#btn-today').click(function() { state.currentDate = new Date(); loadAndRender(); });
        $('#btn-range').click(openRangePicker);

        $('.view-btn').click(function() {
            switchView($(this).data('view'));
        });

        // Keyboard shortcuts
        $(document).on('keydown', function(e) {
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
            if (e.key === 'ArrowLeft') { navigate(-1); e.preventDefault(); }
            if (e.key === 'ArrowRight') { navigate(1); e.preventDefault(); }
            if (e.key === 'k' || e.key === 'K') { navigate(-1); e.preventDefault(); } // previous week/period
            if (e.key === 'j' || e.key === 'J') { navigate(1); e.preventDefault(); }  // next week/period
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
                state.visibleCalendars = {};
                data.calendars.forEach(function(cal) { state.visibleCalendars[cal.id] = cal.visible !== false; });
                renderCalendarList();
                loadEvents();
            }
        });

        rcmail.addEventListener('plugin.caldav-events-response', function(data) {
            state.loading = false;
            caldav_suite.hideLoading('calendar');
            if (!data.events) { state.listLoadingMore = false; return; }

            if (state.currentView === 'list' && state.listPendingAppend) {
                // Append newer events at the bottom
                state.listPendingAppend = false;
                state.listLoadingMore = false;
                state.events = state.events.concat(data.events);
                renderCurrentView(true); // re-render only the list, keep scroll position at the bottom
            } else if (state.currentView === 'list' && state.listPendingPrepend) {
                // Prepend older events at the top
                state.listPendingPrepend = false;
                state.listLoadingMore = false;
                state.events = data.events.concat(state.events);
                renderCurrentView(true);
            } else {
                state.listLoadingMore = false;
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
        caldav_suite.showLoading('calendar', '#calendar-grid');
    });

    function switchView(view) {
        state.currentView = view;
        state.listScrollBound = false;
        state.listPendingAppend = false;
        state.listPendingPrepend = false;
        state.listScrolledToToday = false;
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
            case 'list': d = new Date(d.getTime() + direction * 7 * 86400000); break; // week by week
        }
        state.currentDate = d;
        loadAndRender();
    }

    function loadAndRender() {
        state.pendingAnnounce = true;   // announce range + count after loading
        // On every date change (today/picker/arrows) in the list view, jump back
        // to the current date.
        state.listScrolledToToday = false;
        updateTitle();
        loadEvents();
    }

    // First day of week from the settings: 0 = Sunday, 1 = Monday (default Monday).
    // Robust against string/0 (the earlier "|| 1" wrongly bent Sunday to Monday).
    function getFirstDay() {
        var fd = parseInt(rcmail.env.caldav_first_day, 10);
        return (fd === 0 || fd === 1) ? fd : 1;
    }

    // Readable week range, e.g. "Jun 15 – 21, 2026" or "Jun 29 – Jul 5, 2026".
    function weekRangeText(start, end) {
        var sd = start.getDate(), ed = end.getDate();
        var sm = caldav_suite.monthLong(start.getMonth()), em = caldav_suite.monthLong(end.getMonth()), ey = end.getFullYear();
        if (start.getMonth() === end.getMonth() && start.getFullYear() === ey) {
            return sm + ' ' + sd + ' – ' + ed + ', ' + ey;
        }
        return sm + ' ' + sd + ' – ' + em + ' ' + ed + ', ' + ey;
    }

    function openRangePicker() {
        var d = state.currentDate;
        var val = d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
        var html = '<div class="propform">'
            + '<label for="rangepick-date">' + rcmail.quote_html(caldav_suite.label('pick_week_hint')) + '</label>'
            + '<input type="date" id="rangepick-date" class="form-control" value="' + val + '" />'
            + '</div>';
        caldav_suite.dialog(caldav_suite.label('pick_period'), html, [
            { label: caldav_suite.label('jump'), action: function(dlg) {
                var v = dlg.find('#rangepick-date').val();
                if (v) { state.currentDate = new Date(v + 'T12:00:00'); loadAndRender(); }
            } },
            { label: caldav_suite.label('cancel'), close: true }
        ]);
        setTimeout(function() { $('#rangepick-date').focus(); }, 50);
    }

    function loadEvents() {
        var range = getViewRange();
        state.loading = true;
        // Show the overlay only on a regular load (not on infinite-scroll append).
        if (!state.listLoadingMore) caldav_suite.showLoading('calendar', '#calendar-grid');
        // List view: remember the window (for infinite scrolling)
        if (state.currentView === 'list') {
            state.listStart = range.start;
            state.listEnd = range.end;
            state.listScrollBound = false;
            state.listPendingAppend = false;
            state.listPendingPrepend = false;
        }
        var visibleIds = Object.keys(state.visibleCalendars).filter(function(id) { return state.visibleCalendars[id]; });
        rcmail.http_post('plugin.caldav-events', {
            _start: range.start.toISOString(),
            _end: range.end.toISOString(),
            _calendars: visibleIds
        });
    }

    // Loads another period block for the list view (infinite scroll).
    // direction: -1 = older events on top, +1 = newer events at the bottom.
    function loadListMore(direction) {
        if (state.listLoadingMore || state.loading) return;
        if (!state.listStart || !state.listEnd) return;
        state.listLoadingMore = true;

        var blockDays = 120; // ~4 Monate
        var newStart, newEnd;
        if (direction < 0) {
            newEnd = new Date(state.listStart.getTime());
            newStart = new Date(newEnd.getTime() - blockDays * 86400000);
            state.listPendingPrepend = true;
        } else {
            newStart = new Date(state.listEnd.getTime());
            newEnd = new Date(newStart.getTime() + blockDays * 86400000);
            state.listPendingAppend = true;
        }

        var visibleIds = Object.keys(state.visibleCalendars).filter(function(id) { return state.visibleCalendars[id]; });
        rcmail.http_post('plugin.caldav-events', {
            _start: newStart.toISOString(),
            _end: newEnd.toISOString(),
            _calendars: visibleIds
        });
        // Extend the window so the next load appends correctly
        state.listStart = direction < 0 ? newStart : state.listStart;
        state.listEnd = direction > 0 ? newEnd : state.listEnd;
    }

    function getViewRange() {
        var d = state.currentDate;
        var start, end;
        switch (state.currentView) {
            case 'month':
                start = new Date(d.getFullYear(), d.getMonth(), 1);
                end = new Date(d.getFullYear(), d.getMonth() + 1, 0, 23, 59, 59);
                // Extend to full weeks
                var firstDay = getFirstDay();
                while (start.getDay() !== firstDay) start.setDate(start.getDate() - 1);
                while (end.getDay() !== (firstDay + 6) % 7) end.setDate(end.getDate() + 1);
                break;
            case 'week':
                start = new Date(d);
                var dow = start.getDay();
                var firstDay = getFirstDay();
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
                // List view: initial window around "today" (1 month back, 1 month forward).
                // Further blocks are loaded via infinite scrolling.
                start = new Date(d.getFullYear(), d.getMonth() - 1, 1);
                start.setHours(0, 0, 0, 0);
                end = new Date(d.getFullYear(), d.getMonth() + 1, 1);
                end.setHours(23, 59, 59);
                break;
        }
        return { start: start, end: end };
    }

    // Checks whether the currently displayed time range contains today's date.
    // Returns true, then updateTitle appends the "(current)" marker.
    function isCurrentRange() {
        var now = new Date();
        var range = getViewRange();
        var s = new Date(range.start.getFullYear(), range.start.getMonth(), range.start.getDate());
        var e = new Date(range.end.getFullYear(), range.end.getMonth(), range.end.getDate());
        var t = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        return s.getTime() <= t.getTime() && t.getTime() <= e.getTime();
    }

    function updateTitle() {
        var d = state.currentDate;
        var title = '';
        switch (state.currentView) {
            case 'month':
                title = caldav_suite.monthLong(d.getMonth()) + ' ' + d.getFullYear();
                break;
            case 'week':
            case 'list':
                var range = getViewRange();
                title = weekRangeText(range.start, range.end);
                break;
            case 'day':
                title = caldav_suite.formatDateLong(d.toISOString());
                break;
        }
        if (isCurrentRange()) title += ' (' + caldav_suite.label('current_range') + ')';
        $('#btn-range').text(title);
    }

    function renderCurrentView(keepScroll) {
        updateTitle();
        var container = $('#calendar-grid');
        // Remember the scroll position before the re-render (for infinite scrolling)
        var scroller = container.closest('.scroller')[0];
        var prevScrollTop = scroller ? scroller.scrollTop : 0;
        switch (state.currentView) {
            case 'month': renderMonth(container); break;
            case 'week':  renderWeek(container);  break;
            case 'day':   renderDay(container);   break;
            case 'list':  renderList(container, keepScroll);  break;
        }
        if (keepScroll && scroller) {
            scroller.scrollTop = prevScrollTop;
        }
        // Announce only on range change (navigate/today/pick-week/switch-view),
        // not when merely showing/hiding a calendar.
        if (state.pendingAnnounce) {
            state.pendingAnnounce = false;
            var cnt = getVisibleEvents().length;
            caldav_suite.announce($('#btn-range').text() + ', ' + cnt + ' ' + caldav_suite.label('events'));
        }
    }

    // ---- Calendar Sidebar ----

    function renderCalendarList() {
        var calAria = function(cal, on) {
            return cal.name + ', ' + (on ? caldav_suite.label('shown') : caldav_suite.label('hidden'));
        };
        // Navigable list like the task-list sidebar: role=option items, NO
        // focusable children (no <input>). Status via .checked class + aria-label.
        var html = '<ul id="calendar-ul" class="listing navlist">';
        state.calendars.forEach(function(cal) {
            var on = !!state.visibleCalendars[cal.id];
            html += '<li class="calendar-item' + (on ? ' checked' : '') + '" data-cal-id="' + cal.id + '"'
                + ' style="border-left:4px solid ' + cal.color + '"'
                + ' aria-label="' + rcmail.quote_html(calAria(cal, on)) + '">'
                + '<button type="button" class="calendar-check" aria-hidden="true" tabindex="-1"></button>'
                + '<span class="cal-name" aria-hidden="true">' + rcmail.quote_html(cal.name) + '</span>'
                + '</li>';
        });
        html += '</ul>';
        $('#calendar-list').html(html);

        var saveVisible = function() {
            var ids = Object.keys(state.visibleCalendars).filter(function(id) { return state.visibleCalendars[id]; });
            rcmail.http_post('plugin.caldav-set-visible', { _visible: ids });
        };
        var setCal = function(item, on) {
            if (!item) return;
            var id = item.getAttribute('data-cal-id');
            if (state.visibleCalendars[id] === on) return;
            state.visibleCalendars[id] = on;
            item.classList.toggle('checked', on);
            var cal = state.calendars.find(function(c) { return String(c.id) === String(id); });
            if (cal) item.setAttribute('aria-label', calAria(cal, on));
            saveVisible();
            renderCurrentView();
        };
        var toggleCal = function(item) {
            if (!item) return;
            setCal(item, !item.classList.contains('checked'));
        };

        // Only a click on the checkbox toggles; a click on the row/name does nothing.
        $('#calendar-list .calendar-check').click(function(e) {
            e.stopPropagation();
            toggleCal($(this).closest('.calendar-item')[0]);
        });
        $('#calendar-list .calendar-item').click(function() {
            // intentionally nothing (no toggle anymore), only navigation/selection via a11y
        });

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

        var html = '<table class="calendar-month" role="grid" aria-label="' + caldav_suite.monthLong(d.getMonth()) + ' ' + d.getFullYear() + '">';
        html += '<thead><tr>';
        var firstDay = getFirstDay();
        for (var i = 0; i < 7; i++) {
            var dayIdx = (firstDay + i) % 7;
            html += '<th scope="col">' + caldav_suite.weekdayShort(dayIdx) + '</th>';
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
        // Simple click selects, double-click opens edit, Enter opens edit.
        container.find('.month-event').click(function() {
            var url = $(this).data('url');
            container.find('.month-event').removeClass('selected');
            $(this).addClass('selected');
            state.selectedEventUrl = url;
        });
        container.find('.month-event').on('dblclick', function() {
            var url = $(this).data('url');
            var ev = state.events.find(function(e) { return e.url === url; });
            if (ev) caldav_event_dialog.open(ev, state.calendars);
        });
        container.find('.month-event').on('keydown', function(e) {
            if (e.key === 'Enter') {
                var url = $(this).data('url');
                var ev = state.events.find(function(e) { return e.url === url; });
                if (ev) caldav_event_dialog.open(ev, state.calendars);
            }
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
                + caldav_suite.weekdayShort(cur.getDay()) + ' ' + cur.getDate() + ' ' + caldav_suite.monthShort(cur.getMonth())
                + '</div>';
            cur.setDate(cur.getDate() + 1);
        }
        html += '</div>';

        // All-day events row
        var hasAllDay = false;
        cur = new Date(range.start);
        var allDayHtml = '<div class="week-allday"><div class="time-gutter">' + caldav_suite.label('allday') + '</div>';
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
                            + '" aria-label="' + travelMin + ' ' + caldav_suite.label('travel_time') + '">'
                            + travelMin + ' ' + caldav_suite.label('travel_time')
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
        // Simple click selects, double-click opens edit, Enter opens edit.
        container.find('.week-event-inline, .week-allday-event').click(function() {
            var url = $(this).data('url');
            container.find('.week-event-inline, .week-allday-event').removeClass('selected');
            $(this).addClass('selected');
            state.selectedEventUrl = url;
        });
        container.find('.week-event-inline, .week-allday-event').on('dblclick', function() {
            var url = $(this).data('url');
            var ev = state.events.find(function(e) { return e.url === url; });
            if (ev) caldav_event_dialog.open(ev, state.calendars);
        });
        container.find('.week-event-inline, .week-allday-event').on('keydown', function(e) {
            if (e.key === 'Enter') {
                var url = $(this).data('url');
                var ev = state.events.find(function(e) { return e.url === url; });
                if (ev) caldav_event_dialog.open(ev, state.calendars);
            }
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
                        + '" aria-label="' + travelMin + ' ' + caldav_suite.label('travel_time') + '">'
                        + travelMin + ' ' + caldav_suite.label('travel_time')
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

        // Simple click selects, double-click opens edit, Enter opens edit.
        container.find('.day-event-inline, .day-allday-event').click(function() {
            var url = $(this).data('url');
            container.find('.day-event-inline, .day-allday-event').removeClass('selected');
            $(this).addClass('selected');
            state.selectedEventUrl = url;
        });
        container.find('.day-event-inline, .day-allday-event').on('dblclick', function() {
            var url = $(this).data('url');
            var ev = state.events.find(function(e) { return e.url === url; });
            if (ev) caldav_event_dialog.open(ev, state.calendars);
        });
        container.find('.day-event-inline, .day-allday-event').on('keydown', function(e) {
            if (e.key === 'Enter') {
                var url = $(this).data('url');
                var ev = state.events.find(function(e) { return e.url === url; });
                if (ev) caldav_event_dialog.open(ev, state.calendars);
            }
        });
    }

    // ---- List/Agenda View (accessible) ----

    function renderList(container, keepScroll) {
        var events = getVisibleEvents();
        events.sort(function(a, b) { return (a.start || '') < (b.start || '') ? -1 : 1; });

        // Range header (focusable heading): makes the displayed week + count clear.
        var cnt = events.length;
        var header = '<h3 class="list-range" tabindex="-1">' + caldav_suite.label('week') + ' ' + rcmail.quote_html($('#btn-range').text())
            + ' — ' + cnt + ' ' + caldav_suite.label('events') + '</h3>';

        if (events.length === 0) {
            container.html(header + '<p class="hint">' + caldav_suite.label('no_events') + '</p>');
            return;
        }

        var selectedUrls = {};

        var openEv = function(url) {
            var ev = state.events.find(function(e) { return e.url === url; });
            if (ev) caldav_event_dialog.open(ev, state.calendars);
        };
        // New event on a specific day (data-date, YYYY-MM-DD).
        var newEvOn = function(dateStr) {
            if (!dateStr) return;
            caldav_event_dialog.open({ start: dateStr + 'T09:00', end: dateStr + 'T10:00' }, state.calendars);
        };
        // toggle a single item (multi: Ctrl+click / space); add==true forces selecting
        var toggleOne = function(item, add) {
            var url = item.getAttribute('data-url');
            if (url === null) return;
            var on;
            if (add === true) { selectedUrls[url] = true; on = true; }
            else if (selectedUrls[url]) { delete selectedUrls[url]; on = false; }
            else { selectedUrls[url] = true; on = true; }
            item.classList.toggle('selected', on);
        };
        var toggleSelection = function(item) { toggleOne(item); };

        // Set selection: only this item (the rest is deselected)
        var setSelection = function(item) {
            if (!item) return;
            selectedUrls = {};
            toggleOne(item, true);
        };

        var html = header + '<ul class="listing">';
        // Group by date: one navigable day item per day, the events below it.
        var byDate = {};
        events.forEach(function(ev) {
            var d = ev.start ? ev.start.substr(0, 10) : '';
            if (!byDate[d]) byDate[d] = [];
            byDate[d].push(ev);
        });
        var dates = Object.keys(byDate).sort();

        dates.forEach(function(dateStr) {
            // Day header as its own navigable item (focusable, announces the date).
            html += '<li class="list-day" role="option" data-date="' + dateStr + '" aria-label="' + caldav_suite.formatDateLong(dateStr) + '">'
                + '<span class="list-day-label" aria-hidden="true">' + caldav_suite.formatDateLong(dateStr) + '</span>'
                + '</li>';

            byDate[dateStr].forEach(function(ev) {
                var color = getCalendarColor(ev.calendarId);
                var calName = getCalendarName(ev.calendarId);
                var time = ev.allDay ? caldav_suite.label('allday') : caldav_suite.formatTime(ev.start) + ' – ' + caldav_suite.formatTime(ev.end);
                var travelHtml = '', travelLbl = '';
                if (ev.travel_mode) {
                    travelLbl = caldav_suite.label('travel_time') + ' ' + (ev.travel_mode === 'auto' ? caldav_suite.label('travel_auto') : ev.travel_mode + ' min');
                    travelHtml = '<span class="event-travel" aria-hidden="true">🚗 ' + (ev.travel_mode === 'auto' ? caldav_suite.label('travel_auto') : ev.travel_mode + ' min') + '</span>';
                }
                var aria = [time, ev.summary, ev.location || '', (calName ? caldav_suite.label('calendar') + ' ' + calName : ''), travelLbl]
                    .filter(function(s) { return s; }).join(', ');
                var evDate = ev.start ? ev.start.substr(0, 10) : '';
                html += '<li class="list-event" data-url="' + ev.url + '" data-date="' + evDate + '" aria-label="' + rcmail.quote_html(aria) + '">'
                    + '<span class="event-color-dot" style="background:' + color + '" aria-hidden="true"></span>'
                    + '<span class="event-time" aria-hidden="true">' + time + '</span>'
                    + '<span class="event-summary" aria-hidden="true">' + rcmail.quote_html(ev.summary) + '</span>'
                    + (ev.location ? '<span class="event-location" aria-hidden="true">' + rcmail.quote_html(ev.location) + '</span>' : '')
                    + travelHtml
                    + '<span class="event-edit" aria-hidden="true">&#9998;</span>'
                    + '</li>';
            });
        });
        html += '</ul>';

        container.html(html);

        // Edit icon opens the edit dialog directly
        container.find('.event-edit').click(function(e) {
            e.stopPropagation();
            openEv($(this).closest('.list-event').attr('data-url'));
        });

        // Click: Ctrl+click toggles (multi), a normal click selects a single item,
        // double-click opens edit.
        container.find('.list-event').on('click', function(e) {
            if (e.ctrlKey || e.metaKey) { toggleSelection($(this)); }
            else { setSelection($(this)); }
        });
        container.find('.list-event').on('dblclick', function(e) {
            openEv($(this).attr('data-url'));
        });
        // Double-click on a day -> new event on that day.
        container.find('.list-day').on('dblclick', function(e) {
            newEvOn($(this).attr('data-date'));
        });

        caldav_a11y.makeListNavigable(container.find('.listing')[0], {
            itemSelector: '.list-event, .list-day',
            label: caldav_suite.label('event_list'),
            // When tabbing back in, jump to the current date instead of the oldest event.
            initialIndex: function(items) {
                var target = state.currentDate;
                var ts = target.getFullYear() + '-' + ('0'+(target.getMonth()+1)).slice(-2) + '-' + ('0'+target.getDate()).slice(-2);
                // Prefer the day item of the current date, otherwise the first >= today.
                var first = -1;
                for (var i = 0; i < items.length; i++) {
                    var d = items[i].getAttribute('data-date');
                    if (!d) continue;
                    if (first < 0 && d >= ts) first = i;
                    if (d === ts && items[i].classList.contains('list-day')) return i;
                }
                return first < 0 ? 0 : first;
            },
            // Explicit announcement on arrowing (NVDA does not always announce
            // aria-activedescendant reliably when arrowing).
            onChange: function(item) {
                var lbl = item.getAttribute('aria-label') || item.textContent || '';
                caldav_suite.announce(lbl);
            },
            onActivate: function(item) { // Enter -> edit event, or for a day: new event
                var u = item.getAttribute('data-url');
                if (u) openEv(u);
                else newEvOn(item.getAttribute('data-date'));
            },
            onToggle: function(item) { // Space -> toggle multiselect (events only)
                if (item.getAttribute('data-url') !== null) toggleSelection($(item));
            }
        });

        // Infinite scrolling: load a new block at the bottom/top edge.
        var scroller = container.closest('.scroller')[0];
        if (scroller && !state.listScrollBound) {
            state.listScrollBound = true;
            $(scroller).on('scroll', function() {
                var el = scroller;
                var nearBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 150;
                var nearTop = el.scrollTop < 150;
                if (nearBottom) loadListMore(1);
                else if (nearTop) loadListMore(-1);
            });
        }

        // After the first load (not on infinite-scroll append), jump to the current
        // date (today or picked), instead of starting at the top with the oldest
        // events.
        if (scroller && !state.listScrolledToToday) {
            state.listScrolledToToday = true;
            var target = state.currentDate;
            var targetStr = target.getFullYear() + '-' + ('0'+(target.getMonth()+1)).slice(-2) + '-' + ('0'+target.getDate()).slice(-2);
            var targetEl = null;
            // Find the first event at/after this date
            var items = container.find('.list-event').toArray();
            for (var i = 0; i < items.length; i++) {
                var dstr = items[i].getAttribute('data-date');
                if (dstr && dstr >= targetStr) { targetEl = items[i]; break; }
            }
            if (!targetEl && items.length) targetEl = items[0];
            if (targetEl) {
                var top = targetEl.offsetTop - scroller.offsetTop - 20;
                scroller.scrollTop = Math.max(0, top);
            }
        }
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
