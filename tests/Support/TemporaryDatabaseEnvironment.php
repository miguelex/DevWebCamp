<?php

declare(strict_types=1);

namespace Tests\Support;

final class TemporaryDatabaseEnvironment
{
    public static function run(IntegrationConfig $config, callable $callback): mixed
    {
        $replacement = [
            'DB_HOST' => $config->host,
            'DB_NAME' => $config->database,
            'DB_USER' => $config->user,
            'DB_PASS' => $config->password,
        ];
        $original = [];
        foreach ($replacement as $key => $value) {
            $original[$key] = [array_key_exists($key, $_ENV), $_ENV[$key] ?? null];
            $_ENV[$key] = $value;
        }

        try {
            return $callback();
        } finally {
            foreach ($original as $key => [$present, $value]) {
                if ($present) {
                    $_ENV[$key] = $value;
                } else {
                    unset($_ENV[$key]);
                }
            }
        }
    }
}
