#!/usr/bin/env bash
set -euo pipefail

# Run the Pest test-suite safely inside Docker.
#
# - Forces tests to use the dedicated *test* DB service (`netvibe-test-db`) and DB name (`netvibe_test`).
# - Optionally starts required containers using compose profiles.
# - Runs Pest inside the `netvibe-web` container.

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_SERVICE="netvibe-web"
TEST_DB_NAME="netvibe_test"
TEST_DB_HOST="netvibe-test-db"
TEST_DB_USER="root"
TEST_DB_PASS="secret"

usage() {
  cat <<EOF
Usage: $(basename "$0") [--pattern PATTERN] [--yes]

Runs the project's Pest test-suite from inside the Docker compose service '${COMPOSE_SERVICE}'
in a safe manner. Tests are forced to use:
  - DB_HOST=${TEST_DB_HOST}
  - DB_DATABASE=${TEST_DB_NAME}

Options:
  --pattern PATTERN  Run only tests matching PATTERN (passed to pest).
  --yes              Skip interactive confirmation prompts.
  --help             Show this help and exit.

Examples:
  # run whole suite (interactive)
  scripts/run-tests.sh

  # run only a subset (non-interactive)
  scripts/run-tests.sh --pattern Unit --yes
EOF
}

PATTERN=""
YES="false"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --pattern)
      shift; PATTERN="${1:-}"; shift;;
    --yes)
      YES="true"; shift;;
    --help)
      usage; exit 0;;
    *)
      echo "Unknown argument: $1" >&2
      usage
      exit 2
      ;;
  esac
done

confirm_or_abort() {
  local msg="$1"
  if [[ "$YES" == "true" ]]; then
    echo "[run-tests] non-interactive: proceeding"
    return 0
  fi
  echo
  read -r -p "$msg (type YES to continue): " resp
  if [[ "$resp" != "YES" ]]; then
    echo "Aborted. No changes were made."
    exit 1
  fi
}

cd "$ROOT_DIR"

if ! command -v docker >/dev/null 2>&1; then
  echo "ERR: docker is required for this helper." >&2
  exit 1
fi

# Bring up the containers needed for tests.
# NOTE: netvibe-web currently lives under the dev profile; test DB under the test profile.
echo "[run-tests] Ensuring containers are up (profiles: dev + test)..."
docker compose --profile dev --profile test up -d netvibe-test-db netvibe-web >/dev/null 2>&1 || true

# Ensure the test DB exists (best-effort; test-db also creates it automatically).
echo "[run-tests] Ensuring test database '${TEST_DB_NAME}' exists (best-effort)..."
docker compose exec "$COMPOSE_SERVICE" sh -lc "DB_HOST=${TEST_DB_HOST} DB_PORT=3306 DB_PASSWORD=${TEST_DB_PASS} php scripts/create_test_db.php" || {
  echo "[run-tests] Warning: could not run create_test_db.php - continuing anyway." >&2
}

# Build pest command
PEST_CMD="./vendor/bin/pest"
if [[ -n "$PATTERN" ]]; then
  if [[ "$PATTERN" == */* || "$PATTERN" == *.php ]]; then
    PEST_CMD="$PEST_CMD $PATTERN"
  else
    PEST_CMD="$PEST_CMD --filter '$PATTERN'"
  fi
fi

echo "[run-tests] Running tests inside ${COMPOSE_SERVICE} (DB_HOST=${TEST_DB_HOST}, DB_DATABASE=${TEST_DB_NAME})"

docker compose exec "$COMPOSE_SERVICE" sh -lc "DB_CONNECTION=mysql DB_HOST=${TEST_DB_HOST} DB_DATABASE=${TEST_DB_NAME} DB_USERNAME=${TEST_DB_USER} DB_PASSWORD=${TEST_DB_PASS} ${PEST_CMD}"
