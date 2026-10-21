<?php

declare(strict_types=1);

namespace Solo\Input\Tests\Unit\Pipeline;

use PHPUnit\Framework\TestCase;
use Solo\Input\Pipeline\TypeGuard;

final class TypeGuardTest extends TestCase
{
    public function testAcceptsOnlyAValueOfAMember(): void
    {
        $type = (new \ReflectionProperty(TypeGuardUnion::class, 'value'))->getType();
        $guard = TypeGuard::fromType($type, TypeGuardUnion::class);

        $this->assertNotNull($guard);
        $this->assertTrue($guard->accepts(new \stdClass()));
        $this->assertTrue($guard->accepts(1));
        $this->assertTrue($guard->accepts(null));
        $this->assertFalse($guard->accepts('1'));
    }
}

final class TypeGuardUnion
{
    public object|int|null $value = null;
}
