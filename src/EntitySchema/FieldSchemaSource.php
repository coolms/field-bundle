<?php

declare(strict_types=1);

namespace CoolMS\Field\Bundle\EntitySchema;

use CoolMS\Entity\Contract\FieldMetadataSourceInterface;
use CoolMS\Entity\Contract\FieldSchemaSourceInterface;
use CoolMS\Field\Repository\DefinitionRepositoryInterface;
use CoolMS\Field\Service\FieldMetadataRegistry;

/**
 * Field's side of the Entity extras seam: turns stored Definitions and resolved
 * FieldMetadata into the neutral shapes Entity's ports declare.
 *
 * The direction matters. Field (L1) implements interfaces owned by Entity (L0),
 * so the dependency points down. Entity gained the extras engine without
 * gaining a single reference to a Field class.
 */
final readonly class FieldSchemaSource implements FieldSchemaSourceInterface, FieldMetadataSourceInterface
{
    public function __construct(
        private DefinitionRepositoryInterface $repository,
        private FieldMetadataRegistry $metadataRegistry,
    ) {
    }

    /**
     * Convert stored Definitions to the neutral schema shape.
     *
     * `appliesTo`, `group` and `widget` are lifted out of `options` to
     * top-level keys so downstream code treats record-stored and file-stored
     * fields uniformly -- the file format carries them at the top level, while
     * the record mirror keeps them in options (see FieldDefinitionSyncWarmer)
     * so they survive the record-over-file merge.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getRuntimeFields(string $entityAlias): array
    {
        $result = [];
        foreach ($this->repository->findByEntityAlias($entityAlias) as $def) {
            $appliesTo = $def->options['appliesTo'] ?? null;
            $result[$def->name] = [
                'id' => $def->id->toRfc4122(),
                'entityAlias' => $def->entityAlias,
                'type' => $def->type,
                'label' => $def->label,
                'isRequired' => $def->isRequired,
                // Derived from options on the concrete Definition; recomputed
                // here so the port stays typed against DefinitionInterface.
                'locked' => (bool) ($def->options['locked'] ?? false),
                'source' => 'runtime',
                'validationRules' => $def->validationRules,
                'options' => $def->options,
                'formConfig' => $def->formConfig,
                'apiConfig' => $def->apiConfig,
                'serializerConfig' => $def->serializerConfig,
                'sortOrder' => $def->sortOrder,
                'security' => [
                    'read' => $def->securityRead,
                    'write' => $def->securityWrite,
                ],
                'appliesTo' => is_array($appliesTo) ? $appliesTo : null,
                'group' => is_string($def->options['group'] ?? null) ? $def->options['group'] : null,
                'widget' => is_array($def->options['widget'] ?? null) ? $def->options['widget'] : null,
            ];
        }

        return $result;
    }

    /**
     * @return array<string, array{type: string, label: string}>
     */
    public function getRuntimeFieldSummaries(string $entityAlias): array
    {
        $result = [];
        foreach ($this->repository->findByEntityAlias($entityAlias) as $def) {
            $result[$def->name] = [
                'type' => $def->type,
                'label' => $def->label,
            ];
        }

        return $result;
    }

    /**
     * @param class-string $className
     *
     * @return array<string, array{private: bool, hasMeta: bool, label: string, formType: string|null, sortOrder: int|null}>
     */
    public function getResolvedFieldMetadata(string $className, string $entityAlias): array
    {
        $result = [];
        foreach ($this->metadataRegistry->getAll($className, $entityAlias) as $name => $meta) {
            $result[$name] = [
                'private' => $meta->private,
                'hasMeta' => $meta->hasMeta,
                'label' => $meta->label,
                'formType' => $meta->formType,
                'sortOrder' => $meta->sortOrder,
            ];
        }

        return $result;
    }

    public function hasFileOverride(string $entityAlias, string $fieldName): bool
    {
        return $this->metadataRegistry->hasFileOverride($entityAlias, $fieldName);
    }
}
