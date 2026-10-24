<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\IntegrationConfig;
use Tests\Support\TemporaryDatabaseEnvironment;

require_once dirname(__DIR__) . '/Support/IntegrationConfig.php';
require_once dirname(__DIR__) . '/Support/TemporaryDatabaseEnvironment.php';

final class TemporaryDatabaseEnvironmentTest extends TestCase
{
    public function testRestoresPresentAndAbsentKeysAfterSuccessAndFailure(): void
    {
        $config = IntegrationConfig::fromArray([
            'TEST_DB_HOST' => 'localhost',
            'TEST_DB_PORT' => '3306',
            'TEST_DB_NAME' => 'devwebcamp_port6_test_1234abcd',
            'TEST_DB_USER' => 'synthetic_test_user',
            'TEST_DB_PASS' => '',
        ]);
        $keys = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'];
        $original = [];
        foreach ($keys as $key) {
            $original[$key] = [array_key_exists($key, $_ENV), $_ENV[$key] ?? null];
        }

        try {
            $_ENV['DB_HOST'] = 'original-host';
            $_ENV['DB_PORT'] = '49999';
            unset($_ENV['DB_NAME']);
            $_ENV['DB_USER'] = 'original-user';
            unset($_ENV['DB_PASS']);
            $before = $this->snapshot($keys);

            TemporaryDatabaseEnvironment::run($config, static function () use ($config): void {
                self::assertTrue($_ENV['DB_HOST'] === $config->host);
                self::assertTrue($_ENV['DB_PORT'] === (string) $config->port);
                self::assertTrue($_ENV['DB_NAME'] === $config->database);
                self::assertTrue($_ENV['DB_USER'] === $config->user);
                self::assertTrue($_ENV['DB_PASS'] === $config->password);
            });
            self::assertTrue($before === $this->snapshot($keys));

            try {
                TemporaryDatabaseEnvironment::run($config, static function (): never {
                    throw new RuntimeException('Expected failure.');
                });
                self::fail('Callback failure must propagate.');
            } catch (RuntimeException $error) {
                self::assertSame('Expected failure.', $error->getMessage());
            }
            self::assertTrue($before === $this->snapshot($keys));
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

    private function snapshot(array $keys): array
    {
        $snapshot = [];
        foreach ($keys as $key) {
            $snapshot[$key] = [array_key_exists($key, $_ENV), $_ENV[$key] ?? null];
        }
        return $snapshot;
    }
}
