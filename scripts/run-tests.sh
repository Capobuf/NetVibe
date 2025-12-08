#!/usr/bin/env bash
set -euo pipefail

# Simple helper to run test-suite safely inside the netvibe-web container
# - ensures the DB name is set to a safe default (netvibe-test)
# - forces the DB name to the test database (netvibe-test)
# - runs pest inside the container with optional filter
#
# Usage:
#   scripts/run-tests.sh [--pattern PATTERN] [--yes]
#   scripts/run-tests.sh --help

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_SERVICE="netvibe-web"
TEST_DB_NAME="netvibe-test"
# Credenziali di test forzate (coerenti con tests/Pest.php)
TEST_DB_HOST="netvibe-db"
TEST_DB_USER="root"
TEST_DB_PASS="secret"

usage() {
  cat <<EOF
Usage: $(basename "$0") [--pattern PATTERN] [--yes]

Runs the project's Pest test-suite from inside the ${COMPOSE_SERVICE} container
in a safe manner. By default the script will require that the DB used for tests
is '${TEST_DB_NAME}' (the test database) and will ignore any environment settings.
for interactive confirmation) if your environment appears different.

Options:
  --pattern PATTERN  Run only tests matching PATTERN (passed to pest).
  --yes              Skip interactive confirmation prompts.
  --help             Show this help and exit.

Examples:
  # run whole test-suite (interactive verify)
  scripts/run-tests.sh

  # run only a subset of tests
  scripts/run-tests.sh --pattern Feature/SomeTest

  # run non-interactively
  scripts/run-tests.sh --pattern Unit --yes

This helper assumes you use docker compose with the service name '${COMPOSE_SERVICE}'.
If your environment uses different compose service names or you're running tests
locally without docker, you can adapt the commands in this script accordingly.
EOF
}

PATTERN=""
YES="false"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --pattern)
      shift; PATTERN="$1"; shift;;
    --yes)
      YES="true"; shift;;
    --help)
      usage; exit 0;;
    *)
      echo "Unknown argument: $1" >&2; usage; exit 2;;
  esac
done

confirm_or_abort() {
  local msg="$1"
  if [[ "$YES" == "true" ]]; then
    echo "[run-tests] non-interactive: proceeding"
    return 0
  fi
  echo
  read -p "$msg (type YES to continue): " resp
  if [[ "$resp" != "YES" ]]; then
    echo "Aborted. No changes were made."; exit 1
  fi
}

# 1) Force DB env
echo "[run-tests] Forcing DB settings: DB_DATABASE=${TEST_DB_NAME}, DB_HOST=${TEST_DB_HOST}"

export DB_CONNECTION="mysql"
export DB_DATABASE="${TEST_DB_NAME}"
export DB_HOST="${TEST_DB_HOST}"
export DB_USERNAME="${TEST_DB_USER}"
export DB_PASSWORD="${TEST_DB_PASS}"

# 2) Ensure the test DB exists (best-effort inside docker if available)
if command -v docker >/dev/null 2>&1; then
  echo "[run-tests] Ensuring test database '${TEST_DB_NAME}' exists (best-effort)..."
  docker compose exec ${COMPOSE_SERVICE} php scripts/create_test_db.php || {
    echo "[run-tests] Warning: could not run create_test_db.php - continuing anyway." >&2
  }
else
  echo "[run-tests] docker not available; skipping create_test_db.php (DB must already be reachable at ${TEST_DB_HOST})."
fi

# 3) Build pest command
PEST_CMD="./vendor/bin/pest"
if [[ -n "$PATTERN" ]]; then
  # If user passed a path-like pattern (e.g. tests/Unit/SomeTest.php) pass it as
  # a direct argument to pest. Otherwise, pass it as --filter to run by name.
  if [[ "$PATTERN" == */* || "$PATTERN" == *.php ]]; then
    PEST_CMD="$PEST_CMD $PATTERN"
  else
    PEST_CMD="$PEST_CMD --filter '$PATTERN'"
  fi
fi

if command -v docker >/dev/null 2>&1; then
  echo "[run-tests] Running tests inside ${COMPOSE_SERVICE} (DB_DATABASE=${TEST_DB_NAME})"
  docker compose exec ${COMPOSE_SERVICE} sh -lc "DB_CONNECTION=${DB_CONNECTION} DB_HOST=${DB_HOST} DB_DATABASE=${DB_DATABASE} DB_USERNAME=${DB_USERNAME} DB_PASSWORD=${DB_PASSWORD} ${PEST_CMD}"
else
  echo "[run-tests] Running tests locally with enforced DB env (DB_HOST=${DB_HOST}, DB_DATABASE=${DB_DATABASE})"
  DB_CONNECTION=${DB_CONNECTION} DB_HOST=${DB_HOST} DB_DATABASE=${DB_DATABASE} DB_USERNAME=${DB_USERNAME} DB_PASSWORD=${DB_PASSWORD} ${PEST_CMD}
fi

EXIT_CODE=$?
if [[ $EXIT_CODE -eq 0 ]]; then
  echo "[run-tests] Tests completed successfully.";
else
  echo "[run-tests] Some tests failed (exit code $EXIT_CODE).";
fi

exit $EXIT_CODE
