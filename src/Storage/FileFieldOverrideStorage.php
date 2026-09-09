<?php

declare(strict_types=1);

namespace CoolMS\Field\Bundle\Storage;

use CoolMS\Entity\Registry\EntityAliasRegistry;
use CoolMS\Field\Contract\FieldOverrideStorageInterface;
use CoolMS\Field\Entity\Definition;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Persists static-entity field overrides as individual YAML files.
 *
 * Path convention:
 *   {projectDir}/config/modules/{module}/fields/{alias}/{fieldName}.yaml
 *
 * {module} is resolved from the alias:
 *   1. If alias is registered in EntityAliasRegistry -> FQCN -> App\{Module}\...
 *   2. If alias is already a FQCN (contains \) -> extract module directly
 *   3. Fallback: 'custom'
 *
 * Only non-null / non-empty values are written to the YAML file.
 */
final class FileFieldOverrideStorage implements FieldOverrideStorageInterface
{
    public function __construct(
        private readonly string $projectDir,
        private readonly EntityAliasRegistry $aliases,
    ) {
    }

    /**
     * Writes the override data to a YAML file and returns null -- no DB row is
     * created by this storage backend, so no Definition entity is available.
     *
     * If a YAML file already exists for this field, incoming data is **merged over**
     * the existing values so that pre-existing keys (e.g. `locked`, `isRequired`,
     * `formConfig`) are preserved unless explicitly overridden by $data.
     *
     * @param array<string, mixed> $data
     *
     * @throws RuntimeException when the directory cannot be created or the file cannot be written
     */
    public function save(string $alias, string $fieldName, array $data): ?Definition
    {
        $path = $this->resolvePath($alias, $fieldName);
        $dir = dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Could not create directory "%s".', $dir));
        }

        // Merge over any existing YAML so pre-existing keys (locked, isRequired,
        // formConfig, validationRules, ...) are preserved when only sortOrder changes.
        $existing = [];
        if (file_exists($path)) {
            $parsed = Yaml::parseFile($path);
            if (is_array($parsed)) {
                $existing = $parsed;
            }
        }

        $incoming = array_filter(
            $data,
            static fn (mixed $v): bool => null !== $v && '' !== $v && [] !== $v,
        );

        // Never downgrade a pre-existing `locked: true` to false via a sortOrder-only
        // write (e.g. drag-reorder).  The only way to explicitly unlock a field is to
        // send locked=true->false through the full edit form, which the DB backend handles.
        if (isset($existing['locked']) && true === $existing['locked']) {
            unset($incoming['locked']);
        }

        $merged = array_merge($existing, $incoming);

        if (false === file_put_contents($path, Yaml::dump($merged, 4, 2))) {
            throw new RuntimeException(sprintf('Could not write field override file "%s".', $path));
        }

        return null;
    }

    public function delete(string $alias, string $fieldName): void
    {
        $path = $this->resolvePath($alias, $fieldName);

        if (!file_exists($path)) {
            return;
        }

        unlink($path);

        // Remove the alias directory if it is now empty
        $dir = dirname($path);
        $files = array_diff((array) scandir($dir), ['.', '..']);
        if ([] === $files && is_dir($dir)) {
            rmdir($dir);
        }
    }

    private function resolvePath(string $alias, string $fieldName): string
    {
        $module = $this->resolveModule($alias);
        $fsAlias = $this->toFsAlias($alias);

        return sprintf(
            '%s/config/modules/%s/fields/%s/%s.yaml',
            rtrim($this->projectDir, '/'),
            $module,
            $fsAlias,
            $fieldName,
        );
    }

    /**
     * Converts an alias to a filesystem-safe directory name.
     *
     * Slug aliases are returned as-is. FQCN aliases (e.g. Vendor\Blog\Entity\PageVariant)
     * are converted to snake_case short names (e.g. page_variant) so the on-disk path
     * matches the convention used by slug-based aliases.
     */
    private function toFsAlias(string $alias): string
    {
        if (!str_contains($alias, '\\')) {
            return $alias; // already a safe slug
        }
        // Vendor\Blog\Entity\PageVariant -> 'PageVariant' -> 'page_variant'
        $shortName = substr($alias, strrpos($alias, '\\') + 1);

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $shortName));
    }

    private function resolveModule(string $alias): string
    {
        // FQCN passed directly
        if (str_contains($alias, '\\')) {
            return $this->extractModuleFromClass($alias);
        }

        // Slug registered in registry -> look up the FQCN
        $class = $this->aliases->getClassForAlias($alias);
        if (null !== $class) {
            return $this->extractModuleFromClass($class);
        }

        return 'custom';
    }

    private function extractModuleFromClass(string $class): string
    {
        $parts = explode('\\', ltrim($class, '\\'));

        // Namespace: App\{Module}\... -> $parts[0]='App', $parts[1]='Module'
        if (isset($parts[1]) && 'App' === $parts[0]) {
            return strtolower($parts[1]);
        }

        return 'custom';
    }
}
