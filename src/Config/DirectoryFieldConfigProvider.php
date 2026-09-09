<?php

declare(strict_types=1);

namespace CoolMS\FieldBundle\Config;

use CoolMS\Core\Config\FileFormatLoaderInterface;
use CoolMS\Core\Field\FieldConfigProviderInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Scans two locations for field config and merges the results.
 *
 * 1. Legacy per-alias file: {configDir}/{entityAlias}.yaml (or .xml / .php)
 *    First matching file wins. One file per entity alias.
 *    (Used if $configDir is non-empty; kept for backward compatibility.)
 *
 * 2. Per-field files: {projectDir}/config/modules/ * /fields/{alias}/ *.yaml
 *    One file per field; filename (without extension) is the field name.
 *    Values from per-field files overwrite values from the per-alias file when
 *    the same field is defined in both locations.
 */
final class DirectoryFieldConfigProvider implements FieldConfigProviderInterface
{
    /** @param iterable<FileFormatLoaderInterface> $loaders */
    public function __construct(
        private readonly string $projectDir,
        private readonly string $configDir = '',
        private readonly iterable $loaders = [],
    ) {
    }

    /**
     * @return array<string, array<string, mixed>>|null
     */
    public function getConfig(string $entityAlias): ?array
    {
        $result = [];

        // -- 1. Legacy per-alias file -----------------------------------------
        if ('' !== $this->configDir) {
            $perAlias = $this->loadFromAliasFile($entityAlias);
            if (null !== $perAlias) {
                $result = $perAlias;
            }
        }

        // -- 2. Per-field files -----------------------------------------------
        $perField = $this->loadFromModuleFiles($entityAlias);
        // Per-field files win over the per-alias file for the same field name
        $result = array_merge($result, $perField);

        return [] === $result ? null : $result;
    }

    /** @return array<string, array<string, mixed>>|null */
    private function loadFromAliasFile(string $entityAlias): ?array
    {
        foreach ($this->loaders as $loader) {
            foreach (['yaml', 'yml', 'xml', 'php'] as $ext) {
                if (!$loader->supports($ext)) {
                    continue;
                }
                $file = rtrim($this->configDir, '/') . '/' . $entityAlias . '.' . $ext;
                if (is_file($file)) {
                    /** @var array<string, array<string, mixed>> $data */
                    $data = $loader->load($file);

                    return $data;
                }
            }
        }

        return null;
    }

    /** @return array<string, array<string, mixed>> */
    private function loadFromModuleFiles(string $entityAlias): array
    {
        $result = [];
        $pattern = rtrim($this->projectDir, '/') . '/config/modules/*/fields/*/*.yaml';

        foreach (glob($pattern) ?: [] as $file) {
            // Parent directory name IS the alias; skip files for other aliases.
            // toFsAlias() normalises FQCN aliases to snake_case short names so that
            // they match the on-disk convention used by FileFieldOverrideStorage.
            if (basename(dirname($file)) !== $this->toFsAlias($entityAlias)) {
                continue;
            }

            $fieldName = pathinfo($file, PATHINFO_FILENAME);
            /** @var array<string, mixed> $data */
            $data = Yaml::parseFile($file) ?? [];
            if ([] !== $data) {
                $result[$fieldName] = $data;
            }
        }

        return $result;
    }

    /**
     * Converts an alias to a filesystem-safe directory name.
     *
     * Slug aliases are returned as-is. FQCN aliases (e.g. Vendor\Blog\Entity\PageVariant)
     * are converted to snake_case short names (e.g. page_variant) so that the directory
     * comparison matches the paths written by FileFieldOverrideStorage.
     */
    private function toFsAlias(string $alias): string
    {
        if (!str_contains($alias, '\\')) {
            return $alias; // already a safe slug
        }
        // Vendor\Blog\Entity\PageVariant -> 'PageVariant' -> 'page_variant'
        $shortName = substr($alias, strrpos($alias, '\\') + 1);

        return (string) preg_replace('/(?<!^)[A-Z]/', '_$0', $shortName)
                |> strtolower(...);
    }
}
