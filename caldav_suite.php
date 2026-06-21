<?php

require_once __DIR__ . '/vendor/autoload.php';

use Slohmaier\CalDAVSuite\CalDAVClient;

class caldav_suite extends rcube_plugin
{
    public $task = '.*';

    private $rc;

    public function init()
    {
        $this->rc = rcmail::get_instance();

        // Register AJAX actions BEFORE register_task (which clobbers $this->mytask)
        $this->register_action('plugin.caldav-calendars', [$this, 'action_get_calendars']);
        $this->register_action('plugin.caldav-events', [$this, 'action_get_events']);
        $this->register_action('plugin.caldav-event-save', [$this, 'action_save_event']);
        $this->register_action('plugin.caldav-event-delete', [$this, 'action_delete_event']);
        $this->register_action('plugin.caldav-tasklists', [$this, 'action_get_tasklists']);
        $this->register_action('plugin.caldav-tasks', [$this, 'action_get_tasks']);
        $this->register_action('plugin.caldav-task-save', [$this, 'action_save_task']);
        $this->register_action('plugin.caldav-task-delete', [$this, 'action_delete_task']);
        $this->register_action('plugin.caldav-task-toggle', [$this, 'action_toggle_task']);
        $this->register_action('plugin.caldav-test-connection', [$this, 'action_test_connection']);

        $this->register_task('calendar');
        $this->register_task('tasks');

        $this->add_hook('startup', [$this, 'startup']);
        $this->add_hook('preferences_sections_list', [$this, 'preferences_sections_list']);
        $this->add_hook('preferences_list', [$this, 'preferences_list']);
        $this->add_hook('preferences_save', [$this, 'preferences_save']);
        $this->add_hook('addressbooks_list', [$this, 'addressbooks_list']);
        $this->add_hook('addressbook_get', [$this, 'addressbook_get']);
    }

    public function startup($args)
    {
        $this->add_texts('localization/', true);

        $this->add_button([
            'command'    => 'calendar',
            'label'      => 'caldav_suite.calendar',
            'type'       => 'link',
            'class'      => 'button-calendar',
            'classsel'   => 'button-calendar button-selected',
            'innerclass' => 'button-inner',
        ], 'taskbar');

        $this->add_button([
            'command'    => 'tasks',
            'label'      => 'caldav_suite.tasks',
            'type'       => 'link',
            'class'      => 'button-tasks',
            'classsel'   => 'button-tasks button-selected',
            'innerclass' => 'button-inner',
        ], 'taskbar');

        // CSS and base JS on all tasks (needed for taskbar icons + settings)
        $this->include_stylesheet($this->local_skin_path() . '/styles/caldav_suite.css');
        $this->include_script('js/caldav_suite.js');
        $this->include_script('js/a11y.js');

        if ($this->rc->task === 'calendar') {
            $this->include_script('js/calendar_view.js');
            $this->include_script('js/event_dialog.js');

            if (empty($this->rc->action) || $this->rc->action === 'index') {
                $this->calendar_view();
                $args['abort'] = true;
            }
        }

        if ($this->rc->task === 'tasks') {
            $this->include_script('js/task_view.js');
            $this->include_script('js/task_dialog.js');

            if (empty($this->rc->action) || $this->rc->action === 'index') {
                $this->tasks_view();
                $args['abort'] = true;
            }
        }

        return $args;
    }

    // ---- Views ----

    public function calendar_view()
    {
        $this->rc->output->set_pagetitle($this->gettext('calendar'));
        $this->rc->output->add_handlers([
            'plugin.calendarlist' => [$this, 'render_calendar_list'],
            'plugin.calendargrid' => [$this, 'render_calendar_grid'],
        ]);

        $prefs = $this->rc->user->get_prefs();
        $this->rc->output->set_env('caldav_configured', !empty($prefs['caldav_suite_url']));
        $this->rc->output->set_env('caldav_default_view', $prefs['caldav_suite_default_view'] ?? 'month');
        $this->rc->output->set_env('caldav_first_day', (int)($prefs['caldav_suite_first_day'] ?? 1));
        $this->rc->output->set_env('caldav_time_format', $prefs['caldav_suite_time_format'] ?? '24');
        $this->rc->output->set_env('caldav_geocode_provider', $prefs['caldav_suite_geocode_provider'] ?? 'photon');
        $this->rc->output->set_env('caldav_geocode_url', $prefs['caldav_suite_geocode_url'] ?? '');
        $this->rc->output->set_env('caldav_geocode_lang', 'de');

        $this->rc->output->send('caldav_suite.calendar');
    }

