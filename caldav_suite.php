<?php

use Slohmaier\CalDAVSuite\CalDAVClient;

class caldav_suite extends rcube_plugin
{
    public $task = 'mail|calendar|tasks|settings';

    public function init()
    {
        $this->add_hook('startup', array($this, 'startup'));
        $this->register_task('calendar');
        $this->register_task('tasks');
        $this->register_action('index', array($this, 'calendar_view'));
    }

    public function startup($args)
    {
        $rcmail = rcmail::get_instance();
        $this->add_texts('localization/', true);
        $this->include_stylesheet($this->local_skin_path() . '/styles/caldav_suite.css');
        $this->include_script('js/caldav_suite.js');

        // Add menu items
        $this->add_button(array(
            'command' => 'calendar',
            'label' => 'caldav_suite.calendar',
            'type' => 'link',
            'class' => 'button-calendar',
            'classsel' => 'button-calendar button-selected',
            'innerclass' => 'button-inner',
        ), 'taskbar');

        $this->add_button(array(
            'command' => 'tasks',
            'label' => 'caldav_suite.tasks',
            'type' => 'link',
            'class' => 'button-tasks',
            'classsel' => 'button-tasks button-selected',
            'innerclass' => 'button-inner',
        ), 'taskbar');

        return $args;
    }

    public function calendar_view()
    {
        // TODO: implement
    }
}
