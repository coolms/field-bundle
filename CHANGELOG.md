# Changelog

All notable changes to `coolms/field-bundle` are recorded here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning is described in `CONTRIBUTING.md` -- read it before assuming what a
major number means here.

Every entry in this file was written in the same commit as the change it
describes. Nothing here is reconstructed.

## Unreleased

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
