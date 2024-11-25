#!/usr/bin/env bash

set -euo pipefail

phan="$(dirname "$0")"

if [[ ! -f "$phan/vendor/autoload.php" ]]; then
	composer install --quiet --working-dir="$phan"
fi

exec "$phan/phan"
