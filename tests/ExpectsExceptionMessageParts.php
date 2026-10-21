<?php

declare(strict_types=1);

namespace Solo\Input\Tests;

/**
 * expectExceptionMessage() keeps only the last message it is given: name
 * every part instead, matched in any order.
 *
 * @phpstan-require-extends \PHPUnit\Framework\TestCase
 */
trait ExpectsExceptionMessageParts
{
    private function expectExceptionMessageParts(string ...$parts): void
    {
        $lookaheads = array_map(static fn(string $part): string => '(?=.*' . preg_quote($part, '/') . ')', $parts);
        $this->expectExceptionMessageMatches('/^' . implode('', $lookaheads) . '/s');
    }
}
