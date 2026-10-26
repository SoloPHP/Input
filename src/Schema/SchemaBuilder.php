<?php

declare(strict_types=1);

namespace Solo\Input\Schema;

use BackedEnum;
use Solo\Input\Cache\PropertyMetadata;
use Solo\Input\Cache\ReflectionCache;
use Solo\Input\Cache\InputMetadata;
use Solo\Input\RuleParser;
use UnitEnum;

/**
 * Builds the JSON-serializable input contract of an input class.
 *
 * Fields are keyed by their input key — the key the client sends and the
 * key `ValidationException` reports errors under — and describe only what
 * the client sends: generated properties and `#[FromContext]` values are left
 * out. Nested DTOs (`fields`) and `#[Items]` classes (`items`) are described
 * inline; a class that recurs on its own nesting path is referenced as
 * `['$ref' => ClassName]` instead. When several properties read one input
 * key, the first describes it and the others follow under `also`.
 *
 * Every value emitted is composed solely of scalars and arrays: no
 * `ReflectionProperty`, enums, objects, or closures leak through, so the
 * result round-trips losslessly through `json_encode`/`json_decode`.
 *
 * Validation rules are parsed by the shared `RuleParser`, the single source of
 * truth for rule-string split semantics (also used by `MetadataBuilder`).
 *
 * @phpstan-type NormalizedRule array{name: string, args: list<string>}
 * @phpstan-type RequiredIf array{field: string, value: string}
 * @phpstan-type FieldSchema array{
 *     property: string,
 *     source: ?string,
 *     type: ?string,
 *     nullable: bool,
 *     required: bool,
 *     requiredIf: RequiredIf|null,
 *     hasDefault: bool,
 *     default: mixed,
 *     rules: list<NormalizedRule>,
 *     format?: string,
 *     cases?: list<int|string>,
 *     cast?: string,
 *     emptyToNull?: true,
 *     noTrim?: true,
 *     fields?: array<string, mixed>,
 *     items?: array<string, mixed>,
 *     also?: list<array<string, mixed>>,
 * }
 *
 * @internal Use `InputFactory::schema()`.
 */
final readonly class SchemaBuilder
{
    public function __construct(
        private ReflectionCache $cache,
    ) {
    }

    /**
     * @return array<string, FieldSchema>
     */
    public function build(InputMetadata $metadata): array
    {
        return $this->describe($metadata, [$metadata->className => true]);
    }

    /**
     * @param array<string, true> $path Classes on the current nesting path.
     * @return array<string, FieldSchema>
     */
    private function describe(InputMetadata $metadata, array $path): array
    {
        $inputKeys = array_column($metadata->properties, 'sourceKey', 'name');

        $schema = [];
        foreach ($metadata->properties as $property) {
            if ($property->appSupplied) {
                continue;
            }

            $field = $this->describeField($property, $inputKeys, $path);

            // Several properties may read one input key — the client has to
            // satisfy them all, so none is dropped.
            if (isset($schema[$property->sourceKey])) {
                $schema[$property->sourceKey]['also'][] = $field;
            } else {
                $schema[$property->sourceKey] = $field;
            }
        }

        return $schema;
    }

    /**
     * @param array<string, string> $inputKeys Property name → input key.
     * @param array<string, true>   $path
     * @return FieldSchema
     */
    private function describeField(PropertyMetadata $property, array $inputKeys, array $path): array
    {
        $rules = RuleParser::parse($property->validationRules);

        $field = [
            'property'   => $property->name,
            'source'     => $property->source?->value,
            'type'       => $property->type,
            'nullable'   => $property->isNullable,
            // A field without a default fails `required` when absent, whatever its rules.
            'required'   => $property->isRequired || !$property->hasDefault,
            'requiredIf' => $this->extractRequiredIf($rules, $inputKeys),
            'hasDefault' => $property->hasDefault,
            'default'    => $this->export($property->defaultValue),
            'rules'      => $rules,
        ];

        if ($property->dateFormat !== null) {
            $field['format'] = $property->dateFormat;
        }
        if ($property->enumClass !== null) {
            $field['cases'] = array_column($property->enumClass::cases(), 'value');
        }
        // What the client may send when the type does not say it: any JSON
        // value for #[Cast('json')], a number for #[Cast('decimal')], a float
        // for #[Cast('float')] on int|float.
        if ($property->effectiveCastType !== null && $property->effectiveCastType !== $property->type) {
            $field['cast'] = $property->effectiveCastType;
        }
        if ($property->emptyToNull) {
            $field['emptyToNull'] = true;
        }
        if ($property->noTrim) {
            $field['noTrim'] = true;
        }
        if ($property->nested !== null) {
            $field['fields'] = $this->describeChild($property->nested, $path);
        }
        if ($property->items !== null) {
            $field['items'] = $this->describeChild($property->items, $path);
        }

        return $field;
    }

    /**
     * @param class-string        $class
     * @param array<string, true> $path
     * @return array<string, mixed>
     */
    private function describeChild(string $class, array $path): array
    {
        if (isset($path[$class])) {
            return ['$ref' => $class];
        }

        return $this->describe($this->cache->get($class), $path + [$class => true]);
    }

    /**
     * Extract the first well-formed `required_if` as `{field, value}`, with
     * `field` mapped to the referenced property's input key. Null when there
     * is none — a `required_if` missing an argument is treated as absent.
     *
     * @param list<NormalizedRule>  $rules
     * @param array<string, string> $inputKeys
     * @return RequiredIf|null
     */
    private function extractRequiredIf(array $rules, array $inputKeys): ?array
    {
        foreach ($rules as $rule) {
            if ($rule['name'] !== 'required_if') {
                continue;
            }

            if (!isset($rule['args'][0], $rule['args'][1])) {
                return null;
            }

            return ['field' => $inputKeys[$rule['args'][0]] ?? $rule['args'][0], 'value' => $rule['args'][1]];
        }

        return null;
    }

    /**
     * A default value in JSON terms: enum cases become their value / name.
     */
    private function export(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            is_array($value) => array_map($this->export(...), $value),
            default => $value,
        };
    }
}