    public function tasks_view()
    {
        $this->rc->output->set_pagetitle($this->gettext('tasks'));
        $this->rc->output->add_handlers([
            'plugin.tasklistlist' => [$this, 'render_tasklist_list'],
            'plugin.tasklist'    => [$this, 'render_task_list'],
        ]);

        $prefs = $this->rc->user->get_prefs();
        $this->rc->output->set_env('caldav_configured', !empty($prefs['caldav_suite_url']));

        $this->rc->output->send('caldav_suite.tasklist');
    }

    // ---- Template Handlers ----

    public function render_calendar_list($attrib)
    {
        return '<div id="calendar-list" role="group" aria-label="' . $this->gettext('calendars') . '"></div>';
    }

    public function render_calendar_grid($attrib)
    {
        return '<div id="calendar-grid" role="main" aria-live="polite"></div>';
    }

    public function render_tasklist_list($attrib)
    {
        // Inneres <ul> wird per JS zur beschrifteten role=listbox (makeListNavigable);
        // Wrapper bleibt presentational, sonst doppelte Label-Ansage.
        return '<div id="tasklist-list"></div>';
    }

    public function render_task_list($attrib)
    {
        return '<div id="task-list-container" role="main" aria-live="polite"></div>';
    }

    // ---- AJAX Handlers ----

    public function action_get_calendars()
    {
        $clients = $this->get_all_caldav_clients();
        if (empty($clients)) {
            $this->rc->output->command('plugin.caldav-calendars-response', ['error' => $this->gettext('no_caldav_configured')]);
            $this->rc->output->send();
            return;
        }

        $calendars = [];
        $prefs = $this->rc->user->get_prefs();
        $colors = json_decode($prefs['caldav_suite_colors'] ?? '{}', true) ?: [];

        foreach ($clients as $client) {
            foreach ($client->getCalendars() as $cal) {
                $calendars[] = [
                    'id'    => $cal->getId(),
                    'name'  => $cal->displayName,
                    'url'   => $cal->url,
                    'color' => $colors[$cal->getId()] ?? $cal->color ?? '#4fc3f7',
                ];
            }
        }

        $this->rc->output->command('plugin.caldav-calendars-response', ['calendars' => $calendars]);
        $this->rc->output->send();
    }

    public function action_get_events()
    {
        $clients = $this->get_all_caldav_clients();
        if (empty($clients)) {
            $this->rc->output->command('plugin.caldav-events-response', ['error' => $this->gettext('no_caldav_configured')]);
            $this->rc->output->send();
            return;
        }

        $start = new \DateTimeImmutable(rcube_utils::get_input_value('_start', rcube_utils::INPUT_POST));
        $end = new \DateTimeImmutable(rcube_utils::get_input_value('_end', rcube_utils::INPUT_POST));
        $calendarIds = rcube_utils::get_input_value('_calendars', rcube_utils::INPUT_POST) ?: [];

        $events = [];
        foreach ($clients as $client) {
            foreach ($client->getCalendars() as $cal) {
                if (!empty($calendarIds) && !in_array($cal->getId(), $calendarIds)) {
                    continue;
                }
                foreach ($client->getEvents($cal->url, $start, $end) as $event) {
                    $data = $event->toArray();
                    $data['calendarId'] = $cal->getId();
                    $events[] = $data;
                }
            }
        }

        $this->rc->output->command('plugin.caldav-events-response', ['events' => $events]);
        $this->rc->output->send();
    }

    public function action_save_event()
    {
        $client = $this->get_caldav_client();
        if (!$client) {
            $this->rc->output->show_message($this->gettext('no_caldav_configured'), 'error');
            $this->rc->output->send();
            return;
        }

        $data = rcube_utils::get_input_value('_event', rcube_utils::INPUT_POST);
        $calendarUrl = rcube_utils::get_input_value('_calendar_url', rcube_utils::INPUT_POST);
        $existingUrl = rcube_utils::get_input_value('_url', rcube_utils::INPUT_POST);
        $etag = rcube_utils::get_input_value('_etag', rcube_utils::INPUT_POST);

        $backend = new \Slohmaier\CalDAVSuite\CalendarBackend();

        // Edit: bestehendes Objekt holen und mergen (erhaelt RRULE/ATTENDEE/etc.),
        // sonst neu bauen.
        if ($existingUrl && ($existing = $client->getObject($existingUrl))) {
            $ical = $backend->updateICalEvent($existing->serialize(), $data) ?: $backend->buildICalEvent($data);
        } else {
            $ical = $backend->buildICalEvent($data);
        }

        $url = $existingUrl ?: (rtrim($calendarUrl, '/') . '/' . \Sabre\VObject\UUIDUtil::getUUID() . '.ics');
        $success = $client->putObject($url, $ical, $etag ?: null);

        if ($success) {
            $this->rc->output->show_message($this->gettext('event_saved'), 'confirmation');
            $this->rc->output->command('plugin.caldav-event-saved', ['success' => true]);
        } else {
            $this->rc->output->show_message($this->gettext('error_saving'), 'error');
            $this->rc->output->command('plugin.caldav-event-saved', ['success' => false]);
        }
        $this->rc->output->send();
    }

