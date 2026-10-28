# Changelog

## 1.1.0 — Unreleased

An empty input means one thing: `null`, and a `""` the field's type cannot hold, are no value. The factory reads such a `""` as `null` before validation, the validator checks a `null` like a missing field — by presence rules alone — and the field holds `null`, keeps its default or reports `required`. Values, absent fields and `schema()` are unchanged. See the [upgrade guide](https://solophp.github.io/Input/guide/upgrading).

### Fixes

- `#[EmptyToNull]` gives what it documents for a field with rules: `?q=` for `#[EmptyToNull] #[Validate('string|max:100')] public ?string $q` is `null`, where it failed `string` although the client sent a string.
- A rule matching the type is harmless for no value, as documented: `?done=` for `#[Validate('boolean')] public ?bool $done` is `null`, `?page=` for `#[Validate('integer')] public int $page = 1` is `1`.

### Behavior changes

- A nullable field takes `null` without `nullable` in its rules, so in a partial update a `null` clears it. Keep it from being cleared with `filled`: `#[Validate('filled|string|max:255')] public ?string $name = null`.
- A field with a default keeps it for no value — in a partial update a `null` resets it; one with neither reports `required` instead of the first rule it failed.
- A `""` for an `array` or `#[Items]` field, or for a custom caster's array type, is no value instead of `[]`. Only a `[]` the client sends is the empty list.
- Rules of your own run on no value only as presence rules: `#[Validate('accepted')] public bool $terms = false` takes `false` for `null`. Drop the default and write `required|accepted`.
- A `null` in `#[FromContext]` is no value too: `null`, the default, or `UnexpectedValueException`.
- The validator gets a `""` the type cannot hold as `null`; rule strings reach it as written, and a `nullable` you write keeps working.
- Requires `solophp/contracts` ^1.8: its `ValidatorInterface` checks a missing or `null` field by presence rules alone, and it conflicts with `solophp/validator` below 4.2, which does not.

Earlier releases are described in [GitHub Releases](https://github.com/SoloPHP/Input/releases).
