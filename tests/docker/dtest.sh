#!/usr/bin/env bash
# Lance une commande dans le projet Drupal d'essai, le module monté dans
# web/modules/custom/pathly. Usage : tests/docker/dtest.sh <volume> <image> <commande>
set -euo pipefail
VOLUME="$1"; IMAGE="$2"; shift 2
ROOT="$(cd "$(dirname "$0")/../.." && pwd -W 2>/dev/null || pwd)"
MSYS_NO_PATHCONV=1 docker run --rm \
  -v "$VOLUME:/work" \
  -v pathly-composer-cache:/root/.composer/cache \
  -v "$ROOT:/work/project/web/modules/custom/pathly" \
  -w /work/project/web/modules/custom/pathly \
  "$IMAGE" bash -c "$*"
