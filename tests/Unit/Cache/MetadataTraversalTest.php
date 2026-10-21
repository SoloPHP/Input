<?php

declare(strict_types=1);

namespace Solo\Input\Tests\Unit\Cache\Traversal;

use PHPUnit\Framework\TestCase;
use Solo\Contracts\Validator\ValidatorInterface;
use Solo\Input\Attributes\Items;
use Solo\Input\Attributes\Validate;
use Solo\Input\Cache\ReflectionCache;
use Solo\Input\Exceptions\ConfigurationException;
use Solo\Input\Attributes\Input;
use Solo\Input\Inputs;
use Solo\Input\InputFactory;

final class MetadataTraversalTest extends TestCase
{
    public function testFailureOrderSurvivesRetriesAndCacheClearing(): void
    {
        $message = ConfigurationException::requiredWithDefault(BrokenFirstFieldParent::class, 'title')->getMessage();
        $cache = new ReflectionCache();
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            if ($attempt === 2) {
                $cache->clear();
            }
            try {
                $cache->get(BrokenFirstFieldParent::class);
                $this->fail('Expected ConfigurationException');
            } catch (ConfigurationException $e) {
                $this->assertSame($message, $e->getMessage());
            }
        }

        $this->assertSame(
            ['next', 'children', 'label'],
            array_keys($cache->get(RecursiveInput::class)->properties)
        );
    }

    public function testAbsentItemsAreValidatedBeforeInputProcessing(): void
    {
        Inputs::clearCache();
        $validator = $this->createMock(ValidatorInterface::class);
        $validator->expects($this->never())->method('validate');
        $factory = new InputFactory($validator);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            ConfigurationException::nullableRuleWithNonNullableType(
                BrokenChildInput::class,
                'quantity',
                'int'
            )->getMessage()
        );
        $factory->fromArray(BrokenItemsParent::class, []);
    }

    public function testRelativeTypesUseTheirDeclaringClassForBindingAndSchema(): void
    {
        $factory = new InputFactory($this->createStub(ValidatorInterface::class));
        $dto = $factory->fromArray(RelativeGrandchildInput::class, [
            'next' => ['label' => 'self', 'next' => ['label' => 'recursive']],
            'ancestor' => ['label' => 'parent'],
        ]);

        $this->assertNotNull($dto->next);
        $this->assertSame(RelativeBaseInput::class, $dto->next::class);
        $this->assertSame('self', $dto->next->label);
        $this->assertSame('recursive', $dto->next->next?->label);
        $this->assertNotNull($dto->ancestor);
        $this->assertSame(RelativeBaseInput::class, $dto->ancestor::class);
        $this->assertSame('parent', $dto->ancestor->label);
        $this->assertSame([
            'ancestor' => ['label' => 'parent'],
            'next' => ['next' => ['label' => 'recursive'], 'label' => 'self'],
        ], Inputs::toArray($dto, onlyProvided: true));

        $schema = $factory->schema(RelativeGrandchildInput::class);
        $this->assertSame(
            ['$ref' => RelativeBaseInput::class],
            $schema['next']['fields']['next']['fields'] ?? null
        );
        $this->assertSame(
            ['$ref' => RelativeBaseInput::class],
            $schema['ancestor']['fields']['next']['fields'] ?? null
        );
    }
}

#[Input]
final class BrokenChildInput
{
    public function __construct(
        #[Validate('nullable')]
        public int $quantity,
    ) {
    }
}

#[Input]
final class BrokenItemsParent
{
    public function __construct(
        /** @var list<BrokenChildInput> */
        #[Items(BrokenChildInput::class)]
        public array $children = [],
    ) {
    }
}

#[Input]
final class BrokenFirstFieldParent
{
    public function __construct(
        #[Validate('required')]
        public string $title = 'default',
        public ?BrokenChildInput $child = null,
    ) {
    }
}

#[Input]
final class RecursiveInput
{
    public function __construct(
        public ?RecursiveInput $next = null,
        /** @var list<RecursiveInput> */
        #[Items(RecursiveInput::class)]
        public array $children = [],
        public string $label = '',
    ) {
    }
}

#[Input]
class RelativeBaseInput
{
    public function __construct(
        public ?self $next = null,
        public string $label = '',
    ) {
    }
}

// Inherited fields are passed on to the parent's constructor.
class RelativeChildInput extends RelativeBaseInput
{
    public function __construct(
        public ?parent $ancestor = null,
        ?RelativeBaseInput $next = null,
        string $label = '',
    ) {
        parent::__construct($next, $label);
    }
}

final class RelativeGrandchildInput extends RelativeChildInput
{
}
