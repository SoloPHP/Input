<?php

declare(strict_types=1);

namespace Solo\Input\Tests\Unit\Grouping;

use PHPUnit\Framework\TestCase;
use Solo\Input\Attributes\Group;
use Solo\Input\Attributes\Input;
use Solo\Input\Inputs;

final class GroupBuilderTest extends TestCase
{
    public function testGroupThrowsExceptionOnDuplicateKeyFromScalar(): void
    {
        $dto = new ScalarDuplicateInput(filters: ['limit' => 100], limit: 10);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("Duplicate key 'limit' in group 'criteria' from field 'limit'");

        Inputs::group($dto, 'criteria');
    }

    public function testDuplicateKeyFromMergedArraysThrows(): void
    {
        $dto = new DuplicateKeyInput(first: ['status' => 'paid'], second: ['status' => 'draft']);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("Duplicate key 'status' in group 'criteria' from field 'second'");

        Inputs::group($dto, 'criteria');
    }

    public function testGroupReadsTheValuesWithoutChangingTheDto(): void
    {
        $dto = new DuplicateKeyInput(first: ['price >=' => '10.00'], second: ['price <=' => '20.00']);
        $before = Inputs::toArray($dto);

        $this->assertSame(['price >=' => '10.00', 'price <=' => '20.00'], Inputs::group($dto, 'criteria'));
        $this->assertSame($before, Inputs::toArray($dto));

        $dto = new DuplicateKeyInput(first: [], second: ['status' => 'active']);

        $this->assertSame(['status' => 'active'], Inputs::group($dto, 'criteria'));
    }

    public function testListsAndObjectsAreAddedUnderTheirKey(): void
    {
        $from = new \DateTimeImmutable('2026-10-01');
        $options = new \ArrayObject(['limit' => 10]);
        $dto = new OtherValuesInput(status: ['paid', 'pending'], created_from: $from, options: $options);

        // Only arrays merge: an iterable object stays one value.
        $this->assertSame(
            ['status IN' => ['paid', 'pending'], 'created_at >=' => $from, 'options' => $options],
            Inputs::group($dto, 'criteria')
        );
    }
}

#[Input]
final readonly class DuplicateKeyInput
{
    /**
     * @param array<array-key, mixed> $first
     * @param array<array-key, mixed> $second
     */
    public function __construct(
        #[Group('criteria')]
        public array $first,
        #[Group('criteria')]
        public array $second,
    ) {
    }
}

#[Input]
final readonly class ScalarDuplicateInput
{
    /**
     * @param array<string, mixed> $filters
     */
    public function __construct(
        #[Group('criteria')]
        public array $filters,
        #[Group('criteria')]
        public int $limit,
    ) {
    }
}

#[Input]
final readonly class OtherValuesInput
{
    /**
     * @param list<string>           $status
     * @param iterable<string, mixed> $options
     */
    public function __construct(
        #[Group('criteria', mapTo: 'status IN')]
        public array $status = [],
        #[Group('criteria', mapTo: 'created_at >=')]
        public ?\DateTimeImmutable $created_from = null,
        #[Group('criteria')]
        public iterable $options = [],
    ) {
    }
}
