/**
 * CalDAV Suite - Tasks View
 */

(function() {
    if (!window.rcmail) return;

    var state = {
        taskLists: [],
        visibleLists: {},
        tasks: [],
        sortBy: 'due',
        filter: 'open'   // 'open' | 'completed' | 'all'
    };

    // After a toggle (reload), set focus back onto the same task.
    // If it dropped out of the list (completed + "show completed" off), move focus to
    // the neighbor so it does not fall to <body> and NVDA stays in focus mode.
    var pendingFocusUrl = null;
    var pendingFocusFallback = null;

    rcmail.addEventListener('init', function() {
        if (rcmail.task !== 'tasks') return;
        if (!rcmail.env.caldav_configured) {
            $('#task-list-container').html('<p class="hint">' + caldav_suite.label('no_caldav_configured') + '</p>');
            return;
        }

        $('#btn-new-task').click(function() { caldav_task_dialog.open(null, state.taskLists); });
        $('#task-filter').change(function() { state.filter = this.value; loadTasks(); });
        $('#task-sort').change(function() { state.sortBy = this.value; renderTasks(); });

        rcmail.addEventListener('plugin.caldav-tasklists-response', function(data) {
            if (data.lists) {
                state.taskLists = data.lists;
                state.visibleLists = {};
                data.lists.forEach(function(l) { state.visibleLists[l.id] = l.visible !== false; });
                renderTaskListSidebar();
                loadTasks();
            }
        });

        rcmail.addEventListener('plugin.caldav-tasks-response', function(data) {
            caldav_suite.hideLoading('tasks');
            if (data.tasks) {
                state.tasks = data.tasks;
                renderTasks();
            }
        });

        rcmail.addEventListener('plugin.caldav-task-saved', function(data) { if (data.success) loadTasks(); });
        rcmail.addEventListener('plugin.caldav-task-deleted', function(data) { if (data.success) loadTasks(); });
        rcmail.addEventListener('plugin.caldav-task-toggled', function(data) {
            // Apply the toggle client-side instead of reloading the whole list (saves ~1.5s).
            if (!data.success || !data.url) return;
            var task = state.tasks.find(function(t) { return t.url === data.url; });
            if (!task) { loadTasks(); return; }
            task.completed = !!data.completed;
            if (task.completed) { task.status = 'COMPLETED'; task.percent_complete = 100; }
            else { task.status = 'NEEDS-ACTION'; }
            // Keep focus on the toggled task
            pendingFocusUrl = data.url;
            pendingFocusFallback = [];
            renderTasks();
        });

        rcmail.http_post('plugin.caldav-tasklists');
    });

    function loadTasks() {
        caldav_suite.showLoading('tasks', '#task-list-container');
        // Only with "Open" may completed tasks be dropped server-side; otherwise fetch
        // all and filter client-side (see renderTasks).
        rcmail.http_post('plugin.caldav-tasks', {
            _include_completed: state.filter === 'open' ? '0' : '1'
        });
    }

    function renderTaskListSidebar() {
        var listAria = function(list, on) {
            return list.name + ', ' + (on ? caldav_suite.label('shown') : caldav_suite.label('hidden'));
        };
        var html = '<ul id="tasklist-ul" class="listing navlist">';
        state.taskLists.forEach(function(list) {
            var on = state.visibleLists[list.id] !== false;
            html += '<li class="tasklist-item' + (on ? ' checked' : '') + '" data-list-id="' + list.id + '"'
                + ' style="border-left:4px solid ' + (list.color || '#4fc3f7') + '"'
                + ' aria-label="' + rcmail.quote_html(listAria(list, on)) + '">'
                + '<button type="button" class="tasklist-check" aria-hidden="true" tabindex="-1"></button>'
                + '<span class="tasklist-name" aria-hidden="true">' + rcmail.quote_html(list.name) + '</span>'
                + '</li>';
        });
        html += '</ul>';
        $('#tasklist-list').html(html);

        var saveVisible = function() {
            var ids = Object.keys(state.visibleLists).filter(function(id) { return state.visibleLists[id]; });
            rcmail.http_post('plugin.caldav-set-visible-tasklists', { _visible: ids });
        };
        var toggleList = function(item) {
            if (!item) return;
            var id = item.getAttribute('data-list-id');
            var on = !item.classList.contains('checked');
            state.visibleLists[id] = on;
            item.classList.toggle('checked', on);
            var list = state.taskLists.find(function(l) { return String(l.id) === String(id); });
            if (list) item.setAttribute('aria-label', listAria(list, on));
            saveVisible();
            renderTasks();
        };

        // Only a click on the checkbox toggles; a click on the row/name does nothing.
        $('#tasklist-list .tasklist-check').click(function(e) {
            e.stopPropagation();
            toggleList($(this).closest('.tasklist-item')[0]);
        });
        $('#tasklist-list .tasklist-item').click(function() {
            // intentionally nothing (no toggle anymore)
        });

        // Same arrow navigation as the main list: up/down between lists,
        // Space/Enter shows/hides the list. NO focusable child elements
        // (status via .checked class + aria-label).
        caldav_a11y.makeListNavigable(document.getElementById('tasklist-ul'), {
            itemSelector: '.tasklist-item',
            label: caldav_suite.label('task_lists'),
            onToggle: toggleList,
            onActivate: toggleList
        });
    }

    function renderTasks() {
        var tasks = state.tasks.filter(function(t) {
            if (state.visibleLists[t.listId] === false) return false;
            if (state.filter === 'open') return !t.completed;
            if (state.filter === 'completed') return !!t.completed;
            return true; // 'all'
        });

        // Sort
        tasks.sort(function(a, b) {
            switch (state.sortBy) {
                case 'priority':
                    var pa = a.priority || 99, pb = b.priority || 99;
                    return pa - pb;
                case 'created':
                    return (b.created || '') > (a.created || '') ? 1 : -1;
                case 'due':
                default:
                    var da = a.due || '9999-12-31', db = b.due || '9999-12-31';
                    return da > db ? 1 : -1;
            }
        });

        if (tasks.length === 0) {
            $('#task-list-container').html('<p class="hint" tabindex="-1">' + caldav_suite.label('no_tasks') + '</p>');
            // List empty -> focus the hint instead of losing focus to <body>.
            if (pendingFocusUrl) {
                var hint = document.querySelector('#task-list-container .hint');
                if (hint) hint.focus();
                pendingFocusUrl = null;
                pendingFocusFallback = null;
            }
            return;
        }

        var prioWord = { 'priority-high': caldav_suite.label('priority_high'), 'priority-medium': caldav_suite.label('priority_medium'), 'priority-low': caldav_suite.label('priority_low') };

        var html = '<ul id="task-list" class="listing">';
        tasks.forEach(function(task) {
            var priorityClass = '';
            var priorityLabel = '';
            if (task.priority >= 1 && task.priority <= 3) { priorityClass = 'priority-high'; priorityLabel = '!!!'; }
            else if (task.priority >= 4 && task.priority <= 6) { priorityClass = 'priority-medium'; priorityLabel = '!!'; }
            else if (task.priority >= 7) { priorityClass = 'priority-low'; priorityLabel = '!'; }

            var isOverdue = false;
            var dueIndicator = '';
            var dueDetailHtml = '';
            if (task.due) {
                isOverdue = new Date(task.due) < new Date() && !task.completed;
                // Indicator dot in the list (red when overdue, otherwise neutral).
                dueIndicator = '<span class="task-due-indicator' + (isOverdue ? ' overdue' : '') + '"'
                    + ' aria-hidden="true" title="' + caldav_suite.label('due_date') + ': ' + caldav_suite.formatDate(task.due) + '"></span>';
                // Date as text only in the detail view (when selected).
                dueDetailHtml = '<div class="task-due-detail' + (isOverdue ? ' overdue' : '') + '">'
                    + caldav_suite.label('due_date') + ': ' + caldav_suite.formatDate(task.due)
                    + (isOverdue ? ' (' + caldav_suite.label('overdue') + ')' : '') + '</div>';
            }

            // Full aria-label -> NVDA reads exactly one clear announcement when arrowing.
            var aria = [
                task.summary,
                task.due ? (caldav_suite.label('due_date') + ' ' + caldav_suite.formatDate(task.due) + (isOverdue ? ' ' + caldav_suite.label('overdue') : '')) : '',
                priorityClass ? (caldav_suite.label('priority') + ' ' + prioWord[priorityClass]) : '',
                task.completed ? caldav_suite.label('done') : caldav_suite.label('open')
            ].filter(function(s) { return s; }).join(', ');

            // NO focusable descendants (no <input>/<button>) in the role=option item:
            // otherwise NVDA/browser would route activation/click to the focusable child
            // instead of the option ("only checkbox focused"). Status lives in the aria-label
            // + .completed class; check/edit are purely visual <span>s styled via CSS.
            // Detail line (note/location) only shown when selected.
            var detailHtml = '';
            var extras = [];
            if (task.description) { detailHtml += '<div class="task-desc" aria-hidden="true">' + rcmail.quote_html(task.description) + '</div>'; }
            if (task.location) { detailHtml += '<div class="task-loc" aria-hidden="true">' + rcmail.quote_html(task.location) + '</div>'; }

            html += '<li class="task-item' + (task.completed ? ' completed' : '') + '" data-url="' + task.url + '"'
                + ' data-etag="' + (task.etag || '') + '"'
                + ' aria-label="' + rcmail.quote_html(aria) + '">'
                + '<span class="task-check" aria-hidden="true"></span>'
                + '<span class="task-body">'
                + '<span class="task-title-row">'
                + '<span class="task-summary" aria-hidden="true">' + rcmail.quote_html(task.summary) + '</span>'
                + dueIndicator
                + (priorityLabel ? '<span class="task-priority ' + priorityClass + '" aria-hidden="true">' + priorityLabel + '</span>' : '')
                + '<span class="task-edit" aria-hidden="true">&#9998;</span>'
                + '</span>'
                + ((detailHtml || dueDetailHtml) ? '<span class="task-details">' + dueDetailHtml + detailHtml + '</span>' : '')
                + '</span>'
                + '</li>';
        });
        html += '</ul>';

        $('#task-list-container').html(html);

        var openEdit = function(url) {
            var task = state.tasks.find(function(t) { return t.url === url; });
            if (task) caldav_task_dialog.open(task, state.taskLists);
        };
        // Check off/on via item attributes (no <input> anymore). Status = .completed class.
        var toggleDone = function(item) {
            if (!item) return;
            pendingFocusUrl = item.getAttribute('data-url');
            // Remember neighbors as focus fallback (next preferred, otherwise previous task).
            pendingFocusFallback = [];
            var next = item.nextElementSibling, prev = item.previousElementSibling;
            if (next) pendingFocusFallback.push(next.getAttribute('data-url'));
            if (prev) pendingFocusFallback.push(prev.getAttribute('data-url'));
            var nowCompleted = !item.classList.contains('completed');
            rcmail.http_post('plugin.caldav-task-toggle', {
                _url: item.getAttribute('data-url'),
                _etag: item.getAttribute('data-etag') || '',
                _completed: nowCompleted ? '1' : '0'
            });
        };

        // ---- Selection (like the event list): click selects, actions via the toolbar ----
        var prioWord = { 'priority-high': caldav_suite.label('priority_high'), 'priority-medium': caldav_suite.label('priority_medium'), 'priority-low': caldav_suite.label('priority_low') };

        // Screenreader: read the full details when a task is selected.
        var announceTaskDetails = function(url) {
            var task = state.tasks.find(function(t) { return t.url === url; });
            if (!task) return;
            var parts = [task.summary];
            var due = task.due ? new Date(task.due) : null;
            if (due) {
                var over = due < new Date() && !task.completed;
                parts.push(caldav_suite.label('due_date') + ' ' + caldav_suite.formatDate(task.due) + (over ? ', ' + caldav_suite.label('overdue') : ''));
            }
            if (task.priority >= 1 && task.priority <= 3) parts.push(caldav_suite.label('priority') + ' ' + caldav_suite.label('priority_high'));
            else if (task.priority >= 4 && task.priority <= 6) parts.push(caldav_suite.label('priority') + ' ' + caldav_suite.label('priority_medium'));
            else if (task.priority >= 7) parts.push(caldav_suite.label('priority') + ' ' + caldav_suite.label('priority_low'));
            if (task.completed) parts.push(caldav_suite.label('done'));
            if (task.description) parts.push(caldav_suite.label('note') + ': ' + task.description);
            if (task.location) parts.push(caldav_suite.label('location') + ': ' + task.location);
            caldav_suite.announce(parts.join('. '));
        };

        var setTaskSelection = function(item) {
            if (!item) return;
            selectedTaskUrls = {};
            var url = item.getAttribute('data-url');
            if (url !== null) selectedTaskUrls[url] = true;
            $('#task-list .task-item').removeClass('selected');
            item.addClass('selected');
            announceTaskDetails(url);
        };
        var toggleTaskSelection = function(item) {
            if (!item) return;
            var url = item.getAttribute('data-url');
            if (url === null) return;
            if (selectedTaskUrls[url]) { delete selectedTaskUrls[url]; item.removeClass('selected'); }
            else { selectedTaskUrls[url] = true; item.addClass('selected'); }
        };

        // Mouse: click on check completes it, click on row/summary only selects (no auto-edit).
        $('#task-list .task-check').click(function() { toggleDone($(this).closest('.task-item')[0]); });
        $('#task-list .task-item').click(function(e) {
            if (e.ctrlKey || e.metaKey) { toggleTaskSelection($(this)); }
            else { setTaskSelection($(this)); }
        });

        // Edit icon in the row opens the edit dialog directly (no selection needed).
        $('#task-list .task-edit').click(function(e) {
            e.stopPropagation();
            openEdit($(this).closest('.task-item').attr('data-url'));
        });

        // Uniform, screenreader-navigable list: arrow up/down, Enter = select,
        // Space = check off/on.
        caldav_a11y.makeListNavigable(document.getElementById('task-list'), {
            itemSelector: '.task-item',
            label: caldav_suite.label('tasks'),
            onActivate: function(item) { // Enter -> edit
                openEdit(item.getAttribute('data-url'));
            },
            onToggle: toggleDone
        });

        // Reset focus after a toggle reload.
        if (pendingFocusUrl) {
            var list = document.getElementById('task-list');
            var ok = caldav_a11y.focusItemByAttr(list, 'data-url', pendingFocusUrl);
            // Task dropped out of the list -> try the neighbors.
            if (!ok && pendingFocusFallback) {
                for (var i = 0; i < pendingFocusFallback.length && !ok; i++) {
                    ok = caldav_a11y.focusItemByAttr(list, 'data-url', pendingFocusFallback[i]);
                }
            }
            // Last resort: focus the listbox container (its focus handler sets the
            // first option active) so focus never falls to <body>.
            if (!ok && list) { list.focus(); }
            pendingFocusUrl = null;
            pendingFocusFallback = null;
        }
    }
})();
