#!/usr/bin/env bash
set -euo pipefail

# Simple helper to run test-suite safely inside the netvibe-web container
# - ensures the DB name is set to a safe default (netvibe-test)
# - optionally creates the test DB via existing PHP helper
# - runs pest inside the container with optional filter
#
# Usage:
#   scripts/run-tests.sh [--pattern PATTERN] [--yes]
#   scripts/run-tests.sh --help

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_SERVICE="netvibe-web"
TEST_DB_NAME="netvibe-test"

usage() {
  cat <<EOF
Usage: $(basename "$0") [--pattern PATTERN] [--yes]

Runs the project's Pest test-suite from inside the ${COMPOSE_SERVICE} container
in a safe manner. By default the script will require that the DB used for tests
is '${TEST_DB_NAME}' (the test database) and will refuse to run (or will ask
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

check_env_db() {
  # If .env exists, try to parse DB_DATABASE, otherwise check env from compose
  local db_from_env=""
  if [[ -f "$ROOT_DIR/.env" ]]; then
    # Parse DB_DATABASE from .env (rudimentary)
    db_from_env=$(grep -E '^DB_DATABASE=' "$ROOT_DIR/.env" | cut -d'=' -f2- || true)
  fi

  if [[ -z "$db_from_env" ]]; then
    # Try to read via docker compose but avoid failure if compose is not running
    if docker compose ps >/dev/null 2>&1; then
      # Ask the container what it thinks the env var is (best-effort)
      db_from_env=$(docker compose exec -T ${COMPOSE_SERVICE} sh -lc 'echo "${DB_DATABASE:-}"' || true)
    fi
  fi

  echo "$db_from_env"
}

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

# 1) Verify DB name
db_found=$(check_env_db)
if [[ -z "$db_found" ]]; then
  echo "[run-tests] Could not determine DB_DATABASE from .env or container — continuing with caution." >&2
else
  if [[ "$db_found" != "$TEST_DB_NAME" ]]; then
    echo "[run-tests] WARNING: DB_DATABASE appears to be '$db_found' (requires '$TEST_DB_NAME' to be safe)."
    confirm_or_abort "Proceed anyway?"
  fi
fi

# 2) Ensure the test DB exists (best-effort)
echo "[run-tests] Ensuring test database '${TEST_DB_NAME}' exists (best-effort)..."
docker compose exec ${COMPOSE_SERVICE} php scripts/create_test_db.php || {
  echo "[run-tests] Warning: could not run create_test_db.php - continuing anyway." >&2
}

# 3) Run tests inside container with DB override to ensure safety
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

echo "[run-tests] Running tests inside ${COMPOSE_SERVICE} (DB_DATABASE=${TEST_DB_NAME})"
# Use 'sh -c' to ensure DB_DATABASE is set for the pest process
docker compose exec ${COMPOSE_SERVICE} sh -lc "DB_DATABASE=${TEST_DB_NAME} ${PEST_CMD}"

EXIT_CODE=$?
if [[ $EXIT_CODE -eq 0 ]]; then
  echo "[run-tests] Tests completed successfully.";
else
  echo "[run-tests] Some tests failed (exit code $EXIT_CODE).";
fi

exit $EXIT_CODE
