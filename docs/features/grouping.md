# Field Grouping

Group related fields with `#[Group]` and extract them as a single array with `Inputs::group()`.

## Basic Usage

```php
use Solo\Input\Attributes\{Group, Input};
use Solo\Input\Inputs;

#[Input]
final readonly class SearchInput
{
    public function __construct(
        #[Group('criteria')]
        public ?string $search = null,
        #[Group('criteria')]
        public ?string $status = null,
        #[Group('pagination')]
        public int $page = 1,
        #[Group('pagination')]
        public int $perPage = 20,
    ) {
    }
}

$input = $factory->fromQuery(SearchInput::class, $request);

$criteria = Inputs::group($input, 'criteria');
// ['search' => '...', 'status' => '...']

$pagination = Inputs::group($input, 'pagination');
// ['page' => 1, 'perPage' => 20]
```

---

## Flattening Behavior

`Inputs::group()` reads the group's fields in declaration order and returns a flat array:

- **Associative array fields**: Contents are merged by their own keys, preserving their order and nested values
- **Other values** — scalars, sequential arrays and objects: Added by field name (or by `mapTo` if specified)
- **Empty arrays and `null` values**: Skipped entirely
- **`false`, `0` and `""`**: Included as ordinary values

Only the field's own `null` value is skipped. A `null` entry inside an associative array is preserved and its key participates in duplicate detection.

Only arrays are merged. An object is one value even when it is iterable: a date, a value object or a repository filter is added under the field name like a scalar.

```php
#[Input]
final readonly class FilterInput
{
    /**
     * @param array<string, mixed> $search
     * @param array<string, mixed> $filters
     * @param list<string>         $statuses
     */
    public function __construct(
        #[Group('criteria')]
        public array $search = [],
        #[Group('criteria')]
        public array $filters = [],
        #[Group('criteria')]
        public int $limit = 10,
        #[Group('criteria')]
        public array $statuses = [],
    ) {
    }
}

$input = new FilterInput(
    search: ['name LIKE' => '%test%'],
    filters: ['status' => 'active'],
    limit: 20,
    statuses: ['pending', 'paid'],
);

Inputs::group($input, 'criteria');
// [
//     'name LIKE' => '%test%',                  // associative array — merged by keys
//     'status' => 'active',                     // associative array — merged by keys
//     'limit' => 20,                            // scalar — by field name
//     'statuses' => ['pending', 'paid'],        // sequential array — by field name
// ]
// An empty array (search: []) would be skipped entirely
```

---

## Key Remapping with `mapTo`

Use the `mapTo:` named argument on `#[Group]` to change the output key. Useful when the field name differs from the desired output key — a database column name, or a criteria key that also carries an operator:

```php
#[Input]
final readonly class ProductFilterInput
{
    /**
     * @param list<string> $statuses
     */
    public function __construct(
        #[Validate('integer')]
        #[Group('criteria', mapTo: 'category_id')]
        public ?int $category = null,
        #[Validate('numeric')]
        #[Group('criteria', mapTo: 'price >=')]
        public ?string $min_price = null,
        #[Validate('array')]
        #[Group('criteria', mapTo: 'status IN')]
        public array $statuses = [],
    ) {
    }
}

// ?category=5&min_price=50.00&statuses[]=active&statuses[]=draft
Inputs::group($input, 'criteria');
// [
//     'category_id' => 5,
//     'price >=' => '50.00',
//     'status IN' => ['active', 'draft'],
// ]
```

