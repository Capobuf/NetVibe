#!/bin/sh
# One-shot DB initializer for NetVibe
# Creates the dedicated test database on the main MariaDB instance (idempotent).
#
# NOTE: In docker-compose we also provide a dedicated `netvibe-test-db` service for tests.
# This script is kept as a utility for environments that prefer a single DB instance.

set -eu

DB_HOST="${DB_HOST:-netvibe-db}"
DB_PORT="${DB_PORT:-3306}"
DB_USER="root"
DB_PASS="${DB_PASSWORD:-secret}"
TEST_DB="netvibe_test"

echo "Waiting for MariaDB to become available (${DB_HOST}:${DB_PORT})..."
until mysqladmin ping -h "${DB_HOST}" -P "${DB_PORT}" --silent; do
  sleep 1
done

echo "Creating test database '${TEST_DB}' (idempotent)..."
mysql -h "${DB_HOST}" -P "${DB_PORT}" -u"${DB_USER}" -p"${DB_PASS}" \
  -e "CREATE DATABASE IF NOT EXISTS \`${TEST_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

echo "DB init completed."
