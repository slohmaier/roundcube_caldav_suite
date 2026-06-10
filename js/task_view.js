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

        var html = '<ul id="task-list" class="task-list" role="list" aria-label="' + caldav_suite.label('tasks') + '">';
        tasks.forEach(function(task) {
            var priorityClass = '';
            var priorityLabel = '';
            if (task.priority >= 1 && task.priority <= 3) { priorityClass = 'priority-high'; priorityLabel = '!!!'; }
            else if (task.priority >= 4 && task.priority <= 6) { priorityClass = 'priority-medium'; priorityLabel = '!!'; }
            else if (task.priority >= 7) { priorityClass = 'priority-low'; priorityLabel = '!'; }

            var dueHtml = '';
            if (task.due) {
                var isOverdue = new Date(task.due) < new Date() && !task.completed;
                dueHtml = '<span class="task-due' + (isOverdue ? ' overdue' : '') + '">'
                    + caldav_suite.formatDate(task.due) + '</span>';
            }

            html += '<li class="task-item' + (task.completed ? ' completed' : '') + '" role="listitem" data-url="' + task.url + '">'
                + '<label class="task-check">'
                + '<input type="checkbox"' + (task.completed ? ' checked' : '')
                + ' data-url="' + task.url + '" data-etag="' + (task.etag || '') + '"'
                + ' aria-label="' + (task.completed ? 'Erledigt: ' : '') + rcmail.quote_html(task.summary) + '" />'
                + '</label>'
                + '<span class="task-summary" tabindex="0">' + rcmail.quote_html(task.summary) + '</span>'
                + dueHtml
                + (priorityLabel ? '<span class="task-priority ' + priorityClass + '" aria-label="Priorität: ' + priorityLabel + '">' + priorityLabel + '</span>' : '')
                + '<button class="task-edit btn btn-sm" data-url="' + task.url + '" aria-label="Bearbeiten: ' + rcmail.quote_html(task.summary) + '">&#9998;</button>'
                + '</li>';
        });
        html += '</ul>';

        $('#task-list-container').html(html);

        // Checkbox toggle
        $('#task-list input[type="checkbox"]').change(function() {
            rcmail.http_post('plugin.caldav-task-toggle', {
                _url: $(this).data('url'),
                _etag: $(this).data('etag'),
                _completed: this.checked ? '1' : '0'
            });
        });

        // Edit button
        $('#task-list .task-edit').click(function() {
            var url = $(this).data('url');
            var task = state.tasks.find(function(t) { return t.url === url; });
            if (task) caldav_task_dialog.open(task, state.taskLists);
        });

        // Click on summary
        $('#task-list .task-summary').click(function() {
            $(this).closest('.task-item').find('.task-edit').click();
        }).on('keydown', function(e) {
            if (e.key === 'Enter') $(this).click();
        });
    }
})();
