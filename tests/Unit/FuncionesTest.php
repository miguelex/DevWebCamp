<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/Helpers/funciones.php';

final class FuncionesTest extends TestCase
{
    public function testEscapingPreservesNullEmptyAndNormalValues(): void
    {
        self::assertSame('', s(null));
        self::assertSame('', s(''));
        self::assertSame('Synthetic text', s('Synthetic text'));
        self::assertSame('0', s('0'));
        self::assertSame('&lt;a title=&quot;quoted&quot;&gt;&amp;&#039;&lt;/a&gt;', s('<a title="quoted">&\'</a>'));
    }

    public function testEscapingStillRejectsArrayInputs(): void
    {
        $this->expectException(\TypeError::class);
        s(['invalid']);
    }
}
