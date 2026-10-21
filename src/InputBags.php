<?php

declare(strict_types=1);

namespace Solo\Input;

use Psr\Http\Message\ServerRequestInterface;
use Solo\Input\Cache\PropertyMetadata;

/**
 * Immutable per-request snapshot of the four input bags the handler reads from.
 *
 * Each bag corresponds to one `FieldSource`. Without an explicit source
 * attribute the handler falls back to a method-level default (Body for
 * `fromBody`/`fromArray`, Query for `fromQuery`). `#[FromRoute]` and
 * `#[FromContext]` deviate from the default to read from the `$route` /
 * `$context` arguments passed to `from*()`.
 *
 * @internal
 */
final readonly class InputBags
{
    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $query
     * @param array<string, mixed> $route
     * @param array<string, mixed> $context
     */
    public function __construct(
        public array $body,
        public array $query,
        public array $route,
        public array $context,
    ) {
    }

    /**
     * Build bags from a PSR-7 request plus app-supplied route/context.
     *
     * PSR-7's `getParsedBody()` may legitimately return an object — typically a
     * `stdClass` produced by JSON middleware that calls `json_decode($json)`
     * without `assoc=true`. We normalise via a JSON round-trip so nested
     * stdClass children become associative arrays too; bare arrays pass through
     * untouched; anything else is treated as an empty body.
     *
     * @param array<string, mixed> $route
     * @param array<string, mixed> $context
     */
    public static function fromRequest(
        ServerRequestInterface $request,
        array $route = [],
        array $context = [],
    ): self {
        return new self(
            body:    self::normaliseBody($request->getParsedBody()),
            query:   $request->getQueryParams(),
            route:   $route,
            context: $context,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function normaliseBody(mixed $body): array
    {
        if (is_array($body)) {
            return $body;
        }
        if (is_object($body)) {
            $decoded = json_decode(json_encode($body, JSON_THROW_ON_ERROR), true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    /**
     * Build bags from a raw array (acts as the body bag). Query is empty;
     * route/context as passed.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $route
     * @param array<string, mixed> $context
     */
    public static function fromArray(array $data, array $route = [], array $context = []): self
    {
        return new self(
            body:    $data,
            query:   [],
            route:   $route,
            context: $context,
        );
    }

    /**
     * Build bags for nested item processing.
     *
     * Only the item's own data and the outer context are propagated. Route is
     * deliberately dropped — it describes the OUTER resource and must not leak
     * into per-item fields (a route key `id` must not silently overwrite an
     * item's own `id`).
     *
     * @param array<string, mixed> $itemData
     * @param array<string, mixed> $context
     */
    public static function forItem(array $itemData, array $context = []): self
    {
        return self::fromArray($itemData, route: [], context: $context);
    }

    /**
     * Look up a property's value from its declared source.
     *
     * `$defaultSource` is used when the property has no source attribute
     * (and therefore `PropertyMetadata::$source` is null).
     *
     * When `PropertyMetadata::$pathSegments` is non-null (set by
     * `#[FromBody('a.b.c')]`), the bag is walked segment by segment; any
     * missing key or non-array intermediate counts as "value absent".
     */
    public function lookup(PropertyMetadata $property, FieldSource $defaultSource, bool &$hasValue): mixed
    {
        $source = $property->source ?? $defaultSource;

        $bag = match ($source) {
            FieldSource::Body    => $this->body,
            FieldSource::Query   => $this->query,
            FieldSource::Route   => $this->route,
            FieldSource::Context => $this->context,
        };

        if ($property->pathSegments !== null) {
            return $this->walkPath($bag, $property->pathSegments, $hasValue);
        }

        $key = $property->sourceKey;

        if (!array_key_exists($key, $bag)) {
            $hasValue = false;
            return null;
        }

        $hasValue = true;
        return $bag[$key];
    }

    /**
     * Traverse nested arrays by dot-path segments. A missing key OR a
     * non-array intermediate stops traversal and marks the value absent.
     *
     * @param array<string, mixed> $bag
     * @param list<string>         $segments
     */
    private function walkPath(array $bag, array $segments, bool &$hasValue): mixed
    {
        $current = $bag;
        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                $hasValue = false;
                return null;
            }
            $current = $current[$segment];
        }
        $hasValue = true;
        return $current;
    }
}
