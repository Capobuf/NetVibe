<?php
/**
 * Create the `netvibe_test` database (idempotent).
 *
 * Intended to be executed from inside the application container.
 * By default it connects to the dedicated test MariaDB service.
 */

declare(strict_types=1);

$host = getenv('DB_HOST') ?: 'netvibe-test-db';
$port = (int) (getenv('DB_PORT') ?: 3306);

// For DB creation we need root privileges.
$user = 'root';
$pass = getenv('MARIADB_ROOT_PASSWORD') ?: (getenv('DB_PASSWORD') ?: 'secret');

$maxAttempts = 30;
$attempt = 0;
$pdo = null;
while ($attempt < $maxAttempts) {
    try {
        $pdo = new PDO("mysql:host={$host};port={$port}", $user, $pass, [PDO::ATTR_TIMEOUT => 2]);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        break;
    } catch (Throwable $e) {
        $attempt++;
        if ($attempt >= $maxAttempts) {
            fwrite(STDERR, "ERR: Unable to connect to {$host}:{$port} after {$maxAttempts} attempts — {$e->getMessage()}\n");
            exit(1);
        }
        echo "Waiting for DB ({$host}:{$port}) — attempt {$attempt}/{$maxAttempts}...\n";
        sleep(1);
    }
}

try {
    $pdo->exec('CREATE DATABASE IF NOT EXISTS `netvibe_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

    // Optionally grant privileges to the app user (useful when you don't run tests as root)
    $appUser = getenv('DB_USERNAME') ?: 'netvibe';
    $appPw = getenv('DB_PASSWORD') ?: 'secret';

    $escapedUser = addslashes($appUser);
    $escapedPw = addslashes($appPw);

    $pdo->exec("CREATE USER IF NOT EXISTS '{$escapedUser}'@'%' IDENTIFIED BY '{$escapedPw}';");
    $pdo->exec("GRANT ALL PRIVILEGES ON `netvibe_test`.* TO '{$escapedUser}'@'%';");
    $pdo->exec('FLUSH PRIVILEGES;');

    echo "DB_OK\n";
} catch (Throwable $e) {
    fwrite(STDERR, "ERR: {$e->getMessage()}\n");
    exit(1);
}
