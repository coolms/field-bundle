# coolms/field-bundle

Symfony wiring for the field family: the bundle, its DI extension, the console
commands, form types, the override storage router, and the field-definition
API Platform resources.

```bash
composer require coolms/field-bundle:^2.0@alpha
```

Only pre-releases exist so far, hence the `@alpha` stability flag. Requiring
this pulls in `coolms/field` and `coolms/field-doctrine`, so it is the only one
of the three you name directly.

```php
// config/bundles.php
CoolMS\Field\Bundle\FieldBundle::class => ['all' => true],
```

## What the extension registers

- Doctrine mapping for `CoolMS\Field\Entity`, pointed at `coolms/field-doctrine`'s
  XML, on an entity manager named **`central`** -- the application needs
  DoctrineBundle with an entity manager of that name -- and
  `DefinitionInterface` resolved to `Definition`
- the definition repository, aliased to the domain's contract
- `coolms:field:definition:create`, `coolms:field:definition:list` and
  `coolms:field:prune-undeclared`
- the cache warmer that imports the fields declared in configuration
- the built-in form types and the override storage router
- the API Platform state provider and the create, update and delete processors of
  the definition resource

## The API resources need a service scan, for now

The resources -- `/field/definitions` (with `/{id}`, `/reorder` and the delete
by alias), `/field/form-types` and `/dynamic-entity/constraints` -- are **not**
registered by the extension. Since API Platform 4.1 a class carrying
`#[ApiResource]` is discovered by being an autoconfigured service, so with the
bundle alone these routes do not exist, and nothing reports it. Neither do the
constraint-metadata provider, the reorder and delete-by-alias processors, or the
constraint registry.

Until the extension registers them, register the package's classes yourself:

```yaml
# config/services.yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true

    CoolMS\Field\Bundle\:
        resource: '%kernel.project_dir%/vendor/coolms/field-bundle/src/'
        exclude:
            - '%kernel.project_dir%/vendor/coolms/field-bundle/src/FieldBundle.php'
            - '%kernel.project_dir%/vendor/coolms/field-bundle/src/DependencyInjection/'
```

## Fields declared in configuration

Fields declared in YAML, under `config/modules/{module}/fields/{alias}/`, are
imported into the definitions table by an optional cache warmer: it runs with
`cache:warmup` and `cache:clear`, after Doctrine's own warmer, and is skipped by
`--no-optional-warmers` and by a warm-up with no database. Rows created that way
carry a `system` marker, and the YAML is their source of truth: each warm-up
re-applies it. Rows created through the API carry no marker and are never
touched.

The warm-up never deletes. `coolms:field:prune-undeclared` removes a marked row
whose field its alias's YAML no longer declares; it is a dry run unless given
`--execute`, and under `--no-interaction` it also needs `--force`. It skips an
alias whose YAML is gone entirely, so those rows stay until something else
removes them -- the API's delete, for example.

## Where an override is written

`FieldOverrideStorageRouter` decides, per write: in the `dev` environment, for an
entity registered by class or alias, a YAML file, so the change can be committed
with the code; in every other case -- any other environment, or a type that
exists only at runtime -- the database.

## Requires

PHP 8.5, `api-platform/core`, `coolms/core`, `coolms/core-application`,
`coolms/core-bundle`, `coolms/entity`, `coolms/entity-bundle`, `coolms/field`,
`coolms/field-doctrine`, `psr/log`, and the Symfony config, console,
dependency-injection, form, http-foundation, http-kernel, security-bundle,
serializer, validator and yaml components. DoctrineBundle with a `central`
entity manager, as above.

## Contributing

See the CoolMS organization's
[contributing guide](https://github.com/coolms/.github/blob/develop/CONTRIBUTING.md).

## License

MIT. See [LICENSE](LICENSE).
