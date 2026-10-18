<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StandaloneContractTest extends TestCase
{
    // Temporary bridge: run selected isolated scripts until their assertions move into PHPUnit.
    public static function contracts(): array
    {
        return [
            'pdo' => ['persistence/pdo_contract.php', 'PDO ActiveRecord contract: 36 checks passed'],
            'speaker create' => ['quality/ponentes_create_contract.php', 'image double left no persistent upload files'],
            'speaker edit' => ['quality/ponentes_edit_contract.php', 'image double left no persistent upload files'],
        ];
    }

    #[DataProvider('contracts')]
    public function testIsolatedLegacyContract(string $file, string $expectedOutput): void
    {
        $runner = dirname(__DIR__) . '/' . $file;
        $process = proc_open([PHP_BINARY, $runner], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        self::assertSame(0, $exitCode, $stdout . $stderr);
        self::assertStringContainsString($expectedOutput, $stdout);
    }
}
