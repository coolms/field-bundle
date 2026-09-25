<?php

declare(strict_types=1);

namespace CoolMS\Field\Bundle\CacheWarmer;

use CoolMS\Entity\Factory\EntityFactoryFactoryInterface;
use CoolMS\Entity\Registry\EntityAliasRegistry;
use CoolMS\Field\Bundle\Config\DirectoryFieldConfigProvider;
use CoolMS\Field\Doctrine\EntityFieldNamesResolverInterface;
use CoolMS\Field\Entity\Definition;
use CoolMS\Field\Entity\DefinitionInterface;
use CoolMS\Field\Repository\DefinitionRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;
use Throwable;

/**
 * Imports config-defined fields (config/modules/{module}/fields/{alias}/*.yaml)
 * into coolms_field_definitions on every cache:warmup, so FieldDefinitionListener
 * can fire syncField and provision v_* generated columns for managed-dynamic entities.
 *
 * Source-of-truth for any row carrying options['system'] = true is the YAML config;
 * the warmer recreates and re-applies these rows on every warmup. UI-created rows
 * (no system marker) are NEVER touched.
 *
 * !! IT NEVER DELETES (2026-09-24). It used to delete the marked rows no YAML
 * declares any more, on every warm-up -- every rebuild, dozens a day in dev --
 * where nothing that walks the console registry could see it. That reconciliation
 * is {@see \CoolMS\Field\Bundle\Maintenance\UndeclaredDefinitionPruner} now,
 * run by `coolms:field:prune-undeclared`: a dry run unless --execute.
 *
 * Lifecycle invariants for the future override flow:
 *   - First warmup creates the row with options['system'] = true; the listener
 *     fires syncField and provisions the v_* generated column.
 *   - Removing the YAML leaves the row; `coolms:field:prune-undeclared` deletes it.
 *   - When a UI override flow later edits the same (entity_alias, name) and clears
 *     options['system'], subsequent warmups treat the row as user-owned and skip it --
 *     for the upsert and the prune alike.
 *   - Deleting that override while the YAML still defines the field will cause the
 *     next warmup to re-create the row with options['system'] = true.
 *
 * Connection or schema failures (e.g., fresh install with un-migrated DB) are logged
 * at warning level and swallowed so cache:warmup never crashes.
 */
final class FieldDefinitionSyncWarmer implements CacheWarmerInterface
{
    public function __construct(
        private readonly EntityAliasRegistry $aliasRegistry,
        private readonly DirectoryFieldConfigProvider $configProvider,
        private readonly DefinitionRepositoryInterface $repository,
        private readonly EntityFactoryFactoryInterface $entityFactoryFactory,
        private readonly EntityFieldNamesResolverInterface $fieldNamesResolver,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Optional in Symfony's sense: this warmer needs the database, and a warm-up
     * without one (`--no-optional-warmers`, an image build) must be able to skip
     * it. Required, it ran at container compile and loaded entity metadata
     * before DoctrineBundle's metadata warmer (priority 1000, optional), which
     * then refused -- so the production warm-up never completed. As an optional
     * warmer it runs after Doctrine's, in the same `cache:clear`.
     */
    public function isOptional(): bool
    {
        return true;
    }

    /**
     * @return string[]
     */
    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        try {
            foreach (array_unique($this->aliasRegistry->aliasMap) as $alias) {
                $this->syncAlias($alias);
            }
        } catch (Throwable $e) {
            $this->logger->warning(
                'FieldDefinitionSyncWarmer: skipping config-field import.',
                ['exception' => $e::class, 'message' => $e->getMessage()],
            );
        }

        return [];
    }

    private function syncAlias(string $alias): void
    {
        $config = $this->configProvider->getConfig($alias) ?? [];
        // An empty config cannot be told apart from a missing module directory, so
        // an alias that declares nothing is skipped whole.
        if ([] === $config) {
            return;
        }

        $native = $this->resolveNativeFieldNames($alias);

        /** @var array<string, Definition> $existing */
        $existing = [];
        foreach ($this->repository->findByEntityAlias($alias) as $fd) {
            if ($fd instanceof Definition) {
                $existing[$fd->name] = $fd;
            }
        }

        foreach ($config as $fieldName => $data) {
            // YAML files for native Doctrine columns/associations exist purely to
            // customise label/sortOrder/formConfig for UI rendering; importing them
            // as FieldDefinition rows would create a v_* generated column reading
            // extras->>'name', which is always NULL because the value lives in the
            // native column. Skip before any upsert.
            if (isset($native[$fieldName])) {
                continue;
            }

            $fd = $existing[$fieldName] ?? null;
            // User-owned rows (no system marker) are never touched.
            if (null !== $fd && true !== ($fd->options['system'] ?? false)) {
                continue;
            }
            $this->upsert($alias, $fieldName, $data, $fd);
        }
    }