    public function action_delete_event()
    {
        $client = $this->get_caldav_client();
        $url = rcube_utils::get_input_value('_url', rcube_utils::INPUT_POST);
        $etag = rcube_utils::get_input_value('_etag', rcube_utils::INPUT_POST);

        if ($client && $client->deleteObject($url, $etag ?: null)) {
            $this->rc->output->show_message($this->gettext('event_deleted'), 'confirmation');
            $this->rc->output->command('plugin.caldav-event-deleted', ['success' => true]);
        } else {
            $this->rc->output->show_message($this->gettext('error_deleting'), 'error');
        }
        $this->rc->output->send();
    }

    public function action_get_tasklists()
    {
        $client = $this->get_caldav_client();
        if (!$client) {
            $this->rc->output->command('plugin.caldav-tasklists-response', ['error' => $this->gettext('no_caldav_configured')]);
            $this->rc->output->send();
            return;
        }

        $lists = [];
        foreach ($client->getTaskLists() as $list) {
            $lists[] = [
                'id'   => $list->getId(),
                'name' => $list->displayName,
                'url'  => $list->url,
            ];
        }

        $this->rc->output->command('plugin.caldav-tasklists-response', ['lists' => $lists]);
        $this->rc->output->send();
    }

    public function action_get_tasks()
    {
        $client = $this->get_caldav_client();
        if (!$client) {
            $this->rc->output->command('plugin.caldav-tasks-response', ['error' => $this->gettext('no_caldav_configured')]);
            $this->rc->output->send();
            return;
        }

        $listUrl = rcube_utils::get_input_value('_list_url', rcube_utils::INPUT_POST);
        $includeCompleted = (bool)rcube_utils::get_input_value('_include_completed', rcube_utils::INPUT_POST);

        $tasks = [];
        $lists = $listUrl
            ? [new \Slohmaier\CalDAVSuite\Collection($listUrl, '', ['VTODO'])]
            : $client->getTaskLists();

        foreach ($lists as $list) {
            foreach ($client->getTasks($list->url, $includeCompleted) as $task) {
                $data = $task->toArray();
                $data['listId'] = $list->getId();
                $tasks[] = $data;
            }
        }

        $this->rc->output->command('plugin.caldav-tasks-response', ['tasks' => $tasks]);
        $this->rc->output->send();
    }

    public function action_save_task()
    {
        $client = $this->get_caldav_client();
        if (!$client) {
            $this->rc->output->show_message($this->gettext('no_caldav_configured'), 'error');
            $this->rc->output->send();
            return;
        }

        $data = rcube_utils::get_input_value('_task', rcube_utils::INPUT_POST);
        $listUrl = rcube_utils::get_input_value('_list_url', rcube_utils::INPUT_POST);
        $existingUrl = rcube_utils::get_input_value('_url', rcube_utils::INPUT_POST);
        $etag = rcube_utils::get_input_value('_etag', rcube_utils::INPUT_POST);

        $backend = new \Slohmaier\CalDAVSuite\TaskBackend();

        // Edit: bestehendes Objekt holen und mergen (erhaelt RRULE/eigene Props),
        // sonst neu bauen.
        if ($existingUrl && ($existing = $client->getObject($existingUrl))) {
            $ical = $backend->updateICalTodo($existing->serialize(), $data) ?: $backend->buildICalTodo($data);
        } else {
            $ical = $backend->buildICalTodo($data);
        }

        $url = $existingUrl ?: (rtrim($listUrl, '/') . '/' . \Sabre\VObject\UUIDUtil::getUUID() . '.ics');
        $success = $client->putObject($url, $ical, $etag ?: null);

        if ($success) {
            $this->rc->output->show_message($this->gettext('task_saved'), 'confirmation');
            $this->rc->output->command('plugin.caldav-task-saved', ['success' => true]);
        } else {
            $this->rc->output->show_message($this->gettext('error_saving'), 'error');
        }
        $this->rc->output->send();
    }

