<?php

declare(strict_types=1);

namespace Solo\Input\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Solo\Input\RuleParser;

final class RuleParserTest extends TestCase
{
    // ===== parse() =====

    public function testEmptySegmentsAreSkipped(): void
    {
        // Trailing pipe and a doubled pipe both produce empty segments.
        $this->assertSame(
            [
                ['name' => 'required', 'args' => []],
                ['name' => 'string', 'args' => []],
            ],
            RuleParser::parse('required||string|'),
        );
    }
}