    /**
     * Native field/association/embeddable name set for the entity registered under $alias.
     * Empty for runtime-dynamic aliases (no PHP class registered) since those store every
     * field in extras and have no native columns to shadow.
     *
     * @return array<string, true>
     */
    private function resolveNativeFieldNames(string $alias): array
    {
        $class = $this->aliasRegistry->getClassForAlias($alias);
        if (null === $class) {
            return [];
        }
        /* @var class-string $class */

        return $this->fieldNamesResolver->resolve($class);
    }

    /** @param array<string, mixed> $data */
    private function upsert(string $alias, string $fieldName, array $data, ?Definition $fd): void
    {
        if (null === $fd) {
            /** @var Definition $fd */
            $fd = $this->entityFactoryFactory->get(DefinitionInterface::class)->create([
                'entityAlias' => $alias,
                'name' => $fieldName,
                'type' => isset($data['type']) ? (string) $data['type'] : 'string',
            ]);
            if (!isset($data['sortOrder'])) {
                $fd->sortOrder = $this->repository->maxSortOrderByAlias($alias) + 10;
            }
        }

        $this->applyConfig($fd, $data);
        $fd->options['system'] = true;
        $this->repository->save($fd);
    }

    /**
     * Mirrors DbFieldOverrideStorage::applyData() for the YAML config import path.
     * Only assigns keys present in $data so unset fields keep prior values.
     *
     * Order is fixed (not array-iteration order) so that validationRules wins over
     * any redundant isRequired flag in the YAML, matching DbFieldOverrideStorage.
     *
     * @param array<string, mixed> $data
     */
    private function applyConfig(Definition $fd, array $data): void
    {
        if (isset($data['type'])) {
            $fd->type = (string) $data['type'];
        }
        if (isset($data['label'])) {
            $fd->label = (string) $data['label'];
        }
        if (array_key_exists('locked', $data)) {
            $fd->locked = (bool) $data['locked'];
        }
        if (isset($data['sortOrder'])) {
            $fd->sortOrder = (int) $data['sortOrder'];
        }
        if (isset($data['validationRules']) && is_array($data['validationRules'])) {
            /** @var array<string, mixed> $vr */
            $vr = $data['validationRules'];
            $fd->validationRules = $vr;
        }
        if (isset($data['formConfig']) && is_array($data['formConfig'])) {
            /** @var array<string, mixed> $fc */
            $fc = $data['formConfig'];
            $fd->formConfig = $fc;
        }
        if (isset($data['apiConfig']) && is_array($data['apiConfig'])) {
            /** @var array<string, mixed> $ac */
            $ac = $data['apiConfig'];
            $fd->apiConfig = $ac;
        }
        if (isset($data['serializerConfig']) && is_array($data['serializerConfig'])) {
            /** @var array<string, mixed> $sc */
            $sc = $data['serializerConfig'];
            $fd->serializerConfig = $sc;
        }
        // Carry the instance-scoping (`appliesTo`) and editor-panel grouping
        // (`group`) YAML keys into options so the DB mirror stays faithful to
        // the module declaration. Without this the mirror shadows the YAML and
        // silently drops both keys (EntitySchemaLookup reads appliesTo from
        // options; formatDefinitions surfaces group from there) -- disabling
        // instance-aware appliesTo filtering and the field-set panels.
        if (array_key_exists('appliesTo', $data)) {
            $fd->options['appliesTo'] = $data['appliesTo'];
        }
        if (array_key_exists('group', $data)) {
            $fd->options['group'] = $data['group'];
        }
        // Per-field widget overrides (e.g. a taxonomy field's `widget: { tree:
        // <code> }`) ride in options for the same reason as `group`/`appliesTo`:
        // otherwise the DB mirror shadows the YAML and silently drops the key, so
        // the resolver could never forward it to the contributing widget provider.
        if (array_key_exists('widget', $data)) {
            $fd->options['widget'] = $data['widget'];
        }
    }
}
