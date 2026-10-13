#!/usr/bin/env bash
# Creates a cleaned composer autoload for the container: removes the
# entries of the bundled roundcube/roundcubemail copy (transitive dev
# dependency of roundcube/plugin-installer). Otherwise its rcube_* classes
# override those of the running Roundcube and the plugin does not load.
#
# Called by start.sh and generate.sh BEFORE `docker compose up`.
# The real vendor/ (with roundcubemail) stays untouched -> PHPUnit keeps using it.
set -euo pipefail
cd "$(dirname "$0")"

SRC="../vendor/composer"
DST="vendor-runtime"

if [ ! -d "$SRC" ]; then
    echo "ERROR: $SRC missing. Run 'composer install' in the repo root first." >&2
    exit 1
fi

mkdir -p "$DST"
for f in autoload_classmap.php autoload_static.php; do
    sed '\#roundcube/roundcubemail#d' "$SRC/$f" > "$DST/$f"
done
echo "vendor-runtime/ updated (roundcube/roundcubemail removed from the autoload)."

# The plugin is mounted read-only into the container and read by the web server
# user (www-data). With a restrictive umask (e.g. 007), freshly checked-out files
# are 660 -> www-data cannot read them -> "Failed to load plugin file".
# a+rX (only adds read permissions) makes the plugin readable.
chmod -R a+rX .. 2>/dev/null || true
echo "Plugin files set world-readable (a+rX)."
