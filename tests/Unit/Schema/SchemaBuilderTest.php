<?php

declare(strict_types=1);

namespace Solo\Input\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use Solo\Input\Attributes\Cast;
use Solo\Input\Attributes\DateFormat;
use Solo\Input\Attributes\EmptyToNull;
use Solo\Input\Attributes\Exclude;
use Solo\Input\Attributes\Generator;
use Solo\Input\Attributes\Items;
use Solo\Input\Attributes\NoTrim;
use Solo\Input\Attributes\Source\FromBody;
use Solo\Input\Attributes\Source\FromContext;
use Solo\Input\Attributes\Source\FromRoute;
use Solo\Input\Attributes\Validate;
use Solo\Input\Cache\ReflectionCache;
use Solo\Input\Contracts\GeneratorInterface;
use Solo\Input\Attributes\Input;
use Solo\Input\Inputs;
use Solo\Input\Schema\SchemaBuilder;

final class SchemaBuilderTest extends TestCase
{
    private ReflectionCache $cache;

    protected function setUp(): void
    {
        $this->cache = new ReflectionCache();
    }

    /**
     * @param class-string $className
     * @return array<string, array<string, mixed>>
     */
    private function build(string $className): array
    {
        return (new SchemaBuilder($this->cache))->build($this->cache->get($className));
    }

    // ===== input hints and shared keys =====

    public function testInputHandlingTheTypeDoesNotShowIsReported(): void
    {
        $schema = $this->build(SchemaInputHintsInput::class);

        $this->assertSame('json', $schema['settings']['cast']);
        $this->assertSame('string', $schema['settings']['type']);
        $this->assertSame('decimal', $schema['rate']['cast']);
        $this->assertSame('float', $schema['amount']['cast']);
        $this->assertTrue($schema['note']['emptyToNull']);
        $this->assertTrue($schema['snippet']['noTrim']);

        foreach (['cast', 'emptyToNull', 'noTrim'] as $key) {
            $this->assertArrayNotHasKey($key, $schema['plain'], "{$key}: an int property cast as int says nothing new");
        }
    }

    public function testPropertiesSharingAnInputKeyAreAllDescribed(): void
    {
        $schema = $this->build(SchemaSharedKeyInput::class);

        $this->assertSame(['id'], array_keys($schema));
        $this->assertSame('id', $schema['id']['property']);
        $this->assertArrayHasKey('also', $schema['id']);
        $this->assertCount(1, $schema['id']['also']);
        $this->assertSame('productId', $schema['id']['also'][0]['property']);
        $this->assertContains(['name' => 'exists', 'args' => ['products', 'id']], $schema['id']['also'][0]['rules']);
        $this->assertArrayNotHasKey('also', $this->build(SchemaFixtureInput::class)['name']);
    }

    public function testAFieldWithoutADefaultIsRequired(): void
    {
        $schema = $this->build(SchemaRequiredInput::class);

        $this->assertTrue($schema['sku']['required'], 'no default: absent fails required');
        $this->assertTrue($schema['note']['required'], 'nullable, but still no default');
        $this->assertFalse($schema['stock']['required']);
        $this->assertSame(['hasDefault' => true, 'default' => 0], [
            'hasDefault' => $schema['stock']['hasDefault'],
            'default' => $schema['stock']['default'],
        ]);
    }
}

#[Input]
final readonly class SchemaRequiredInput
{
    public function __construct(
        public string $sku,
        public ?string $note,
        public int $stock = 0,
    ) {
    }
}

// ============================================================================
// Fixtures
// ============================================================================

#[Input]
final class SchemaFixtureInput
{
    public function __construct(
        #[FromRoute('product_id')]
        #[Validate('required|integer')]
        public int $id,
        #[Validate('required|string')]
        public string $name,
        public string $noRules,
        #[FromContext('tenantId')]
        public int $contextId,
        #[Generator(SchemaIdGenerator::class)]
        public string $uuid,
        #[Validate('nullable|string|max:255')]
        public ?string $nickname = null,
        #[FromBody('company.type')]
        #[Validate('nullable|in:person,company')]
        public ?string $companyType = null,
        #[Validate('nullable|string|required_if:companyType,company')]
        public ?string $companyName = null,
        #[Validate('nullable|in:a,b')]
        public ?string $status = null,
        #[Validate('integer|min_value:1')]
        public int $page = 1,
        #[Exclude]
        public string $internal = 'secret',
        /** @var array<int, array<string, mixed>>|null */
        #[Validate('nullable|array')]
        #[Items(SchemaLineInput::class)]
        public ?array $lines = null,
        #[DateFormat('Y-m-d')]
        public ?\DateTimeImmutable $day = null,
        public SchemaState $state = SchemaState::Draft,
        public ?SchemaAddressInput $address = null,
        #[Validate('nullable|required_if:onlyfield')]
        public ?string $conditionalBad = null,
    ) {
    }
}

#[Input]
final class SchemaLineInput
{
    public function __construct(
        #[Validate('required|string')]
        public string $sku,
    ) {
    }
}

#[Input]
final class SchemaAddressInput
{
    public function __construct(
        #[Validate('required|string')]
        public string $city,
    ) {
    }
}

enum SchemaState: string
{
    case Draft = 'draft';
    case Live = 'live';
}

final class SchemaIdGenerator implements GeneratorInterface
{
    public function generate(array $options = []): string
    {
        return 'id';
    }
}

#[Input]
final class SchemaSharedKeyInput
{
    public function __construct(
        #[FromRoute]
        #[Validate('required|integer')]
        public int $id,
        #[FromRoute('id')]
        #[Validate('required|exists:products,id')]
        public int $productId,
    ) {
    }
}

#[Input]
final class SchemaInputHintsInput
{
    public function __construct(
        #[Cast('json')]
        public ?string $settings = null,
        #[Cast('decimal')]
        public ?string $rate = null,
        #[Cast('float')]
        public int|float $amount = 0,
        #[EmptyToNull]
        public ?string $note = null,
        #[NoTrim]
        public string $snippet = '',
        public int $plain = 0,
    ) {
    }
}