    public function action_delete_task()
    {
        $client = $this->get_caldav_client();
        $url = rcube_utils::get_input_value('_url', rcube_utils::INPUT_POST);
        $etag = rcube_utils::get_input_value('_etag', rcube_utils::INPUT_POST);

        if ($client && $client->deleteObject($url, $etag ?: null)) {
            $this->rc->output->show_message($this->gettext('task_deleted'), 'confirmation');
            $this->rc->output->command('plugin.caldav-task-deleted', ['success' => true]);
        } else {
            $this->rc->output->show_message($this->gettext('error_deleting'), 'error');
        }
        $this->rc->output->send();
    }

    public function action_toggle_task()
    {
        $client = $this->get_caldav_client();
        if (!$client) {
            $this->rc->output->send();
            return;
        }

        $url = rcube_utils::get_input_value('_url', rcube_utils::INPUT_POST);
        $etag = rcube_utils::get_input_value('_etag', rcube_utils::INPUT_POST);
        $completed = (bool)rcube_utils::get_input_value('_completed', rcube_utils::INPUT_POST);

        $backend = new \Slohmaier\CalDAVSuite\TaskBackend();
        $ical = $backend->toggleCompleted($url, $completed, $client);

        if ($ical && $client->putObject($url, $ical, $etag ?: null)) {
            $this->rc->output->command('plugin.caldav-task-toggled', ['success' => true, 'completed' => $completed]);
        } else {
            $this->rc->output->show_message($this->gettext('error_saving'), 'error');
        }
        $this->rc->output->send();
    }

    public function action_test_connection()
    {
        $url = rcube_utils::get_input_value('_url', rcube_utils::INPUT_POST);
        $username = rcube_utils::get_input_value('_username', rcube_utils::INPUT_POST);
        $password = rcube_utils::get_input_value('_password', rcube_utils::INPUT_POST);

        try {
            $client = new CalDAVClient($url, $username, $password);
            $collections = $client->discover();
            $calendars = count(array_filter($collections, fn($c) => $c->supportsEvents()));
            $taskLists = count(array_filter($collections, fn($c) => $c->supportsTodos()));

            // Also test CardDAV
            $cardClient = new \Slohmaier\CalDAVSuite\CardDAVClient($url, $username, $password);
            $addressbooks = count($cardClient->discoverAddressbooks());

            $this->rc->output->command('plugin.caldav-test-result', [
                'success'      => true,
                'calendars'    => $calendars,
                'tasklists'    => $taskLists,
                'addressbooks' => $addressbooks,
            ]);
            $this->rc->output->show_message(
                sprintf($this->gettext('connection_success_full'), $calendars, $taskLists, $addressbooks),
                'confirmation'
            );
        } catch (\Exception $e) {
            $this->rc->output->command('plugin.caldav-test-result', ['success' => false]);
            $this->rc->output->show_message($this->gettext('connection_failed'), 'error');
        }
        $this->rc->output->send();
    }

    // ---- Settings ----

    public function preferences_sections_list($args)
    {
        $args['list']['caldav_suite'] = [
            'id'      => 'caldav_suite',
            'section' => $this->gettext('settings'),
        ];
        return $args;
    }

