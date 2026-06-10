/**
 * CalDAV Suite - Task create/edit dialog
 */

window.caldav_task_dialog = {
    open: function(taskData, taskLists) {
        var isEdit = taskData && taskData.url;
        var title = isEdit ? caldav_suite.label('edit_task') : caldav_suite.label('new_task');

        var t = Object.assign({
            summary: '', description: '', due: '', priority: 0, listId: taskLists.length ? taskLists[0].id : ''
        }, taskData || {});

        var dueVal = t.due ? t.due.substr(0, 16) : '';

        var listOptions = '';
        taskLists.forEach(function(l) {
            var sel = l.id === t.listId ? ' selected' : '';
            listOptions += '<option value="' + l.url + '"' + sel + '>' + rcmail.quote_html(l.name) + '</option>';
        });

        var html = '<form class="propform" id="task-form">'
            + '<div class="prop"><label for="task-title">' + caldav_suite.label('title') + '</label>'
            + '<input type="text" id="task-title" class="form-control" value="' + rcmail.quote_html(t.summary) + '" required /></div>'
            + '<div class="prop"><label for="task-due">' + caldav_suite.label('due_date') + '</label>'
            + '<input type="datetime-local" id="task-due" class="form-control" value="' + dueVal + '" /></div>'
            + '<div class="prop"><label for="task-priority">' + caldav_suite.label('priority') + '</label>'
            + '<select id="task-priority" class="form-control">'
            + '<option value="0"' + (t.priority == 0 ? ' selected' : '') + '>' + caldav_suite.label('priority_none') + '</option>'
            + '<option value="1"' + (t.priority >= 1 && t.priority <= 3 ? ' selected' : '') + '>' + caldav_suite.label('priority_high') + '</option>'
            + '<option value="5"' + (t.priority >= 4 && t.priority <= 6 ? ' selected' : '') + '>' + caldav_suite.label('priority_medium') + '</option>'
            + '<option value="9"' + (t.priority >= 7 ? ' selected' : '') + '>' + caldav_suite.label('priority_low') + '</option>'
            + '</select></div>'
            + '<div class="prop"><label for="task-list">' + caldav_suite.label('select_tasklist') + '</label>'
            + '<select id="task-list" class="form-control">' + listOptions + '</select></div>'
            + '<div class="prop"><label for="task-desc">' + caldav_suite.label('description') + '</label>'
            + '<textarea id="task-desc" class="form-control" rows="3">' + rcmail.quote_html(t.description || '') + '</textarea></div>'
            + '</form>';

        var buttons = [
            {
                label: caldav_suite.label('save'),
                action: function(dlg) {
                    var formData = {
                        title: dlg.find('#task-title').val(),
                        description: dlg.find('#task-desc').val(),
                        due: dlg.find('#task-due').val(),
                        priority: dlg.find('#task-priority').val(),
                        uid: t.uid || ''
                    };
                    rcmail.http_post('plugin.caldav-task-save', {
                        _task: JSON.stringify(formData),
                        _list_url: dlg.find('#task-list').val(),
                        _url: t.url || '',
                        _etag: t.etag || ''
                    });
                }
            },
            { label: caldav_suite.label('cancel'), action: null }
        ];

        if (isEdit) {
            buttons.splice(1, 0, {
                label: caldav_suite.label('delete'),
                action: function() {
                    if (confirm(caldav_suite.label('confirm_delete_task'))) {
                        rcmail.http_post('plugin.caldav-task-delete', {
                            _url: t.url,
                            _etag: t.etag || ''
                        });
                    }
                }
            });
        }

        var dlg = caldav_suite.dialog(title, html, buttons);
        dlg.find('#task-title').focus();
    }
};
