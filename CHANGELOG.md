# Changelog

All notable changes to `coolms/field-bundle` are recorded here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning is described in the [CoolMS contributing guide](https://github.com/coolms/.github/blob/develop/CONTRIBUTING.md)
-- read it before assuming what a major number means here.

Every entry in this file was written in the same commit as the change it
describes. Nothing here is reconstructed.

## Unreleased

### Fixed

- The extension registers the API surface itself: the three resources, every
  state provider and processor they name (the reorder and delete-by-alias
  processors and the constraint-metadata provider among them, which nothing
  registered), the label writer the processors use and the constraint registry.
  An application that installs the bundle serves `/field/definitions`,
  `/field/form-types` and `/dynamic-entity/constraints` without a service scan of
  its own; the README section that asked for one is gone.
- The built-in form-type provider carries its tag by name. It was tagged only by
  an application's scan, so without one the form-type list came back empty.
- Constraint-metadata providers are collected by autoconfiguration, as the
  form-type and widget providers are.
- A test kernel with only the bundles this package requires, and nothing of the
  application's own, serves each route.
- `ext-simplexml` is required: Doctrine reads the entity mapping this bundle
  registers as XML, and the extension it needs was declared by nothing.

### Added

- README.md: what the extension registers, how fields declared in configuration
  are imported and pruned, where an override is written, and what it requires.

## 2.0.0-alpha2 - 2026-10-07

### Changed

- **A cache warm-up no longer deletes field definitions.**
  `FieldDefinitionSyncWarmer` used to delete, on every warm-up, the rows it had
  marked (`options.system`) that no YAML declares any more. Warm-up runs on
  every rebuild, and a warmer is seen by nothing that holds destructive
  commands to their rule. The warmer now only refreshes the rows it owns; a row
  whose YAML is gone stays until `coolms:field:prune-undeclared` deletes it.

### Added

- `coolms:field:prune-undeclared` and `Maintenance\UndeclaredDefinitionPruner`:
  the deletion the warmer ran, under the destructive-command rule -- a dry run
  unless `--execute`, a refusal under `--no-interaction` without `--force`, and
  "N of M field definition(s)" in every report. The rule is the warmer's,
  unchanged: only marked rows, never for an alias that declares nothing, never
  a native column's name.

### Security

- `options.system` is no longer client-writable through the field-definition
  API. It is `FieldDefinitionSyncWarmer`'s ownership marker: a row carrying it
  is re-applied from YAML on every warm-up and **deleted** once no YAML declares
  it. `DefinitionResource::applyTo()` copied the request's `options` bag onto
  the row whole, so a create or an update could set the marker -- scheduling
  that field's deletion for the next `cache:warmup` -- or clear it on a
  YAML-declared field and take the field out of its YAML's hands. The row now
  keeps the marker it had, whatever the body says, and a new row has none. The
  rest of the bag is written as before.

### Added

- Declares `support` -- `issues` and `source` -- so a page imported from this
  package, and the catalogue, know where a correction is filed. Packagist filled
  the gap from GitHub when the manifest was silent; the declared field is the
  one that holds on any registry.

Tests the application had been carrying for this package since the code
moved here: `FieldMetadataRegistryTest`. Nothing under `src/` changes.

## 2.0.0-alpha1 - 2026-09-10

**A pre-release. It carries no compatibility promise**, which is the honest
statement of where the platform is: the shape is still moving, and a stable tag
would be a promise that cannot be kept yet.

Composer will not install it under default stability. Set

```json
"minimum-stability": "alpha",
"prefer-stable": true
```

in your root `composer.json`, then:

```
composer require coolms/field-bundle:^2.0
```

`prefer-stable` keeps every other dependency of yours on its newest stable
release, so this loosening applies to what actually needs it and nothing else.

!! **A per-package stability flag is not enough.** `composer require
coolms/field-bundle:^2.0@alpha` admits the alpha of the package it names and **nothing
behind it**, so the siblings it pulls in still fail to resolve -- and composer
reports that against the sibling rather than against what you asked for.

### Added

**The Symfony integration for `coolms/field`.** Twenty-nine classes, all of them
the framework half: the bundle and its extension, the compiler pass that builds
the metadata registry, the HTTP API resources with their providers and
processors, two console commands, the form-type provider and the Form bridge,
the constraint registry, the cache warmer, and the override storage router with
its file and database ends.

The extension's `prepend()` is what makes `coolms/field-doctrine` reachable: it
registers that package's `src/mapping` directory with the ORM as an external
mapping source, so a host installing both gets the entity mapped without
configuring anything.

**Seven tests arrive with the code they cover** -- the provider, the cache
warmer, the config provider, the schema source, the form meta loader, the
reflection reader and the translation writer -- tested here rather than in the
application that used to contain them. Two carried an application import and
could not have travelled as they were; neither turned out to be a real
dependency, and two fixtures replace what tied them.

### Fixed before it shipped

`psr/log`, `symfony/http-foundation`, `symfony/security-bundle`,
`symfony/serializer` and `symfony/yaml` were named by imports in `src/` and
absent from the require block. Every one was installed here because some other
package asked for it -- the sole-holder shape, one level out from the
application.

Four docblocks explained fully-qualified class-name aliases using a class from
the private application this package was extracted from. The example is useful
and the class is not: **a published package must not name the code that consumes
it.** A plain `Vendor\Blog\...` placeholder carries the same meaning.

### Changed

The namespace nests under the domain root: `CoolMS\FieldBundle\` becomes
`CoolMS\Field\Bundle\`. A suffix names a superstructure and the absence of one
names the subject, so the domain package keeps the root prefix and everything
laid over it carries a segment equal to its suffix. `coolms/field-doctrine` has
declared `CoolMS\Field\Doctrine\` since it was created, so this brings the
third package into line with the second rather than inventing an arrangement.

The class name is deliberately unchanged: `FieldBundle` in namespace
`CoolMS\Field\Bundle` is redundant, and renaming it is a separate change.

This package also follows the tier below it from `-app` to `-application`, and
`CoolMS\CoreBundle\` and `CoolMS\EntityBundle\` to their nested forms.
