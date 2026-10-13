#!/usr/bin/env bash
# All-in-one: create cleaned autoload -> bring up the stack -> set up.
#   ./start.sh            (Docker direkt)
#   DOCKER="sudo docker" ./start.sh
set -euo pipefail
cd "$(dirname "$0")"
DOCKER="${DOCKER:-docker}"

./prep-runtime-vendor.sh
$DOCKER compose up -d
./setup.sh
