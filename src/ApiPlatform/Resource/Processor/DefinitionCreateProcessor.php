<?php

declare(strict_types=1);

namespace CoolMS\FieldBundle\ApiPlatform\Resource\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use CoolMS\FieldBundle\ApiPlatform\Resource\DefinitionResource;
use CoolMS\FieldBundle\Translation\FieldTranslationWriter;
use CoolMS\Core\Field\ReservedFieldNameException;
use CoolMS\Core\Field\ReservedFieldNames;
use CoolMS\Entity\Factory\EntityFactoryFactoryInterface;
use CoolMS\Entity\Registry\EntityAliasRegistry;
use CoolMS\Field\Contract\FieldOverrideStorageInterface;
use CoolMS\Field\Entity\Definition;
use CoolMS\Field\Entity\DefinitionInterface;
use CoolMS\Field\Repository\DefinitionRepositoryInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** @implements ProcessorInterface<DefinitionResource, DefinitionResource> */
final class DefinitionCreateProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly DefinitionRepositoryInterface $repository,
        private readonly EntityFactoryFactoryInterface $entityFactoryFactory,
        private readonly EntityAliasRegistry $aliasRegistry,
        private readonly FieldOverrideStorageInterface $storage,
        private readonly FieldTranslationWriter $translationWriter,
    ) {
    }

    /**
     * @param DefinitionResource $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): DefinitionResource
    {
        // isRequired -> validationRules translation is now handled inside applyTo()
        // and buildFieldData(); no need to mutate $data here.

        if ('' === trim($data->entityAlias)) {
            throw new UnprocessableEntityHttpException('entityAlias is required.');
        }

        if ('' === trim($data->name)) {
            throw new UnprocessableEntityHttpException('name is required.');
        }

        $alias = trim($data->entityAlias);
        $fieldName = trim($data->name);

        // For PHP-registered entity aliases and FQCN aliases, FieldDefinition is a
        // metadata overlay -- skip the reserved-name check so native columns (e.g. 'id',
        // 'createdAt') can receive DB overrides such as label or sortOrder.
        $isStaticEntityOverride =
            $this->aliasRegistry->hasSlug($alias)
            || str_contains($alias, '\\');

        if (!$isStaticEntityOverride
            && ReservedFieldNames::isReserved($fieldName)) {
            throw new UnprocessableEntityHttpException(ReservedFieldNameException::forField($fieldName)->getMessage());
        }

        // -- Static entity alias: delegate to the environment-aware storage router --
        if ($isStaticEntityOverride) {
            // Auto-assign sortOrder when not explicitly provided; compute locally to
            // avoid mutating the readonly $data resource.
            $effectiveSortOrder = $data->sortOrder ?? ($this->repository->maxSortOrderByAlias($alias) + 10);

            $payload = $this->isSortOrderOnly($data)
                ? $this->buildSortOrderData($data, $effectiveSortOrder)
                : $this->buildFieldData($data, $effectiveSortOrder);

            $saved = $this->storage->save($alias, $fieldName, $payload);

            // Prod path (DbFieldOverrideStorage): returns the persisted entity directly.
            if (null !== $saved) {
                return DefinitionResource::fromEntity($saved);
            }

            // Any path: check whether a DB row already exists for this alias+name
            // (e.g. prod DbFieldOverrideStorage upserted it, or it was pre-existing).
            foreach ($this->repository->findByEntityAlias($alias) as $fd) {
                if ($fd->name === $fieldName && $fd instanceof Definition) {
                    return DefinitionResource::fromEntity($fd);
                }
            }

            // Dev / file-only path: storage wrote only to a YAML file, no DB row.
            // Return 204 No Content so the client knows the save succeeded.
            throw new HttpException(Response::HTTP_NO_CONTENT);
        }

        // -- Runtime alias: write directly to DB (existing behaviour) -------------
        // Enforce uniqueness of (entityAlias, name) -- no upsert for runtime fields.
        $existing = $this->repository->findByEntityAlias($alias);
        foreach ($existing as $existingFd) {
            if ($existingFd->name === $fieldName) {
                throw new ConflictHttpException(sprintf('A field named "%s" already exists for entity type "%s".', $fieldName, $alias));
            }
        }

        /** @var Definition $fd */
        $fd = $this->entityFactoryFactory->get(DefinitionInterface::class)->create([
            'entityAlias' => $alias,
            'name' => $fieldName,
            'type' => $data->type ?: 'string',
        ]);

        // Apply all bulk + type-specific + security fields via the resource's
        // own merge logic so that top-level individual fields take precedence
        // over the raw options bag regardless of which style the client used.
        $data->applyTo($fd);

        // Auto-assign sortOrder when not explicitly provided: max(existing) + 10.
        // applyTo() skips null sortOrder, so we fill it in here.
        if (null === $data->sortOrder) {
            $fd->sortOrder = $this->repository->maxSortOrderByAlias($alias) + 10;
        }

        $this->repository->save($fd);

        // Persist per-locale label translations carried by the request: the
        // field's own `label` plus each select option's label. Wired on the
        // runtime path (the dynamic-entity /
        // products-catalog use case, which always has a DB Definition with a
        // UUID to key against). Static-entity overrides and the file-only path
        // are out of scope for this increment -- the file-only path has no
        // persisted entity to derive a catalogue key from.
        $this->translationWriter->writeLabel($fd, $data->labelTranslations);
        $this->translationWriter->writeOptionLabels($fd, $data->optionLabels);

        return DefinitionResource::fromEntity($fd);
    }

    /**
     * Convert a DefinitionResource into the flat data map expected by
     * FieldOverrideStorageInterface -- same shape as a YAML field block.
     *
     * Only non-null / non-empty values are included so that storage
     * implementations can write clean YAML / apply minimal DB updates.
     *
     * @param int $effectiveSortOrder Pre-computed sort order (auto-assigned when $resource->sortOrder is null)
     *
     * @return array<string, mixed>
     */
    private function buildFieldData(DefinitionResource $resource, int $effectiveSortOrder): array
    {
        $data = [];

        if ('' !== $resource->type) {
            $data['type'] = $resource->type;
        }
        if ('' !== $resource->label) {
            $data['label'] = $resource->label;
        }
        $data['sortOrder'] = $effectiveSortOrder;

        // locked -- only write when explicitly provided (null = not in body, skip)
        if (null !== $resource->locked) {
            $data['locked'] = $resource->locked;
        }

        // Resolve effective validationRules (honour isRequired shortcut).
        // null validationRules = not in body; we still honour isRequired alone if provided.
        $validationRules = $resource->validationRules;
        if (null !== $validationRules) {
            if (true === $resource->isRequired) {
                $validationRules['NotBlank'] ??= null;
            } elseif (false === $resource->isRequired) {
                unset($validationRules['NotBlank']);
            }
        }
        if (!empty($validationRules)) {
            $data['validationRules'] = $validationRules;
        }

        if (!empty($resource->formConfig)) {
            $data['formConfig'] = $resource->formConfig;
        }
        if (!empty($resource->apiConfig)) {
            $data['apiConfig'] = $resource->apiConfig;
        }
        if (!empty($resource->serializerConfig)) {
            $data['serializerConfig'] = $resource->serializerConfig;
        }

        // Type-specific options
        if (null !== $resource->maxLength) {
            $data['maxLength'] = $resource->maxLength;
        }
        if (null !== $resource->placeholder) {
            $data['placeholder'] = $resource->placeholder;
        }
        if (null !== $resource->min) {
            $data['min'] = $resource->min;
        }
        if (null !== $resource->max) {
            $data['max'] = $resource->max;
        }
        if (null !== $resource->step) {
            $data['step'] = $resource->step;
        }
        if (null !== $resource->precision) {
            $data['precision'] = $resource->precision;
        }
        if (!empty($resource->selectOptions)) {
            $data['selectOptions'] = $resource->selectOptions;
        }
        if (null !== $resource->multiple) {
            $data['multiple'] = $resource->multiple;
        }
        if (null !== $resource->relationTarget) {
            $data['relationTarget'] = $resource->relationTarget;
        }
        if (null !== $resource->relationCardinality) {
            $data['relationCardinality'] = $resource->relationCardinality;
        }
        if (null !== $resource->relationWidget) {
            $data['relationWidget'] = $resource->relationWidget;
        }
        if (null !== $resource->relationFilter) {
            $data['relationFilter'] = $resource->relationFilter;
        }
        if (null !== $resource->relationDisplayField) {
            $data['relationDisplayField'] = $resource->relationDisplayField;
        }
        if (null !== $resource->dateFormat) {
            $data['dateFormat'] = $resource->dateFormat;
        }
        if (null !== $resource->dateMin) {
            $data['dateMin'] = $resource->dateMin;
        }
        if (null !== $resource->dateMax) {
            $data['dateMax'] = $resource->dateMax;
        }
        if (!empty($resource->securityRead)) {
            $data['securityRead'] = $resource->securityRead;
        }
        if (!empty($resource->securityWrite)) {
            $data['securityWrite'] = $resource->securityWrite;
        }

        return $data;
    }

    /**
     * Returns true when the request carries only positional/identity data
     * (entityAlias, name, type, label, sortOrder) and all other fields are
     * absent or at their zero/empty values.
     *
     * Used to guard drag-to-reorder writes: a sortOrder-only save must not
     * overwrite locked, isRequired, validationRules, or any other metadata
     * that may already exist in the YAML file.
     */
    private function isSortOrderOnly(DefinitionResource $resource): bool
    {
        return empty($resource->validationRules)
            && empty($resource->formConfig)
            && empty($resource->apiConfig)
            && empty($resource->serializerConfig)
            && (null === $resource->locked || false === $resource->locked)
            && null === $resource->maxLength
            && null === $resource->placeholder;
    }

    /**
     * Minimal data map for sortOrder-only writes.
     *
     * Only type, label and sortOrder are included so that FileFieldOverrideStorage
     * merges cleanly into an existing YAML file without touching any other key.
     *
     * @return array<string, mixed>
     */
    private function buildSortOrderData(DefinitionResource $resource, int $effectiveSortOrder): array
    {
        return array_filter(
            [
                'type' => $resource->type ?: null,
                'label' => $resource->label ?: null,
                'sortOrder' => $effectiveSortOrder,
            ],
            static fn (mixed $v): bool => null !== $v,
        );
    }
}
