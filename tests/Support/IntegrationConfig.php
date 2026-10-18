<?php

declare(strict_types=1);

namespace Tests\Support;

use InvalidArgumentException;

final class IntegrationConfig
{
    private function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $database,
        public readonly string $user,
        public readonly string $password,
    ) {}

    public static function fromEnvironment(): self
    {
        $names = ['TEST_DB_HOST', 'TEST_DB_PORT', 'TEST_DB_NAME', 'TEST_DB_USER', 'TEST_DB_PASS'];
        $values = [];
        foreach ($names as $name) {
            $value = getenv($name);
            if ($value !== false) {
                $values[$name] = $value;
            }
        }
        return self::fromArray($values);
    }

    public static function fromArray(array $values): self
    {
        foreach (['TEST_DB_HOST', 'TEST_DB_PORT', 'TEST_DB_NAME', 'TEST_DB_USER', 'TEST_DB_PASS'] as $name) {
            if (!array_key_exists($name, $values) || !is_string($values[$name])) {
                throw new InvalidArgumentException('Explicit test database configuration is required.');
            }
        }

        if (!in_array($values['TEST_DB_HOST'], ['127.0.0.1', 'localhost'], true)
            || !ctype_digit($values['TEST_DB_PORT'])
            || (int) $values['TEST_DB_PORT'] < 1
            || (int) $values['TEST_DB_PORT'] > 65535
            || !preg_match('/\Adevwebcamp_port6_test_[a-f0-9]{8}\z/D', $values['TEST_DB_NAME'])
            || $values['TEST_DB_USER'] === '') {
            throw new InvalidArgumentException('Unsafe test database configuration.');
        }

        return new self(
            $values['TEST_DB_HOST'],
            (int) $values['TEST_DB_PORT'],
            $values['TEST_DB_NAME'],
            $values['TEST_DB_USER'],
            $values['TEST_DB_PASS'],
        );
    }
}
