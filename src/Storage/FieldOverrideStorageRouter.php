<?php

declare(strict_types=1);

namespace CoolMS\Field\Bundle\Storage;

use CoolMS\Entity\Registry\EntityAliasRegistry;
use CoolMS\Field\Contract\FieldOverrideStorageInterface;
use CoolMS\Field\Entity\Definition;

/**
 * Routes field override writes to the appropriate storage backend.
 *
 * Rules:
 *   dev  + static entity alias  -> FileFieldOverrideStorage (YAML)
 *   prod + any alias            -> DbFieldOverrideStorage
 *   dev  + runtime-only slug    -> DbFieldOverrideStorage
 *
 * "Static entity" = alias is registered in EntityAliasRegistry (hasSlug)
 *                   OR is a FQCN (contains '\').
 * Runtime slugs (pure dynamic types with no PHP class registration) are never
 * considered static and always use the DB backend.
 */
final class FieldOverrideStorageRouter implements FieldOverrideStorageInterface
{
    public function __construct(
        private readonly string $env,
        private readonly FileFieldOverrideStorage $fileStorage,
        private readonly DbFieldOverrideStorage $dbStorage,
        private readonly EntityAliasRegistry $aliasRegistry,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return Definition|null propagates the return value of the chosen storage backend
     */
    public function save(string $alias, string $fieldName, array $data): ?Definition
    {
        return $this->choose($alias)->save($alias, $fieldName, $data);
    }

    public function delete(string $alias, string $fieldName): void
    {
        $this->choose($alias)->delete($alias, $fieldName);
    }

    private function choose(string $alias): FieldOverrideStorageInterface
    {
        if ('dev' === $this->env && $this->isStaticEntity($alias)) {
            return $this->fileStorage;
        }

        return $this->dbStorage;
    }

    private function isStaticEntity(string $alias): bool
    {
        // FQCN passed directly -> definitely a static entity
        if (str_contains($alias, '\\')) {
            return true;
        }

        // Slug registered as a PHP alias (e.g. 'page_variant') -> static.
        // Use hasAlias(getClassForAlias(...)) so PHPStan is happy with the
        // class-string key type; null-coalescing '' produces hasAlias(false) -> false
        // for unregistered slugs.
        return $this->aliasRegistry->hasAlias(
            $this->aliasRegistry->getClassForAlias($alias) ?? '',
        );
    }
}
