# Generators

Generators automatically create field values. Useful for UUIDs, sequences, and computed IDs. Declared via the `#[Generator]` attribute.

## Basic Usage

```php
use Solo\Input\Attributes\Generator;
```


```php
use Solo\Input\Contracts\GeneratorInterface;

final class UuidGenerator implements GeneratorInterface
{
    public function generate(array $options = []): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}

// Usage
#[Generator(UuidGenerator::class)]
public string $id,
```

## GeneratorInterface

```php
interface GeneratorInterface
{
    /**
     * @param array<string, mixed> $options
     * @return mixed
     */
    public function generate(array $options = []): mixed;
}
```

---

## Generator Options

Pass options via the `options:` named argument:

```php
#[Generator(SequenceGenerator::class, options: ['table' => 'orders', 'prefix' => 'ORD'])]
public string $orderNumber,
```

Access options in generator:

```php
final class SequenceGenerator implements GeneratorInterface
{
    public function generate(array $options = []): string
    {
        $table = $options['table'] ?? 'default';
        $prefix = $options['prefix'] ?? '';
        $id = $this->getNextId($table);
        
        return $prefix ? "{$prefix}-{$id}" : (string) $id;
    }
}
```

---

## Generators with Dependencies

Register generator instances for dependency injection:

```php
final class DatabaseSequenceGenerator implements GeneratorInterface
{
    public function __construct(
        private readonly Connection $connection
    ) {}

    public function generate(array $options = []): int
    {
        $table = $options['table'] ?? 'sequences';
        $column = $options['column'] ?? 'id';
        
        $result = $this->connection->executeQuery(
            "SELECT MAX({$column}) + 1 FROM {$table}"
        );
        
        return (int) $result->fetchOne();
    }
}

// Built by the PSR-11 container passed to InputFactory — or registered:
$factory->register(
    DatabaseSequenceGenerator::class,
    new DatabaseSequenceGenerator($connection)
);
```

---

## Behavior

Finalization runs bottom-up: complete children, run the current node's post-processors, run its generators, then populate the DTO. Parent post-processors can read generated fields on child DTOs. A child generator can run before a parent's post-processor. A later application error does not roll back earlier generators.

Generators cannot reject input. Throwing `FieldValidationException` or `ValidationException` here raises `LogicException` with the original exception as its cause; move rejection to a normalizer, validator or caster.

When a field has `#[Generator]`:

1. **Any request value is ignored** — A new value is always generated
2. **No validation** — Generated values bypass validation rules
3. **Runs after validation** — Generators fire only once the whole input, nested items included, is valid; input validation errors never consume a sequence value
4. **Type-driven cast** — The value is cast to the declared property type (an int becomes `'42'` for a `string` property)

```php
#[Generator(UuidGenerator::class)]
public string $id,

// Even if request contains {"id": "custom-id"},
// the generated UUID will be used instead
```

---

## Practical Examples

### UUID v4

```php
final class Uuid4Generator implements GeneratorInterface
{
    public function generate(array $options = []): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
```

### Prefixed ID

```php
final class PrefixedIdGenerator implements GeneratorInterface
{
    public function generate(array $options = []): string
    {
        $prefix = $options['prefix'] ?? 'ID';
        $timestamp = time();
        $random = mt_rand(1000, 9999);
        
        return "{$prefix}-{$timestamp}-{$random}";
    }
}

#[Generator(PrefixedIdGenerator::class, options: ['prefix' => 'ORD'])]
public string $orderId,
// "ORD-1705276800-4521"
```

### ULID (Time-sortable)

```php
final class UlidGenerator implements GeneratorInterface
{
    private const ENCODING = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public function generate(array $options = []): string
    {
        $time = (int) (microtime(true) * 1000);
        $ulid = '';
        
        // Encode timestamp (10 chars)
        for ($i = 9; $i >= 0; $i--) {
            $ulid = self::ENCODING[$time % 32] . $ulid;
            $time = (int) ($time / 32);
        }
        
        // Add randomness (16 chars)
        for ($i = 0; $i < 16; $i++) {
            $ulid .= self::ENCODING[random_int(0, 31)];
        }
        
        return $ulid;
    }
}
```

### Timestamp

```php
final class TimestampGenerator implements GeneratorInterface
{
    public function generate(array $options = []): int
    {
        return time();
    }
}

#[Generator(TimestampGenerator::class)]
public int $createdAt,
```

### Random Token

```php
final class TokenGenerator implements GeneratorInterface
{
    public function generate(array $options = []): string
    {
        $length = $options['length'] ?? 32;
        return bin2hex(random_bytes($length / 2));
    }
}

#[Generator(TokenGenerator::class, options: ['length' => 64])]
public string $apiToken,
```

---

## Using with Libraries

### ramsey/uuid

```php
use Ramsey\Uuid\Uuid;

final class RamseyUuidGenerator implements GeneratorInterface
{
    public function generate(array $options = []): string
    {
        $version = $options['version'] ?? 4;
        
        return match ($version) {
            1 => Uuid::uuid1()->toString(),
            4 => Uuid::uuid4()->toString(),
            7 => Uuid::uuid7()->toString(),
            default => Uuid::uuid4()->toString(),
        };
    }
}
```

### symfony/uid

```php
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\Ulid;

final class SymfonyUidGenerator implements GeneratorInterface
{
    public function generate(array $options = []): string
    {
        $type = $options['type'] ?? 'uuid4';
        
        return match ($type) {
            'uuid4' => Uuid::v4()->toRfc4122(),
            'uuid7' => Uuid::v7()->toRfc4122(),
            'ulid' => (new Ulid())->toBase32(),
            default => Uuid::v4()->toRfc4122(),
        };
    }
}
```

---

## Configuration Validation

Invalid generators throw `ConfigurationException`:

```php
// ❌ Error: class does not exist
#[Generator('NonExistentGenerator')]
public string $id,

// ❌ Error: must implement GeneratorInterface
#[Generator(SomeClass::class)]
public string $id,
```

An abstract generator or an interface is valid configuration: the container or `register()` supplies the instance, and only a first use without one throws `ConfigurationException::handlerNotInstantiable`.
