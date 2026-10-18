<?php

declare(strict_types=1);

use Tests\Support\IntegrationConfig;
use Tests\Support\IntegrationDatabase;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/tests/Support/IntegrationConfig.php';
require dirname(__DIR__) . '/tests/Support/IntegrationDatabase.php';

try {
    // Validate every test-only setting before opening any connection.
    $config = IntegrationConfig::fromEnvironment();
} catch (Throwable) {
    fwrite(STDERR, "Integration configuration rejected before connecting.\n");
    exit(2);
}

$server = null;
$target = null;
$created = false;
$result = 1;

try {
    $server = IntegrationDatabase::connect($config, 'information_schema');
    $exists = $server->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
    $exists->execute([$config->database]);
    if ($exists->fetchColumn() !== false) {
        throw new RuntimeException('The test database already exists; refusing to replace it.');
    }

    // The identifier was constrained to a fixed test-only pattern before connecting.
    $server->exec("CREATE DATABASE `{$config->database}` CHARACTER SET utf8mb3");
    $created = true;
    $target = IntegrationDatabase::connect($config, $config->database);
    $schema = file_get_contents(dirname(__DIR__) . '/tests/Integration/schema.sql');
    if ($schema === false) {
        throw new RuntimeException('The integration schema is missing.');
    }
    foreach (explode(';', $schema) as $statement) {
        if (trim($statement) !== '') {
            $target->exec($statement);
        }
    }

    $command = [PHP_BINARY, dirname(__DIR__) . '/vendor/bin/phpunit',
        '--configuration', dirname(__DIR__) . '/phpunit.xml', '--testsuite', 'Integration'];
    $process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, dirname(__DIR__));
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the integration suite.');
    }
    $result = proc_close($process);

    if ($target->query('SELECT DATABASE()')->fetchColumn() !== $config->database
        || (int) $target->query('SELECT COUNT(*) FROM categorias')->fetchColumn() !== 0
        || (int) $target->query('SELECT COUNT(*) FROM usuarios_devwebcamp')->fetchColumn() !== 0) {
        throw new RuntimeException('The integration database contains residual rows.');
    }
    echo "Integration database contains no residual rows.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Integration run failed (' . get_class($error) . ").\n");
    $result = 1;
} finally {
    if ($created && $server instanceof PDO) {
        try {
            if ($server->query('SELECT DATABASE()')->fetchColumn() !== 'information_schema') {
                throw new RuntimeException('Cleanup connection identity mismatch.');
            }
            $target = null;
            $server->exec("DROP DATABASE `{$config->database}`");
            echo "Disposable integration database removed.\n";
        } catch (Throwable $error) {
            fwrite(STDERR, 'Integration cleanup failed (' . get_class($error) . ").\n");
            $result = 1;
        }
    }
}

exit($result);
