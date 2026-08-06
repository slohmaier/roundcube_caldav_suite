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

    // Nach einem Toggle (Reload) den Fokus wieder auf dieselbe Aufgabe setzen.
    // Faellt sie aus der Liste (erledigt + "Erledigte zeigen" aus), Fokus auf den
    // Nachbarn legen, damit er nicht auf <body> faellt und NVDA im Fokus-Modus bleibt.
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
        // Nur bei "Offen" duerfen erledigte serverseitig wegfallen; sonst alle holen
        // und clientseitig filtern (siehe renderTasks).
        rcmail.http_post('plugin.caldav-tasks', {
            _include_completed: state.filter === 'open' ? '0' : '1'
        });
    }

    function renderTaskListSidebar() {
        var listAria = function(list, on) {
            return list.name + ', ' + (on ? 'eingeblendet' : 'ausgeblendet');
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

        var toggleList = function(item) {
            if (!item) return;
            var id = item.getAttribute('data-list-id');
            var on = !item.classList.contains('checked');
            state.visibleLists[id] = on;
            item.classList.toggle('checked', on);
            var list = state.taskLists.find(function(l) { return String(l.id) === String(id); });
            if (list) item.setAttribute('aria-label', listAria(list, on));
            renderTasks();
        };

        // Nur Klick auf die Checkbox togglet; Klick auf Zeile/Name aendert nichts.
        $('#tasklist-list .tasklist-check').click(function(e) {
            e.stopPropagation();
            toggleList($(this).closest('.tasklist-item')[0]);
        });
        $('#tasklist-list .tasklist-item').click(function() {
            // bewusst nichts (kein Toggle mehr)
        });

        // Gleiche Pfeil-Navigation wie die Hauptliste: hoch/runter zwischen den Listen,
        // Leertaste/Enter blendet die Liste ein/aus. KEINE fokussierbaren Kind-Elemente
        // (Status via .checked-Klasse + aria-label).
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
            // Liste leer -> Fokus auf den Hinweis statt verloren auf <body>.
            if (pendingFocusUrl) {
                var hint = document.querySelector('#task-list-container .hint');
                if (hint) hint.focus();
                pendingFocusUrl = null;
                pendingFocusFallback = null;
            }
            return;
        }

        var prioWord = { 'priority-high': 'hoch', 'priority-medium': 'mittel', 'priority-low': 'niedrig' };

        var html = '<div class="list-actions" role="toolbar" aria-label="Aufgaben-Aktionen">'
            + '<button type="button" class="btn btn-sm btn-task-new">' + rcmail.quote_html(caldav_suite.label('new_task')) + '</button>'
            + '<button type="button" class="btn btn-sm btn-task-edit" disabled>' + rcmail.quote_html(caldav_suite.label('edit_task')) + '</button>'
            + '<button type="button" class="btn btn-sm btn-task-delete" disabled>' + rcmail.quote_html(caldav_suite.label('delete')) + '</button>'
            + '</div>'
            + '<ul id="task-list" class="listing">';
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

            // KEINE fokussierbaren Nachfahren (kein <input>/<button>) im role=option-Item:
            // NVDA/Browser wuerde sonst Aktivierung/Klick auf das fokussierbare Kind statt
            // auf die Option routen ("nur Checkbox fokussiert"). Status steckt im aria-label
            // + .completed-Klasse; Check/Edit sind rein visuelle, per CSS gestylte <span>s.
            // Detail-Zeile (Notiz/Ort) nur bei Auswahl anzeigen.
            var detailHtml = '';
            var extras = [];
            if (task.description) { detailHtml += '<div class="task-desc" aria-hidden="true">' + rcmail.quote_html(task.description) + '</div>'; }
            if (task.location) { detailHtml += '<div class="task-loc" aria-hidden="true">' + rcmail.quote_html(task.location) + '</div>'; }

            html += '<li class="task-item' + (task.completed ? ' completed' : '') + '" data-url="' + task.url + '"'
                + ' data-etag="' + (task.etag || '') + '"'
                + ' aria-label="' + rcmail.quote_html(aria) + '">'
                + '<span class="task-check" aria-hidden="true"></span>'
                + '<span class="task-body">'
                + '<span class="task-summary" aria-hidden="true">' + rcmail.quote_html(task.summary) + '</span>'
                + dueHtml
                + (priorityLabel ? '<span class="task-priority ' + priorityClass + '" aria-hidden="true">' + priorityLabel + '</span>' : '')
                + (detailHtml ? '<span class="task-details">' + detailHtml + '</span>' : '')
                + '</span>'
                + '<span class="task-edit" aria-hidden="true">&#9998;</span>'
                + '</li>';
        });
        html += '</ul>';

        $('#task-list-container').html(html);

        var openEdit = function(url) {
            var task = state.tasks.find(function(t) { return t.url === url; });
            if (task) caldav_task_dialog.open(task, state.taskLists);
        };
        var newTask = function() {
            caldav_task_dialog.open(null, state.taskLists);
        };
        var deleteSelected = function() {
            var urls = Object.keys(selectedTaskUrls);
            if (!urls.length) return;
            var n = urls.length;
            var msg = n === 1 ? caldav_suite.label('confirm_delete_task') : n + ' Aufgaben wirklich löschen?';
            if (confirm(msg)) {
                rcmail.http_post('plugin.caldav-task-delete', { _urls: urls });
            }
        };
        // Ab-/anhaken ueber Item-Attribute (kein <input> mehr). Status = .completed-Klasse.
        var toggleDone = function(item) {
            if (!item) return;
            pendingFocusUrl = item.getAttribute('data-url');
            // Nachbarn als Fokus-Fallback merken (naechste bevorzugt, sonst vorige Aufgabe).
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

        // ---- Auswahl (wie Termin-Liste): Klick waehlt aus, Aktionen via Leiste ----
        var selectedTaskUrls = {};
        var taskSelectedCount = function() { return Object.keys(selectedTaskUrls).length; };
        var updateTaskActions = function() {
            var n = taskSelectedCount();
            $('#task-list-container .btn-task-delete').prop('disabled', n === 0);
            $('#task-list-container .btn-task-edit').prop('disabled', n !== 1);
        };
        var setTaskSelection = function(item) {
            if (!item) return;
            selectedTaskUrls = {};
            var url = item.getAttribute('data-url');
            if (url !== null) selectedTaskUrls[url] = true;
            $('#task-list .task-item').removeClass('selected');
            item.addClass('selected');
            updateTaskActions();
        };
        var toggleTaskSelection = function(item) {
            if (!item) return;
            var url = item.getAttribute('data-url');
            if (url === null) return;
            if (selectedTaskUrls[url]) { delete selectedTaskUrls[url]; item.removeClass('selected'); }
            else { selectedTaskUrls[url] = true; item.addClass('selected'); }
            updateTaskActions();
        };

        // Maus: Klick auf Check hakt ab, Klick auf Zeile/Summary waehlt nur aus (kein Auto-Edit).
        $('#task-list .task-check').click(function() { toggleDone($(this).closest('.task-item')[0]); });
        $('#task-list .task-item').click(function(e) {
            if (e.ctrlKey || e.metaKey) { toggleTaskSelection($(this)); }
            else { setTaskSelection($(this)); }
        });

        // Aktionsleiste
        $('#task-list-container .btn-task-new').click(newTask);
        $('#task-list-container .btn-task-edit').click(function() {
            if (taskSelectedCount() === 1) openEdit(Object.keys(selectedTaskUrls)[0]);
        });
        $('#task-list-container .btn-task-delete').click(deleteSelected);

        // Einheitliche, screenreader-navigierbare Liste: Pfeil hoch/runter, Enter = auswaehlen,
        // Leertaste = ab-/anhaken.
        caldav_a11y.makeListNavigable(document.getElementById('task-list'), {
            itemSelector: '.task-item',
            label: caldav_suite.label('tasks'),
            onActivate: function(item) { setTaskSelection(item); },
            onToggle: toggleDone
        });

        // Nach einem Toggle-Reload den Fokus zuruecksetzen.
        if (pendingFocusUrl) {
            var list = document.getElementById('task-list');
            var ok = caldav_a11y.focusItemByAttr(list, 'data-url', pendingFocusUrl);
            // Aufgabe ist aus der Liste gefallen -> Nachbarn probieren.
            if (!ok && pendingFocusFallback) {
                for (var i = 0; i < pendingFocusFallback.length && !ok; i++) {
                    ok = caldav_a11y.focusItemByAttr(list, 'data-url', pendingFocusFallback[i]);
                }
            }
            // Letzter Ausweg: Listbox-Container fokussieren (sein focus-Handler setzt die
            // erste Option aktiv), damit der Fokus nie auf <body> faellt.
            if (!ok && list) { list.focus(); }
            pendingFocusUrl = null;
            pendingFocusFallback = null;
        }
    }
})();
