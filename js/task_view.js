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
        showCompleted: false
    };

    // Nach einem Toggle (Reload) den Fokus wieder auf dieselbe Aufgabe setzen.
    var pendingFocusUrl = null;

    rcmail.addEventListener('init', function() {
        if (rcmail.task !== 'tasks') return;
        if (!rcmail.env.caldav_configured) {
            $('#task-list-container').html('<p class="hint">' + caldav_suite.label('no_caldav_configured') + '</p>');
            return;
        }

        $('#btn-new-task').click(function() { caldav_task_dialog.open(null, state.taskLists); });
        $('#show-completed').change(function() { state.showCompleted = this.checked; loadTasks(); });
        $('#task-sort').change(function() { state.sortBy = this.value; renderTasks(); });

        rcmail.addEventListener('plugin.caldav-tasklists-response', function(data) {
            if (data.lists) {
                state.taskLists = data.lists;
                data.lists.forEach(function(l) { state.visibleLists[l.id] = true; });
                renderTaskListSidebar();
                loadTasks();
            }
        });

        rcmail.addEventListener('plugin.caldav-tasks-response', function(data) {
            if (data.tasks) {
                state.tasks = data.tasks;
                renderTasks();
            }
        });

        rcmail.addEventListener('plugin.caldav-task-saved', function(data) { if (data.success) loadTasks(); });
        rcmail.addEventListener('plugin.caldav-task-deleted', function(data) { if (data.success) loadTasks(); });
        rcmail.addEventListener('plugin.caldav-task-toggled', function(data) { if (data.success) loadTasks(); });

        rcmail.http_post('plugin.caldav-tasklists');
    });

    function loadTasks() {
        rcmail.http_post('plugin.caldav-tasks', {
            _include_completed: state.showCompleted ? '1' : '0'
        });
    }

    function renderTaskListSidebar() {
        var html = '<ul class="tasklist-list" role="list">';
        state.taskLists.forEach(function(list) {
            html += '<li><label class="tasklist-item">'
                + '<input type="checkbox" checked data-list-id="' + list.id + '" />'
                + '<span>' + rcmail.quote_html(list.name) + '</span>'
                + '</label></li>';
        });
        html += '</ul>';
        $('#tasklist-list').html(html);

        $('#tasklist-list input').change(function() {
            state.visibleLists[$(this).data('list-id')] = this.checked;
            renderTasks();
        });
    }

    function renderTasks() {
        var tasks = state.tasks.filter(function(t) {
            return state.visibleLists[t.listId] !== false;
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
            $('#task-list-container').html('<p class="hint">' + caldav_suite.label('no_tasks') + '</p>');
            return;
        }

        var prioWord = { 'priority-high': 'hoch', 'priority-medium': 'mittel', 'priority-low': 'niedrig' };

        var html = '<ul id="task-list" class="task-list">';
        tasks.forEach(function(task) {
            var priorityClass = '';
            var priorityLabel = '';
            if (task.priority >= 1 && task.priority <= 3) { priorityClass = 'priority-high'; priorityLabel = '!!!'; }
            else if (task.priority >= 4 && task.priority <= 6) { priorityClass = 'priority-medium'; priorityLabel = '!!'; }
            else if (task.priority >= 7) { priorityClass = 'priority-low'; priorityLabel = '!'; }

            var isOverdue = false;
            var dueHtml = '';
            if (task.due) {
                isOverdue = new Date(task.due) < new Date() && !task.completed;
                dueHtml = '<span class="task-due' + (isOverdue ? ' overdue' : '') + '" aria-hidden="true">'
                    + caldav_suite.formatDate(task.due) + '</span>';
            }

            // Volles aria-label -> NVDA liest beim Pfeilen genau eine klare Ansage.
            var aria = [
                task.summary,
                task.due ? ('fällig ' + caldav_suite.formatDate(task.due) + (isOverdue ? ' überfällig' : '')) : '',
                priorityClass ? ('Priorität ' + prioWord[priorityClass]) : '',
                task.completed ? 'erledigt' : 'offen'
            ].filter(function(s) { return s; }).join(', ');

            html += '<li class="task-item' + (task.completed ? ' completed' : '') + '" data-url="' + task.url + '"'
                + ' aria-label="' + rcmail.quote_html(aria) + '">'
                + '<label class="task-check" aria-hidden="true">'
                + '<input type="checkbox" tabindex="-1"' + (task.completed ? ' checked' : '')
                + ' data-url="' + task.url + '" data-etag="' + (task.etag || '') + '" />'
                + '</label>'
                + '<span class="task-summary" aria-hidden="true">' + rcmail.quote_html(task.summary) + '</span>'
                + dueHtml
                + (priorityLabel ? '<span class="task-priority ' + priorityClass + '" aria-hidden="true">' + priorityLabel + '</span>' : '')
                + '<button class="task-edit btn btn-sm" tabindex="-1" data-url="' + task.url + '" aria-hidden="true">&#9998;</button>'
                + '</li>';
        });
        html += '</ul>';

        $('#task-list-container').html(html);

        var openEdit = function(url) {
            var task = state.tasks.find(function(t) { return t.url === url; });
            if (task) caldav_task_dialog.open(task, state.taskLists);
        };
        var toggleDone = function(item) {
            var cb = item.querySelector('input[type="checkbox"]');
            if (!cb) return;
            pendingFocusUrl = item.getAttribute('data-url');
            cb.checked = !cb.checked;
            $(cb).trigger('change');
        };

        // Checkbox toggle (Maus + via toggleDone)
        $('#task-list input[type="checkbox"]').change(function() {
            rcmail.http_post('plugin.caldav-task-toggle', {
                _url: $(this).data('url'),
                _etag: $(this).data('etag'),
                _completed: this.checked ? '1' : '0'
            });
        });
        // Maus: Klick auf Edit-Button bzw. Titel oeffnet den Dialog
        $('#task-list .task-edit').click(function() { openEdit($(this).data('url')); });
        $('#task-list .task-summary').click(function() { openEdit($(this).closest('.task-item').data('url')); });

        // Einheitliche, screenreader-navigierbare Liste: Pfeil hoch/runter, Enter = bearbeiten,
        // Leertaste = ab-/anhaken.
        caldav_a11y.makeListNavigable(document.getElementById('task-list'), {
            itemSelector: '.task-item',
            label: caldav_suite.label('tasks'),
            onActivate: function(item) { openEdit(item.getAttribute('data-url')); },
            onToggle: toggleDone
        });

        // Nach einem Toggle-Reload den Fokus zuruecksetzen.
        if (pendingFocusUrl) {
            caldav_a11y.focusItemByAttr(document.getElementById('task-list'), 'data-url', pendingFocusUrl);
            pendingFocusUrl = null;
        }
    }
})();
