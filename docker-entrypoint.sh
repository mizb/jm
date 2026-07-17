#!/bin/sh
set -eu

echo "JM API version ${JM_API_VERSION:-2026.07.07.7}"
echo "JM API listening on http://0.0.0.0:8088"

exec php \
  -d apc.enable_cli=1 \
  -d apc.shm_size=128M \
  -S 0.0.0.0:8088 \
  index.php
