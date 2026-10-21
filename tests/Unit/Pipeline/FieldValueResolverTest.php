<?php

declare(strict_types=1);

namespace Solo\Input\Tests\Unit\Pipeline;

use PHPUnit\Framework\TestCase;
use Solo\Input\Attributes\Input;
use Solo\Input\Cache\InputMetadata;
use Solo\Input\Cache\ReflectionCache;
use Solo\Input\Pipeline\FieldValueResolver;
use Solo\Input\Pipeline\ProcessorRegistry;

final class FieldValueResolverTest extends TestCase
{
    private FieldValueResolver $resolver;
    private InputMetadata $metadata;

    protected function setUp(): void
    {
        $this->resolver = new FieldValueResolver(new ProcessorRegistry());
        $this->metadata = (new ReflectionCache())->get(ValueInput::class);
    }

    public function testInstantiatePassesNamedArgumentsAndLeavesTheRestToDefaults(): void
    {
        $dto = $this->resolver->instantiate($this->metadata, ['count' => 3]);

        $this->assertInstanceOf(ValueInput::class, $dto);
        $this->assertSame(3, $dto->count);
        $this->assertSame('none', $dto->label);
    }

    public function testTheConstructorsOwnCheckRunsOnTheDecodedValues(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('count must not be negative');

        $this->resolver->instantiate($this->metadata, ['count' => -1]);
    }
}

#[Input]
final readonly class ValueInput
{
    public function __construct(
        public int $count,
        public string $label = 'none',
    ) {
        if ($count < 0) {
            throw new \DomainException('count must not be negative');
        }
    }
}
