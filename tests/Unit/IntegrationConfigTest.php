<?php

declare(strict_types=1);

namespace Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\IntegrationConfig;

require_once dirname(__DIR__) . '/Support/IntegrationConfig.php';

final class IntegrationConfigTest extends TestCase
{
    public static function unsafeConfigurations(): array
    {
        $valid = [
            'TEST_DB_HOST' => '127.0.0.1',
            'TEST_DB_PORT' => '3306',
            'TEST_DB_NAME' => 'devwebcamp_port6_test_a1b2c3d4',
            'TEST_DB_USER' => 'test_user',
            'TEST_DB_PASS' => '',
        ];

        return [
            'missing configuration' => [[]],
            'development database' => [array_replace($valid, ['TEST_DB_NAME' => 'devwebcamp'])],
            'empty database' => [array_replace($valid, ['TEST_DB_NAME' => ''])],
            'non-test database' => [array_replace($valid, ['TEST_DB_NAME' => 'other_database'])],
        ];
    }

    #[DataProvider('unsafeConfigurations')]
    public function testRejectsUnsafeConfigurationBeforeAnyConnection(array $values): void
    {
        $this->expectException(InvalidArgumentException::class);

        IntegrationConfig::fromArray($values);
    }
}
