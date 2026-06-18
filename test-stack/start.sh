#!/usr/bin/env bash
# All-in-one: bereinigte Autoload erzeugen -> Stack hochfahren -> einrichten.
#   ./start.sh            (Docker direkt)
#   DOCKER="sudo docker" ./start.sh
set -euo pipefail
cd "$(dirname "$0")"
DOCKER="${DOCKER:-docker}"

./prep-runtime-vendor.sh
$DOCKER compose up -d
./setup.sh