    public function preferences_list($args)
    {
        if ($args['section'] !== 'caldav_suite') {
            return $args;
        }

        $prefs = $this->rc->user->get_prefs();

        $args['blocks']['connection'] = [
            'name'    => $this->gettext('caldav_connection'),
            'options' => [
                'url' => [
                    'title'   => $this->gettext('caldav_url'),
                    'content' => (new html_inputfield([
                        'name' => '_caldav_url', 'id' => 'caldav-url',
                        'size' => 60, 'value' => $prefs['caldav_suite_url'] ?? '',
                    ]))->show(),
                ],
                'username' => [
                    'title'   => $this->gettext('caldav_username'),
                    'content' => (new html_inputfield([
                        'name' => '_caldav_username', 'id' => 'caldav-username',
                        'size' => 30, 'value' => $prefs['caldav_suite_username'] ?? '',
                    ]))->show(),
                ],
                'password' => [
                    'title'   => $this->gettext('caldav_password'),
                    'content' => (new html_inputfield([
                        'name' => '_caldav_password', 'id' => 'caldav-password',
                        'size' => 30, 'type' => 'password', 'value' => '',
                    ]))->show() . ' <small>' . $this->gettext('password_hint') . '</small>',
                ],
                'extra_urls' => [
                    'title'   => $this->gettext('extra_caldav_urls'),
                    'content' => (new html_textarea([
                        'name' => '_caldav_extra_urls', 'id' => 'caldav-extra-urls',
                        'cols' => 60, 'rows' => 3,
                    ]))->show($prefs['caldav_suite_extra_urls'] ?? '')
                    . ' <small>' . $this->gettext('extra_urls_hint') . '</small>',
                ],
                'test' => [
                    'title'   => '',
                    'content' => html::tag('button', [
                        'type' => 'button', 'id' => 'caldav-test-btn',
                        'class' => 'btn btn-secondary',
                        'onclick' => 'caldav_suite_test_connection()',
                    ], $this->gettext('test_connection'))
                    . ' <span id="caldav-test-result"></span>',
                ],
            ],
        ];

        $viewSelect = new html_select(['name' => '_caldav_default_view', 'id' => 'caldav-default-view']);
        $viewSelect->add($this->gettext('view_month'), 'month');
        $viewSelect->add($this->gettext('view_week'), 'week');
        $viewSelect->add($this->gettext('view_day'), 'day');
        $viewSelect->add($this->gettext('view_list'), 'list');

        $daySelect = new html_select(['name' => '_caldav_first_day', 'id' => 'caldav-first-day']);
        $daySelect->add($this->gettext('monday'), '1');
        $daySelect->add($this->gettext('sunday'), '0');

        $timeSelect = new html_select(['name' => '_caldav_time_format', 'id' => 'caldav-time-format']);
        $timeSelect->add('24h', '24');
        $timeSelect->add('12h', '12');

        $args['blocks']['display'] = [
            'name'    => $this->gettext('display_settings'),
            'options' => [
                'default_view' => [
                    'title'   => $this->gettext('default_view'),
                    'content' => $viewSelect->show($prefs['caldav_suite_default_view'] ?? 'month'),
                ],
                'first_day' => [
                    'title'   => $this->gettext('first_day'),
                    'content' => $daySelect->show($prefs['caldav_suite_first_day'] ?? '1'),
                ],
                'time_format' => [
                    'title'   => $this->gettext('time_format'),
                    'content' => $timeSelect->show($prefs['caldav_suite_time_format'] ?? '24'),
                ],
            ],
        ];

        // Geocoding settings
        $geoSelect = new html_select(['name' => '_caldav_geocode_provider', 'id' => 'caldav-geocode-provider']);
        $geoSelect->add('Photon (komoot.io)', 'photon');
        $geoSelect->add('Nominatim (OpenStreetMap)', 'nominatim');

        $args['blocks']['geocoding'] = [
            'name'    => $this->gettext('geocoding_settings'),
            'options' => [
                'provider' => [
                    'title'   => $this->gettext('geocode_provider'),
                    'content' => $geoSelect->show($prefs['caldav_suite_geocode_provider'] ?? 'photon'),
                ],
                'geocode_url' => [
                    'title'   => $this->gettext('geocode_url'),
                    'content' => (new html_inputfield([
                        'name' => '_caldav_geocode_url', 'id' => 'caldav-geocode-url',
                        'size' => 60, 'value' => $prefs['caldav_suite_geocode_url'] ?? '',
                    ]))->show() . ' <small>' . $this->gettext('geocode_url_hint') . '</small>',
                ],
            ],
        ];

        return $args;
    }

    public function preferences_save($args)
    {
        if ($args['section'] !== 'caldav_suite') {
            return $args;
        }

        $args['prefs']['caldav_suite_url'] = rcube_utils::get_input_value('_caldav_url', rcube_utils::INPUT_POST);
        $args['prefs']['caldav_suite_username'] = rcube_utils::get_input_value('_caldav_username', rcube_utils::INPUT_POST);
        $args['prefs']['caldav_suite_default_view'] = rcube_utils::get_input_value('_caldav_default_view', rcube_utils::INPUT_POST);
        $args['prefs']['caldav_suite_first_day'] = rcube_utils::get_input_value('_caldav_first_day', rcube_utils::INPUT_POST);
        $args['prefs']['caldav_suite_time_format'] = rcube_utils::get_input_value('_caldav_time_format', rcube_utils::INPUT_POST);
        $args['prefs']['caldav_suite_extra_urls'] = rcube_utils::get_input_value('_caldav_extra_urls', rcube_utils::INPUT_POST);
        $args['prefs']['caldav_suite_geocode_provider'] = rcube_utils::get_input_value('_caldav_geocode_provider', rcube_utils::INPUT_POST);
        $args['prefs']['caldav_suite_geocode_url'] = rcube_utils::get_input_value('_caldav_geocode_url', rcube_utils::INPUT_POST);

        $password = rcube_utils::get_input_value('_caldav_password', rcube_utils::INPUT_POST);
        if (!empty($password)) {
            $args['prefs']['caldav_suite_password'] = $this->rc->encrypt($password);
        }

        return $args;
    }

