#!/bin/sh
# One-shot DB initializer for NetVibe
# Wait until the DB accepts connections, then create the test database and grant privileges.
set -eu

echo "Waiting for MariaDB to become available..."
until mysqladmin ping -h netvibe-db --silent; do
  sleep 1
done

echo "Creating test database and grants if missing..."
mysql -h netvibe-db -uroot -p"${DB_PASSWORD}" -e "CREATE DATABASE IF NOT EXISTS \`netvibe-test\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON \`netvibe-test\`.* TO '${DB_USERNAME}'@'%' IDENTIFIED BY '${DB_PASSWORD}'; FLUSH PRIVILEGES;"

echo "DB init completed."
