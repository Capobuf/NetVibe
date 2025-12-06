<?php
/**
 * Create the netvibe-test database and grant privileges to the configured DB user.
 * Intended to be run inside the web container where the project is mounted.
 */

// Target DB host/port for the MariaDB server
$h = getenv('DB_HOST') ?: 'netvibe-db';
$p = getenv('DB_PORT') ?: 3306;
// For creating the DB we need root privileges. Prefer MARIADB_ROOT_PASSWORD or fall back to DB_PASSWORD.
$u = 'root';
// Root credentials: prefer MARIADB_ROOT_PASSWORD, otherwise fall back to DB_PASSWORD
$pw = getenv('MARIADB_ROOT_PASSWORD') ?: getenv('DB_PASSWORD') ?: '';

// Wait / retry loop: DB might not be listening immediately when this script runs.
$maxAttempts = 30;
$attempt = 0;
$pdo = null;
while ($attempt < $maxAttempts) {
    try {
        $pdo = new PDO("mysql:host={$h};port={$p}", $u, $pw, [PDO::ATTR_TIMEOUT => 2]);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        break;
    } catch (Exception $e) {
        $attempt++;
        if ($attempt >= $maxAttempts) {
            echo "ERR: Unable to connect to {$h}:{$p} after {$maxAttempts} attempts — " . $e->getMessage() . "\n";
            exit(1);
        }
        // Sleep a short time and retry
        echo "Waiting for DB ({$h}:{$p}) — attempt {$attempt}/{$maxAttempts}...\n";
        sleep(1);
    }
}

try {
    // Ensure the test database exists (idempotent)
    $pdo->exec('CREATE DATABASE IF NOT EXISTS `netvibe-test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

    // Create application user if missing and grant privileges. We use CREATE USER IF NOT EXISTS
    // so we don't accidentally reset an existing password, and then grant privileges.
    $appUser = getenv('DB_USERNAME') ?: 'netvibe';
    $appPw = getenv('DB_PASSWORD') ?: '';

    // MySQL / MariaDB supports CREATE USER IF NOT EXISTS and it is safe / idempotent.
    $escapedUser = addslashes($appUser);
    $escapedPw = addslashes($appPw);

    // Create the user if missing (won't change an existing user), then grant privileges.
    $pdo->exec("CREATE USER IF NOT EXISTS '{$escapedUser}'@'%' IDENTIFIED BY '{$escapedPw}';");
    $pdo->exec("GRANT ALL PRIVILEGES ON `netvibe-test`.* TO '{$escapedUser}'@'%';");
    $pdo->exec('FLUSH PRIVILEGES;');

    echo "DB_OK\n";
} catch (Exception $e) {
    echo "ERR: " . $e->getMessage() . "\n";
    exit(1);
}