    // ---- CardDAV Addressbook Hooks ----

    public function addressbooks_list($args)
    {
        $prefs = $this->rc->user->get_prefs();
        $url = $prefs['caldav_suite_url'] ?? '';
        if (empty($url)) {
            return $args;
        }

        $username = $prefs['caldav_suite_username'] ?? '';
        $password = $this->rc->decrypt($prefs['caldav_suite_password'] ?? '');
        if (empty($username) || $password === false) {
            return $args;
        }

        try {
            $client = new \Slohmaier\CalDAVSuite\CardDAVClient($url, $username, $password);
            $books = $client->discoverAddressbooks();
            foreach ($books as $bookUrl => $book) {
                $id = 'caldav_' . md5($bookUrl);
                $args['sources'][$id] = [
                    'id'       => $id,
                    'name'     => $book['displayName'],
                    'readonly' => false,
                    'groups'   => false,
                ];
            }
        } catch (\Throwable $e) {
            rcube::raise_error($e->getMessage(), true, false);
        }

        return $args;
    }

    public function addressbook_get($args)
    {
        if (!is_string($args['id']) || !str_starts_with($args['id'], 'caldav_')) {
            return $args;
        }

        $prefs = $this->rc->user->get_prefs();
        $url = $prefs['caldav_suite_url'] ?? '';
        $username = $prefs['caldav_suite_username'] ?? '';
        $password = $this->rc->decrypt($prefs['caldav_suite_password'] ?? '');

        if (empty($url) || empty($username) || $password === false) {
            return $args;
        }

        try {
            $client = new \Slohmaier\CalDAVSuite\CardDAVClient($url, $username, $password);
            $books = $client->discoverAddressbooks();
            foreach ($books as $bookUrl => $book) {
                $id = 'caldav_' . md5($bookUrl);
                if ($id === $args['id']) {
                    $args['instance'] = new \Slohmaier\CalDAVSuite\CardDAVAddressbook(
                        $id, $book['displayName'], $client, $bookUrl
                    );
                    break;
                }
            }
        } catch (\Throwable $e) {
            rcube::raise_error($e->getMessage(), true, false);
        }

        return $args;
    }

    // ---- Helpers ----

    private function get_caldav_client(): ?CalDAVClient
    {
        $prefs = $this->rc->user->get_prefs();
        $url = $prefs['caldav_suite_url'] ?? '';
        $username = $prefs['caldav_suite_username'] ?? '';
        $password = $prefs['caldav_suite_password'] ?? '';

        if (empty($url) || empty($username)) {
            return null;
        }

        $decrypted = $this->rc->decrypt($password);
        if ($decrypted === false) {
            return null;
        }

        try {
            return new CalDAVClient($url, $username, $decrypted);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get all CalDAV clients including additional URLs (e.g. shared calendars).
     * @return CalDAVClient[]
     */
    private function get_all_caldav_clients(): array
    {
        $clients = [];
        $main = $this->get_caldav_client();
        if ($main) {
            $clients[] = $main;
        }

        $prefs = $this->rc->user->get_prefs();
        $extraUrls = $prefs['caldav_suite_extra_urls'] ?? '';
        $username = $prefs['caldav_suite_username'] ?? '';
        $password = $prefs['caldav_suite_password'] ?? '';
        $decrypted = $this->rc->decrypt($password);

        if (!empty($extraUrls) && $decrypted !== false) {
            foreach (explode("\n", $extraUrls) as $url) {
                $url = trim($url);
                if (empty($url)) continue;
                try {
                    $clients[] = new CalDAVClient($url, $username, $decrypted);
                } catch (\Exception $e) {
                    // skip broken URLs
                }
            }
        }

        return $clients;
    }
}
