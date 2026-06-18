#!/usr/bin/env bash
# Erzeugt eine bereinigte Composer-Autoload fuer den Container: entfernt die
# Eintraege der gebuendelten roundcube/roundcubemail-Kopie (transitive Dev-
# Abhaengigkeit von roundcube/plugin-installer). Sonst ueberschreiben deren
# rcube_*-Klassen die des laufenden Roundcube und das Plugin laedt nicht.
#
# Wird von start.sh und generate.sh VOR `docker compose up` aufgerufen.
# Das echte vendor/ (mit roundcubemail) bleibt unangetastet -> PHPUnit nutzt es weiter.
set -euo pipefail
cd "$(dirname "$0")"

SRC="../vendor/composer"
DST="vendor-runtime"

if [ ! -d "$SRC" ]; then
    echo "FEHLER: $SRC fehlt. Erst im Repo-Root 'composer install' ausfuehren." >&2
    exit 1
fi

mkdir -p "$DST"
for f in autoload_classmap.php autoload_static.php; do
    sed '\#roundcube/roundcubemail#d' "$SRC/$f" > "$DST/$f"
done
echo "vendor-runtime/ aktualisiert (roundcube/roundcubemail aus Autoload entfernt)."

# Das Plugin wird read-only in den Container gemountet und vom Webserver-User
# (www-data) gelesen. Bei restriktivem umask (z.B. 007) sind frisch ausgecheckte
# Dateien 660 -> www-data kann nicht lesen -> "Failed to load plugin file".
# a+rX (nur Leserechte ergaenzen) macht das Plugin lesbar.
chmod -R a+rX .. 2>/dev/null || true
echo "Plugin-Dateien world-readable gesetzt (a+rX)."
