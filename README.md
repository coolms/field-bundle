# coolms/field-bundle

Symfony wiring for the field family: the bundle, its DI extension, the
field-definition API Platform surface, three console commands, form types, the
validation registry and the override storage router.

```bash
composer require coolms/field-bundle
```

Requiring this pulls in `coolms/field` and `coolms/field-doctrine`, so it is the
only one of the three you name directly.

```php
// config/bundles.php
CoolMS\Field\Bundle\FieldBundle::class => ['all' => true],
```

## What it wires

- Doctrine mapping for `CoolMS\Field\Entity` on the `central` entity manager,
  pointed at `coolms/field-doctrine`'s XML, and `DefinitionInterface` resolved to
  `Definition`
- the definition repository, aliased to the domain's contract
- API Platform resources at `/field/definitions` (with `/{id}` and `/reorder`),
  `/field/form-types` and `/dynamic-entity/constraints`
- `coolms:field:definition:create`, `coolms:field:definition:list` and
  `coolms:field:prune-undeclared`
- a cache warmer that imports the fields declared in configuration
- the built-in form types, the validation constraint registry and the override
  storage router

## Fields declared in configuration

Fields declared in YAML, under `config/modules/{module}/fields/{alias}/`, are
imported into the definitions table on every `cache:warmup`. Rows created that
way carry a `system` marker, and the YAML is their source of truth: each warm-up
re-applies it. Rows created through the API carry no marker and are never
touched.

The warm-up never deletes. A row whose YAML is gone stays until
`coolms:field:prune-undeclared` removes it, and that command is a dry run unless
given `--execute`.

## Where an override is written

`FieldOverrideStorageRouter` decides, per write:

- in `dev`, for an entity registered by class or alias: a YAML file, so the
  change can be committed with the code;
- in `prod`, or for a type that exists only at runtime: the database.

## Requires

`api-platform/core`, `coolms/core`, `coolms/core-application`,
`coolms/core-bundle`, `coolms/entity`, `coolms/entity-bundle`, `coolms/field`,
`coolms/field-doctrine`, `psr/log`, and the Symfony config, console,
dependency-injection, form, http-foundation, http-kernel, security-bundle,
serializer, validator and yaml components.

## Contributing

See the CoolMS organization's
[contributing guide](https://github.com/coolms/.github/blob/develop/CONTRIBUTING.md).

## License

MIT. See [LICENSE](LICENSE).
