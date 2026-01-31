#!/usr/bin/env bash
set -e

if [ -f composer.json ]; then
  composer install --no-interaction --prefer-dist
fi

exec "$@"
