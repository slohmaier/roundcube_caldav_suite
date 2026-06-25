<?php

require_once __DIR__ . '/vendor/autoload.php';

use Slohmaier\CalDAVSuite\CalDAVClient;

class caldav_suite extends rcube_plugin
{
    public $task = '.*';

    private $rc;

    /** Erkannte Kalender-Einladung in der gerade geladenen Mail (oder null). */
    private $itip = null;

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
        $this->register_action('plugin.caldav-itip-reply', [$this, 'action_itip_reply']);
        $this->register_action('plugin.caldav-itip-counter', [$this, 'action_itip_counter']);

        $this->register_task('calendar');
        $this->register_task('tasks');

        $this->add_hook('startup', [$this, 'startup']);
        $this->add_hook('preferences_sections_list', [$this, 'preferences_sections_list']);
        $this->add_hook('preferences_list', [$this, 'preferences_list']);
        $this->add_hook('preferences_save', [$this, 'preferences_save']);
        $this->add_hook('addressbooks_list', [$this, 'addressbooks_list']);
        $this->add_hook('addressbook_get', [$this, 'addressbook_get']);

        // iMIP / iTIP: Kalender-Einladungen in Mails (Annehmen/Ablehnen/Vorschlag).
        // Die zugehoerigen Actions werden oben VOR register_task() registriert.
        $this->add_hook('message_load', [$this, 'on_message_load']);
        $this->add_hook('template_object_messagebody', [$this, 'mail_itip_box']);
    }

    public function startup($args)
    {
        $this->add_texts('localization/', true);

        // CardDAV-Adressbuecher in die Empfaenger-Autovervollstaendigung aufnehmen
        $this->register_carddav_autocomplete();

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

        // iMIP-Einladungs-UI in der Mailansicht
        if ($this->rc->task === 'mail') {
            $this->include_script('js/itip.js');
        }

        if ($this->rc->task === 'calendar') {
            $this->include_script('js/calendar_view.js');
            $this->include_script('js/event_dialog.js');

            if (empty($this->rc->action) || $this->rc->action === 'index') {
                $this->calendar_view();
                $args['abort'] = true;
            }
        }

        if ($this->rc->task === 'addressbook') {
            // Adressbuch-/Gruppen-Sidebar navigierbar + sprechend (aria-activedescendant)
            $this->include_script('js/addressbook_a11y.js');
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
        // Inneres <ul> wird per JS zur beschrifteten role=listbox (makeListNavigable);
        // Wrapper bleibt presentational, sonst doppelte Label-Ansage.
        return '<div id="calendar-list"></div>';
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

    /**
     * Traegt die CardDAV-Adressbuecher dieses Plugins in autocomplete_addressbooks ein,
     * damit Kontakte bei der Empfaenger-Suche im Mail-Composer erscheinen. Roundcubes
     * Autocomplete iteriert nur die konfigurierten Quellen-IDs; die caldav_*-IDs sind
     * dynamisch -> hier zur Laufzeit ergaenzen. Discovery 1x pro Session gecacht.
     */
    private function register_carddav_autocomplete()
    {
        if ($this->rc->task !== 'mail') {
            return;
        }
        $prefs = $this->rc->user->get_prefs();
        if (empty($prefs['caldav_suite_url'])) {
            return;
        }

        // Discovery 1x pro Session cachen; leere Ergebnisse NICHT cachen, damit sich ein
        // transienter Discovery-Fehler nicht festsetzt (sonst bleibt Autocomplete leer).
        $ids = $_SESSION['caldav_suite_abook_ids'] ?? [];
        if (empty($ids)) {
            try {
                $username = $prefs['caldav_suite_username'] ?? '';
                $password = $this->rc->decrypt($prefs['caldav_suite_password'] ?? '');
                if (!empty($username) && $password !== false) {
                    $client = new \Slohmaier\CalDAVSuite\CardDAVClient($prefs['caldav_suite_url'], $username, $password);
                    foreach ($client->discoverAddressbooks() as $bookUrl => $book) {
                        $ids[] = 'caldav_' . md5($bookUrl);
                    }
                }
            } catch (\Throwable $e) {
                rcube::raise_error($e->getMessage(), true, false);
            }
            if (!empty($ids)) {
                $_SESSION['caldav_suite_abook_ids'] = $ids;
            }
        }

        if (!empty($ids)) {
            $ac = (array) $this->rc->config->get('autocomplete_addressbooks', 'sql');
            $merged = array_values(array_unique(array_merge($ac, $ids)));
            $this->rc->config->set('autocomplete_addressbooks', $merged);
        }
    }

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
    // ===== iMIP / iTIP (Kalender-Einladungen in Mails) =====

    /** message_load: gerade geladene Mail auf eine REQUEST-Einladung pruefen. */
    public function on_message_load($args)
    {
        $message = $args['object'] ?? null;
        if (!$message || empty($message->mime_parts)) {
            return $args;
        }
        foreach ($message->mime_parts as $part) {
            if (strtolower((string) $part->mimetype) !== 'text/calendar') {
                continue;
            }
            $hdrMethod = strtoupper((string) ($part->ctype_parameters['method'] ?? ''));
            $body      = $message->get_part_body($part->mime_id, false);
            $parsed    = $body ? \Slohmaier\CalDAVSuite\ITip::parse($body) : null;
            if ($parsed && ($parsed['method'] === 'REQUEST' || $hdrMethod === 'REQUEST')) {
                $this->itip = [
                    'parsed'  => $parsed,
                    'mbox'    => $message->folder,
                    'uid'     => $message->uid,
                    'mime_id' => $part->mime_id,
                ];
                break;
            }
        }
        return $args;
    }

    /** template_object_messagebody: Einladungs-Box ueber den Mailtext setzen. */
    public function mail_itip_box($args)
    {
        if (empty($this->itip)) {
            return $args;
        }
        $args['content'] = $this->render_itip_box($this->itip) . $args['content'];
        return $args;
    }

    private function render_itip_box(array $ctx): string
    {
        $p   = $ctx['parsed'];
        $Q   = fn($s) => rcube::Q((string) $s);
        $g   = fn($k) => $this->gettext($k);

        $when = $this->itip_format_when($p);
        $org  = $p['organizer'] ? trim(($p['organizer']['name'] ?: '') . ' <' . $p['organizer']['email'] . '>') : '';

        $cals = $this->get_calendars_list();
        $calSelect = '';
        if (!empty($cals)) {
            $opts = '';
            foreach ($cals as $c) {
                $opts .= '<option value="' . $Q($c['url']) . '">' . $Q($c['name']) . '</option>';
            }
            $calSelect = '<div class="caldav-itip-calsel">'
                . '<label for="caldav-itip-cal">' . $Q($g('itip_in_calendar')) . '</label> '
                . '<select id="caldav-itip-cal" class="form-control">' . $opts . '</select>'
                . '</div>';
        }

        $details = '<dt>' . $Q($g('itip_when')) . '</dt><dd>' . $Q($when) . '</dd>';
        if ($p['location'] !== '') {
            $details .= '<dt>' . $Q($g('itip_where')) . '</dt><dd>' . $Q($p['location']) . '</dd>';
        }
        if ($org !== '') {
            $details .= '<dt>' . $Q($g('itip_organizer')) . '</dt><dd>' . $Q($org) . '</dd>';
        }
        $details .= '<dt>' . $Q($g('itip_status')) . '</dt><dd id="caldav-itip-status">' . $Q($g('itip_status_pending')) . '</dd>';

        $startAttr = $p['dtstart'] ? $this->itip_local_dt($p['dtstart']) : '';
        $endAttr   = $p['dtend'] ? $this->itip_local_dt($p['dtend']) : '';

        $html = '<div class="caldav-itip" role="region" aria-labelledby="caldav-itip-title"'
            . ' data-msg-uid="' . $Q($ctx['uid']) . '"'
            . ' data-mbox="' . $Q($ctx['mbox']) . '"'
            . ' data-mime-id="' . $Q($ctx['mime_id']) . '"'
            . ' data-start="' . $Q($startAttr) . '" data-end="' . $Q($endAttr) . '"'
            . ' data-allday="' . ($p['allday'] ? '1' : '0') . '">'
            . '<h2 id="caldav-itip-title" class="caldav-itip-title">'
            . '<span class="caldav-itip-badge">' . $Q($g('itip_invitation')) . '</span> '
            . $Q($p['summary'] !== '' ? $p['summary'] : $g('itip_no_title')) . '</h2>'
            . '<dl class="caldav-itip-details">' . $details . '</dl>'
            . $calSelect
            . '<div class="caldav-itip-actions" role="group" aria-label="' . $Q($g('itip_respond_group')) . '">'
            . '<button type="button" class="btn btn-primary caldav-itip-reply" data-partstat="ACCEPTED">' . $Q($g('itip_accept')) . '</button>'
            . '<button type="button" class="btn caldav-itip-reply" data-partstat="TENTATIVE">' . $Q($g('itip_tentative')) . '</button>'
            . '<button type="button" class="btn caldav-itip-reply" data-partstat="DECLINED">' . $Q($g('itip_decline')) . '</button>'
            . '<button type="button" class="btn caldav-itip-propose">' . $Q($g('itip_propose')) . '</button>'
            . '</div>'
            . '<div class="caldav-itip-live" aria-live="polite"></div>'
            . '</div>';

        return $html;
    }

    private function get_calendars_list(): array
    {
        $out = [];
        foreach ($this->get_all_caldav_clients() as $client) {
            try {
                foreach ($client->getCalendars() as $cal) {
                    $out[] = ['url' => $cal->url, 'name' => $cal->displayName];
                }
            } catch (\Throwable $e) {
                // defekten Client ueberspringen
            }
        }
        return $out;
    }

    private function get_my_emails(): array
    {
        $emails = [];
        foreach ((array) $this->rc->user->list_emails() as $ident) {
            if (!empty($ident['email'])) {
                $emails[] = strtolower($ident['email']);
            }
        }
        return array_values(array_unique($emails));
    }

    private function fetch_itip_ical($mbox, $uid, $mime_id): ?string
    {
        if (!$mbox || !$uid || $mime_id === null || $mime_id === '') {
            return null;
        }
        $this->rc->storage->set_folder($mbox);
        $message = new rcube_message($uid, $mbox);
        if (empty($message->mime_parts)) {
            return null;
        }
        $body = $message->get_part_body($mime_id, false);
        return $body ?: null;
    }

    private function itip_local_dt(\DateTimeInterface $dt): string
    {
        try {
            $tz = new \DateTimeZone($this->rc->config->get('timezone') ?: 'UTC');
            $d  = new \DateTime('@' . $dt->getTimestamp());
            $d->setTimezone($tz);
            return $d->format('Y-m-d\TH:i');
        } catch (\Throwable $e) {
            return $dt->format('Y-m-d\TH:i');
        }
    }

    private function itip_format_when(array $p): string
    {
        if (!$p['dtstart']) {
            return '';
        }
        $start = $this->itip_local_dt($p['dtstart']);
        if ($p['allday']) {
            return substr($start, 0, 10);
        }
        $s = str_replace('T', ' ', $start);
        if ($p['dtend']) {
            $e = $this->itip_local_dt($p['dtend']);
            return $s . ' – ' . substr($e, 11, 5);
        }
        return $s;
    }

    private function itip_status_word(string $partstat): string
    {
        $map = ['ACCEPTED' => 'itip_accepted', 'TENTATIVE' => 'itip_tentatived', 'DECLINED' => 'itip_declined'];
        return $this->gettext($map[$partstat] ?? 'itip_done');
    }

    private function itip_respond(bool $ok, string $message, string $status = '', bool $lock = true): void
    {
        $this->rc->output->command('plugin.caldav-itip-response', [
            'success' => $ok,
            'message' => $message,
            'status'  => $status,
            'lock'    => $lock,
        ]);
        $this->rc->output->send();
    }

    public function action_itip_reply()
    {
        $partstat = strtoupper((string) rcube_utils::get_input_value('_partstat', rcube_utils::INPUT_POST));
        $mbox     = rcube_utils::get_input_value('_mbox', rcube_utils::INPUT_POST);
        $uid      = rcube_utils::get_input_value('_uid', rcube_utils::INPUT_POST);
        $mime_id  = rcube_utils::get_input_value('_mime_id', rcube_utils::INPUT_POST);
        $calUrl   = rcube_utils::get_input_value('_calendar_url', rcube_utils::INPUT_POST);

        if (!in_array($partstat, ['ACCEPTED', 'TENTATIVE', 'DECLINED'], true)) {
            $this->itip_respond(false, $this->gettext('itip_error'));
            return;
        }

        $ical = $this->fetch_itip_ical($mbox, $uid, $mime_id);
        $parsed = $ical ? \Slohmaier\CalDAVSuite\ITip::parse($ical) : null;
        if (!$parsed) {
            $this->itip_respond(false, $this->gettext('itip_error'));
            return;
        }

        $ident   = $this->rc->user->get_identity() ?: [];
        $me       = \Slohmaier\CalDAVSuite\ITip::myAttendee($parsed, $this->get_my_emails());
        $myEmail = $me['email'] ?? ($ident['email'] ?? '');
        $myName  = $me['name'] ?: ($ident['name'] ?? '');

        // Annehmen/Vorbehalt -> Event in gewaehlten Kalender schreiben
        if (in_array($partstat, ['ACCEPTED', 'TENTATIVE'], true) && $calUrl) {
            $stored = \Slohmaier\CalDAVSuite\ITip::buildStoredEvent($ical, $myEmail, $partstat);
            $client = $this->get_caldav_client();
            if ($stored && $client) {
                $slug = preg_replace('/[^A-Za-z0-9._-]/', '', $parsed['uid']) ?: \Sabre\VObject\UUIDUtil::getUUID();
                $url  = rtrim($calUrl, '/') . '/' . $slug . '.ics';
                try {
                    $client->putObject($url, $stored, null);
                } catch (\Throwable $e) {
                    // Schreibfehler nicht fatal fuer die REPLY
                }
            }
        }

        // REPLY an Organizer senden
        $org = $parsed['organizer']['email'] ?? '';
        if ($org !== '' && $myEmail !== '') {
            $reply = \Slohmaier\CalDAVSuite\ITip::buildReply($ical, $myEmail, $myName, $partstat);
            if ($reply) {
                $word    = $this->itip_status_word($partstat);
                $subject = $word . ': ' . $parsed['summary'];
                $body    = sprintf('%s: %s', $parsed['summary'], $word);
                \Slohmaier\CalDAVSuite\ITip::send($this->rc, $myEmail, $myName, $org, $subject, $body, $reply, 'REPLY');
            }
        }

        $this->itip_respond(true, $this->itip_status_word($partstat), $this->itip_status_word($partstat));
    }

    public function action_itip_counter()
    {
        $mbox    = rcube_utils::get_input_value('_mbox', rcube_utils::INPUT_POST);
        $uid     = rcube_utils::get_input_value('_uid', rcube_utils::INPUT_POST);
        $mime_id = rcube_utils::get_input_value('_mime_id', rcube_utils::INPUT_POST);
        $startIn = rcube_utils::get_input_value('_start', rcube_utils::INPUT_POST);
        $endIn   = rcube_utils::get_input_value('_end', rcube_utils::INPUT_POST);
        $comment = rcube_utils::get_input_value('_comment', rcube_utils::INPUT_POST);

        $ical   = $this->fetch_itip_ical($mbox, $uid, $mime_id);
        $parsed = $ical ? \Slohmaier\CalDAVSuite\ITip::parse($ical) : null;
        if (!$parsed) {
            $this->itip_respond(false, $this->gettext('itip_error'));
            return;
        }

        try {
            $tz    = new \DateTimeZone($this->rc->config->get('timezone') ?: 'UTC');
            $start = new \DateTime($startIn, $tz);
            $end   = new \DateTime($endIn ?: $startIn, $tz);
        } catch (\Throwable $e) {
            $this->itip_respond(false, $this->gettext('itip_error'));
            return;
        }

        $ident   = $this->rc->user->get_identity() ?: [];
        $me      = \Slohmaier\CalDAVSuite\ITip::myAttendee($parsed, $this->get_my_emails());
        $myEmail = $me['email'] ?? ($ident['email'] ?? '');
        $myName  = $me['name'] ?: ($ident['name'] ?? '');

        $org = $parsed['organizer']['email'] ?? '';
        if ($org === '' || $myEmail === '') {
            $this->itip_respond(false, $this->gettext('itip_error'));
            return;
        }

        $counter = \Slohmaier\CalDAVSuite\ITip::buildCounter($ical, $start, $end, $comment ?: null);
        if (!$counter) {
            $this->itip_respond(false, $this->gettext('itip_error'));
            return;
        }

        $subject = $this->gettext('itip_counter_subject') . ': ' . $parsed['summary'];
        $body    = sprintf('%s: %s', $parsed['summary'], $this->gettext('itip_counter_subject'));
        \Slohmaier\CalDAVSuite\ITip::send($this->rc, $myEmail, $myName, $org, $subject, $body, $counter, 'COUNTER');

        $this->itip_respond(true, $this->gettext('itip_counter_sent'), $this->gettext('itip_counter_sent'), false);
    }

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
