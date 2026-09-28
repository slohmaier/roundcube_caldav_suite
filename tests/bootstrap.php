<?php
/**
 * PHPUnit bootstrap.
 *
 * Loads the composer autoloader and, for the test environment only, makes the
 * classes of the bundled roundcube/roundcubemail package autoloadable.
 *
 * That package arrives transitively via roundcube/plugin-installer and is
 * deliberately excluded from the production classmap (see "exclude-from-classmap"
 * in composer.json), because composer would otherwise let its core classes
 * (rcmail_sendmail, rcube_mime, ...) shadow the real Roundcube core classes.
 * The unit tests use those classes as Roundcube core stubs, so they are loaded
 * here on demand.
 */

require __DIR__ . '/../vendor/autoload.php';

(static function (): void {
    $root = __DIR__ . '/../vendor/roundcube/roundcubemail';

    if (!is_dir($root)) {
        return;
    }

    // Directories the roundcubemail classmap is built from; class name == file name.
    $dirs = [
        'program/include',
        'program/lib/Roundcube',
        'program/lib',
        'program/steps',
        'program/actions',
        'program/actions/mail',
        'program/actions/settings',
        'program/actions/contacts',
        'program/actions/utils',
    ];

    spl_autoload_register(static function (string $class) use ($root, $dirs): void {
        foreach ($dirs as $dir) {
            $file = $root . '/' . $dir . '/' . $class . '.php';
            if (is_file($file)) {
                require_once $file;
                return;
            }
        }
    });
})();
