<?php

namespace Solo\Input\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Solo\Input\Exceptions\ValidationException;

final class ExceptionsTest extends TestCase
{
    public function testValidationExceptionWithoutErrors(): void
    {
        $exception = new ValidationException();

        $this->assertEquals([], $exception->getErrors());
    }
}
