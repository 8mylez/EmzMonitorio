<?php declare(strict_types=1);

/*
 * Leichtgewichtiger Bootstrap fuer Laeufe ohne Shopware-Testkernel: nur
 * Autoloading (Shop-vendor + Plugin-Klassen). Die Stock-Tests brauchen keinen
 * Kernel - DB-nahe Tests bauen sich ihre DBAL-Connection selbst aus
 * DATABASE_URL und arbeiten in einer eigenen Test-Datenbank.
 *
 *   php phpunit.phar -c custom/plugins/EmzMonitorio/phpunit.xml \
 *       --bootstrap custom/plugins/EmzMonitorio/tests/bootstrap-standalone.php
 *
 * Der Standard-Bootstrap (tests/TestBootstrap.php, via TestBootstrapper)
 * bleibt fuer Umgebungen mit vollstaendigen dev-Dependencies unveraendert.
 */

$shopwareAutoload = dirname(__DIR__, 4) . '/vendor/autoload.php';

if (!is_file($shopwareAutoload)) {
    fwrite(\STDERR, 'Shopware-Autoloader nicht gefunden: ' . $shopwareAutoload . \PHP_EOL);
    exit(1);
}

/** @var \Composer\Autoload\ClassLoader $loader */
$loader = require $shopwareAutoload;
$loader->addPsr4('Emz\\Monitorio\\', dirname(__DIR__) . '/src/');
$loader->addPsr4('Emz\\Monitorio\\Tests\\', __DIR__ . '/');

// DATABASE_URL fuer die DB-nahen Tests: im Container meist keine OS-Env,
// sondern .env/.env.local des Shops - wie im Shopware-Boot per Dotenv laden.
if (!isset($_SERVER['DATABASE_URL']) && class_exists(\Symfony\Component\Dotenv\Dotenv::class)) {
    $envFile = dirname(__DIR__, 4) . '/.env';
    if (is_file($envFile)) {
        (new \Symfony\Component\Dotenv\Dotenv())->usePutenv(false)->bootEnv($envFile);
    }
}
