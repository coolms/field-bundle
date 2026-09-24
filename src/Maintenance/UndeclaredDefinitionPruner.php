<?php

declare(strict_types=1);

namespace CoolMS\Field\Bundle\Maintenance;

use CoolMS\Entity\Registry\EntityAliasRegistry;
use CoolMS\Field\Bundle\Config\DirectoryFieldConfigProvider;
use CoolMS\Field\Doctrine\EntityFieldNamesResolverInterface;
use CoolMS\Field\Entity\Definition;
use CoolMS\Field\Repository\DefinitionRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Deletes the field definitions the sync warmer owns whose YAML declaration is
 * gone -- the reconciliation that used to run inside every cache warm-up.
 *
 * !! A CACHE WARM-UP NEVER DELETES DATA (Dmitry, 2026-09-24). Warm-up runs on
 * every rebuild -- in dev, dozens of times a day since configuration edits rebuild
 * -- and nothing that walks the console registry sees a warmer. The deletion is
 * an explicit step now: `coolms:field:prune-undeclared`, a dry run unless
 * `--execute`, refusing unattended without `--force`, saying "N of M".
 *
 * The rule itself is unchanged from the warmer's:
 *
 * 1. only rows carrying `options.system = true` -- the warmer's own mark, which a
 *    request body can no longer set or clear -- are ever taken;
 * 2. an alias whose YAML declares NOTHING is skipped whole: an empty config
 *    cannot be told apart from a missing module directory (six live
 *    `page_variant` rows once hung on that difference);
 * 3. a native column's name is spared: the warmer never creates such a row, and
 *    the one that predates that guard (`vfs_node.description`) carries a native
 *    column's admin-schema entry.
 *
 * The v_* column is NOT dropped with the row: aliases share
 * `coolms_dynamic_records`, so two can map onto one v_* column.
 */
final readonly class UndeclaredDefinitionPruner
{
    public function __construct(
        private EntityAliasRegistry $aliasRegistry,
        private DirectoryFieldConfigProvider $configProvider,
        private DefinitionRepositoryInterface $repository,
        private EntityFieldNamesResolverInterface $fieldNamesResolver,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * What a prune would delete, out of the definitions it looked at.
     *
     * @return array{undeclared: list<Definition>, examined: int}
     */
    public function plan(): array
    {
        $undeclared = [];
        $examined = 0;
        foreach (array_unique($this->aliasRegistry->aliasMap) as $alias) {
            $config = $this->configProvider->getConfig($alias) ?? [];
            $rows = $this->repository->findByEntityAlias($alias);
            $examined += count($rows);
            if ([] === $config) {
                continue;
            }
            $native = $this->nativeFieldNames($alias);
            foreach ($rows as $fd) {
                if (!$fd instanceof Definition) {
                    continue;
                }
                if (isset($config[$fd->name]) || isset($native[$fd->name])) {
                    continue;
                }
                if (true !== ($fd->options['system'] ?? false)) {
                    continue;
                }
                $undeclared[] = $fd;
            }
        }

        return ['undeclared' => $undeclared, 'examined' => $examined];
    }

    /**
     * @param list<Definition> $undeclared from {@see plan()}
     *
     * @return int the definitions deleted
     */
    public function prune(array $undeclared): int
    {
        $deleted = 0;
        foreach ($undeclared as $fd) {
            $this->repository->delete($fd);
            ++$deleted;
            $this->logger->info(
                'Deleted a system field definition no YAML declares any more.',
                ['alias' => $fd->entityAlias, 'field' => $fd->name],
            );
        }

        return $deleted;
    }

    /**
     * @return array<string, true>
     */
    private function nativeFieldNames(string $alias): array
    {
        $class = $this->aliasRegistry->getClassForAlias($alias);
        if (null === $class) {
            return [];
        }
        /* @var class-string $class */

        return $this->fieldNamesResolver->resolve($class);
    }
}
