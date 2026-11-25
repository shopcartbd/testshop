#!/usr/bin/env bash
# AllStore local verification wrapper.
# Usage:
#   bash tests/run.sh                 # static checks + HTTP smoke test if server is up
#   SKIP_SMOKE=1 bash tests/run.sh    # static checks only
#   BASE_URL=http://host:port bash tests/run.sh
set -euo pipefail
cd "$(dirname "$0")/.."

echo "== PHP syntax =="
mapfile -d '' php_files < <(find . -type f -name '*.php' -not -path './vendor/*' -print0)
for f in "${php_files[@]}"; do php -l "$f" >/dev/null; done
echo "php -l passed for ${#php_files[@]} PHP files"

echo "== JavaScript syntax =="
for f in assets/js/*.js; do node --check "$f"; done
echo "node --check passed for $(find assets/js -maxdepth 1 -name '*.js' | wc -l) JS files"

echo "== Python test syntax =="
python3 -m py_compile tests/smoke.py
echo "python3 -m py_compile passed"

echo "== Static class references =="
php tests/validate_classes.php

echo "== Routes, handlers and method calls =="
php tests/validate.php

echo "== JSON configuration =="
python3 -m json.tool vercel.json >/dev/null
python3 -m json.tool vercel.vercel-php.example.json >/dev/null
echo "JSON configuration is valid"

if [[ "${SKIP_SMOKE:-0}" == "1" ]]; then
  echo "Smoke test skipped (SKIP_SMOKE=1)."
  echo "Static checks passed."
  exit 0
fi

BASE_URL="${BASE_URL:-http://127.0.0.1:8080}"
if ! curl -fsS "$BASE_URL/api/health" >/dev/null 2>&1; then
  echo "HTTP smoke test skipped: no server at $BASE_URL. Start: php -S 0.0.0.0:8080 router.php" >&2
  echo "Static checks passed."
  exit 0
fi
echo "== HTTP smoke test =="
export BASE_URL
python3 tests/smoke.py