The key is a fixed string in your code: the client chooses values, never keys. See [Repository Criteria](#repository-criteria).

::: info
`mapTo` affects every value added by field name — scalars, sequential arrays and objects. Associative array fields are always merged by their own keys. `Inputs::toArray()` is not affected by `mapTo`.
:::

---

## Duplicate Key Protection

A `LogicException` is thrown if duplicate keys are detected:

```php
#[Input]
final readonly class ConflictInput
{
    /**
     * @param array<string, mixed> $first
     * @param array<string, mixed> $second
     */
    public function __construct(
        #[Group('data')]
        public array $first = [],
        #[Group('data')]
        public array $second = [],
    ) {
    }
}

Inputs::group(new ConflictInput(first: ['name' => 'Alice'], second: ['name' => 'Bob']), 'data');
// LogicException: Duplicate key 'name' in group 'data' from field 'second'
```

No key is exempt. Two conditions on one field differ in their keys — `price >=` and `price <=` — and do not collide.

---

## Defaults Belong to the Group

A field's default is its value like any other: a group includes it unless it is `null` or an empty array. Fields do not have to be listed in `Inputs::provided()` to belong to a group.

```php
// ?search=test — `page` keeps its default
Inputs::group($input, 'pagination');
// ['page' => 1, 'perPage' => 20]
```

---

## Non-Existent Groups

Returns empty array for groups with no matching fields:

```php
Inputs::group($input, 'nonexistent');
// []
```

---

## Repository Criteria

`group()` belongs to no particular repository: it collects values. [Solo Repository criteria](https://github.com/SoloPHP/Repository/blob/main/docs/guide/api.md#criteria-and-filter) are an associative array of `'field operator'` keys combined with AND, plus `Filter` objects for what one key cannot say — OR groups, negation and relation predicates. A criteria group feeds them like this:

- A property holding one condition keeps its name for equality or takes a fixed key from `mapTo`: `'price >='`, `'status IN'`.
- A processor producing several conditions returns them as an associative array: `['created_at >=' => $from, 'created_at <=' => $to]`.
- A processor producing an OR group, a negation or a relation predicate returns a `Filter`, such as `Filter::related('tags', ['id' => $id])`. The property is typed `?Filter`, and the filter is added under the property name.

```php
use Solo\Repository\Filter;
use Solo\Input\Attributes\{Caster, Group, Validate};
use Solo\Input\Contracts\CasterInterface;
use Solo\Input\ProcessContext;
use Solo\Input\Attributes\Input;

/**
 * Every word must match one of the configured fields.
 *
 * @implements CasterInterface<Filter|null>
 */
final class KeywordSearch implements CasterInterface
{
    public function cast(mixed $value, ProcessContext $context): ?Filter
    {
        $words = preg_split('/\s+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words === []) {
            return null;
        }

        return Filter::all(...array_map(
            static fn(string $word): Filter => Filter::any(...array_map(
                static fn(string $field): array => [$field . ' LIKE' => "%{$word}%"],
                $context->config['fields'],
            )),
            $words,
        ));
    }
}

#[Input]
final class OrderIndexInput
{
    public function __construct(
        #[Validate('integer')]
        #[Group('criteria')]
        public int $supplier_id,
        /** @var list<string> */
        #[Validate('array')]
        #[Group('criteria', mapTo: 'status IN')]
        public array $status = [],
        #[Validate('string|max:255')]
        #[Caster(KeywordSearch::class, config: ['fields' => ['number', 'note']])]
        #[Group('criteria')]
        public ?Filter $q = null,
    ) {
    }
}

// ?supplier_id=7&status[]=paid&q=chair
Inputs::group($dto, 'criteria');
// [
//     'supplier_id' => 7,
//     'status IN' => ['paid'],
//     'q' => Filter,  // all(any(['number LIKE' => '%chair%'], ['note LIKE' => '%chair%']))
// ]
```

A criteria array cannot hold a `Filter`, so the application joins the group before the query: the conditions stay one array, and each filter is added through `Filter::all()`. A filter's key in the group only names it.

```php
use Solo\Input\Inputs;
use Solo\Repository\Filter;

final class Criteria
{
    /** @return array<string, mixed>|Filter */
    public static function of(object $input, string $group = 'criteria'): array|Filter
    {
        $conditions = [];
        $filters = [];
        foreach (Inputs::group($input, $group) as $key => $value) {
            if ($value instanceof Filter) {
                $filters[] = $value;
            } else {
                $conditions[$key] = $value;
            }
        }

        return $filters === [] ? $conditions : Filter::all($conditions, ...$filters);
    }
}

$orders = $repository->read()->findBy(Criteria::of($dto), ['id' => 'DESC'], limit: 20);

// Conditions the application adds join the same way:
$own = Filter::all(Criteria::of($dto), ['customer_id' => $customerId]);
```

::: warning Keys come from code
A criteria key chooses the field and the operator, so build every key from fixed strings: `mapTo`, a processor's literals or its `config`. An associative array is merged by its keys as they are — never copy request keys into one, or `filter[price >=]=0` turns an equality into a comparison. When users choose the fields to filter by, map each allowed name to a fixed key.
:::

Solo Repository checks each value against its field type before SQL and throws `InvalidArgumentException` for one such as `'12x'` for an integer. Type filter fields like the columns they filter — `int`, a decimal string, a date — so malformed input is a validation error before it reaches the repository.

---

## Performance

Group metadata is cached per class. Multiple calls to `Inputs::group()` reuse cached field lists, but read current DTO values on every call. Group results are not cached, and building a group does not modify the DTO:

```php
$filters = Inputs::group($dto, 'criteria');
$filters = Inputs::group($dto, 'criteria'); // reuses cache
```

### Clearing Cache

`Inputs::clearCache()` is provided as a test helper for isolating cases within a single PHP process. Under PHP-FPM you don't need to call it — the cache dies with the request.

```php
Inputs::clearCache();
Inputs::clearCache(SearchInput::class);
```

---

## Practical Examples

### Search Filters

```php
#[Input]
final class ProductSearchInput
{
    public function __construct(
        #[Group('filters')]
        public ?string $query = null,
        #[Group('filters')]
        public ?string $category = null,
        #[Group('filters')]
        public ?float $minPrice = null,
        #[Group('filters')]
        public ?float $maxPrice = null,
        #[Group('sorting')]
        public string $sortBy = 'created_at',
        #[Group('sorting')]
        public string $sortDir = 'DESC',
        #[Group('pagination')]
        public int $page = 1,
        #[Group('pagination')]
        public int $limit = 20,
    ) {
    }
}

$filters = Inputs::group($dto, 'filters');
$sorting = Inputs::group($dto, 'sorting');
$pagination = Inputs::group($dto, 'pagination');

$products = $repository->search($filters, $sorting, $pagination);
```

### API Response Options

```php
#[Input]
final class ApiInput
{
    public function __construct(
        #[Validate('required|integer')]
        public int $resourceId,
        #[Group('options')]
        public bool $includeRelations = false,
        #[Group('options')]
        public bool $includeMeta = false,
        #[Group('options')]
        public ?string $fields = null,
    ) {
    }
}

$options = Inputs::group($dto, 'options');
// ['includeRelations' => true, 'includeMeta' => false, 'fields' => 'id,name']
```

### Query Builder Integration

```php
#[Input]
final class UserListInput
{
    public function __construct(
        #[Group('where')]
        public array $filters = [],
        #[Group('where')]
        public array $search = [],
        #[Group('order')]
        public string $orderBy = 'id',
        #[Group('order')]
        public string $orderDir = 'ASC',
    ) {
    }
}

$query = $userRepository->query();

foreach (Inputs::group($dto, 'where') as $column => $value) {
    $query->where($column, $value);
}

$order = Inputs::group($dto, 'order');
$query->orderBy($order['orderBy'], $order['orderDir']);
```

---

## Combining with Other Attributes

Groups compose with the rest of the attribute family:

```php
use Solo\Input\Attributes\{Validate, PostProcess, Generator, Group, Exclude};

#[Input]
final class ComplexInput
{
    public function __construct(
        #[Generator(TimestampGenerator::class)]
        #[Group('meta')]
        #[Exclude]
        public int $requestedAt,
        #[Validate('nullable|string')]
        // Strings are auto-trimmed by default.
        #[Group('search')]
        public ?string $query = null,
        #[Validate('in:asc,desc')]
        #[PostProcess(UppercaseProcessor::class)]
        #[Group('sorting')]
        public string $direction = 'asc',
    ) {
    }
}
```

The `UppercaseProcessor` in the example implements the typed post-processing contract:

```php
use Solo\Input\Contracts\PostProcessorInterface;
use Solo\Input\ProcessContext;

/** @implements PostProcessorInterface<string> */
final class UppercaseProcessor implements PostProcessorInterface
{
    public function postProcess(mixed $value, ProcessContext $context): string
    {
        return strtoupper($value);
    }
}
```
