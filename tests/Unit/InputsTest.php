<?php

declare(strict_types=1);

namespace Solo\Input\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Solo\Input\Attributes\Exclude;
use Solo\Input\Attributes\Group;
use Solo\Input\Attributes\Input;
use Solo\Input\Cache\ReflectionCache;
use Solo\Input\Exceptions\ConfigurationException;
use Solo\Input\Inputs;

final class InputsTest extends TestCase
{
    public function testClearCacheForOneClass(): void
    {
        $registry = ReflectionCache::shared();
        $static = $registry->get(StaticGroupInput::class);
        $multi = $registry->get(MultiGroupInput::class);

        Inputs::clearCache(StaticGroupInput::class);

        $this->assertNotSame($static, $registry->get(StaticGroupInput::class));
        $this->assertSame($multi, $registry->get(MultiGroupInput::class));
    }

    public function testToArrayDoesNotReplaceReferencedItems(): void
    {
        $item = new NestedItemInput(product: 'Widget', quantity: 3);
        $original = $item;

        $parent = new ParentWithItemsInput(items: ['first' => &$item, 7 => &$item]);

        $this->assertSame([
            'items' => [
                'first' => ['product' => 'Widget', 'quantity' => 3],
                7 => ['product' => 'Widget', 'quantity' => 3],
            ],
        ], Inputs::toArray($parent));
        $this->assertSame($original, $item);
        $this->assertSame(['first' => $original, 7 => $original], $parent->items);
    }

    public function testToArrayCopiesArraysAndSkipsExcludedFields(): void
    {
        $first = 'a';
        $dto = new ExportedInput(tags: [&$first, 'b'], secret: 'hidden');

        $exported = Inputs::toArray($dto);
        $first = 'changed';

        // A copy of the array: a reference in it does not reach the export.
        $this->assertSame(['tags' => ['a', 'b']], $exported);
    }

    public function testADtoBuiltWithNewSuppliedNothing(): void
    {
        $dto = new MultiGroupInput(status: 'active');

        $this->assertSame([], Inputs::provided($dto));
        $this->assertFalse(Inputs::isProvided($dto, 'status'));
        $this->assertSame([], Inputs::toArray($dto, onlyProvided: true));
        $this->assertSame(['status' => 'active'], Inputs::group($dto, 'criteria'));
    }

    public function testReadersRejectAnObjectThatIsNotAnInput(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Class stdClass is not an input class: mark it #[Input].');

        Inputs::toArray(new \stdClass());
    }
}

// ============================================================================
// Fixtures
// ============================================================================

#[Input]
final class StaticGroupInput
{
    public static int $counter = 0;

    public function __construct(
        #[Group('criteria')]
        public ?string $search = null,
    ) {
    }
}

#[Input]
final readonly class MultiGroupInput
{
    public function __construct(
        #[Group('criteria')]
        public ?string $status,
        #[Group('criteria')]
        public ?string $search = null,
    ) {
    }
}

#[Input]
final readonly class NestedItemInput
{
    public function __construct(
        public string $product,
        public int $quantity,
    ) {
    }
}

#[Input]
final readonly class ParentWithItemsInput
{
    /**
     * @param NestedItemInput[] $items
     */
    public function __construct(
        public array $items,
    ) {
    }
}

#[Input]
final readonly class ExportedInput
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public array $tags,
        #[Exclude]
        public string $secret,
    ) {
    }
}
