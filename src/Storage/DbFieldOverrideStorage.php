<?php

declare(strict_types=1);

namespace CoolMS\FieldBundle\Storage;

use CoolMS\Entity\Factory\EntityFactoryFactoryInterface;
use CoolMS\Field\Contract\FieldOverrideStorageInterface;
use CoolMS\Field\Entity\Definition;
use CoolMS\Field\Entity\DefinitionInterface;
use CoolMS\Field\Repository\DefinitionRepositoryInterface;

/**
 * Persists static-entity field overrides as FieldDefinition rows in the database.
 *
 * Implements upsert semantics: if a row for (alias, fieldName) already exists it
 * is updated; otherwise a new row is created and auto-assigned a sort order.
 */
final class DbFieldOverrideStorage implements FieldOverrideStorageInterface
{
    public function __construct(
        private readonly DefinitionRepositoryInterface $repository,
        private readonly EntityFactoryFactoryInterface $entityFactoryFactory,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return Definition the persisted entity (always available for DB storage)
     */
    public function save(string $alias, string $fieldName, array $data): Definition
    {
        $fd = $this->findExisting($alias, $fieldName);

        if (null === $fd) {
            /** @var Definition $fd */
            $fd = $this->entityFactoryFactory->get(DefinitionInterface::class)->create([
                'entityAlias' => $alias,
                'name' => $fieldName,
                'type' => isset($data['type']) ? (string) $data['type'] : 'string',
            ]);

            // Auto-assign sortOrder when not provided
            if (!isset($data['sortOrder'])) {
                $fd->sortOrder = $this->repository->maxSortOrderByAlias($alias) + 10;
            }
        }

        $this->applyData($fd, $data);
        $this->repository->save($fd);

        return $fd;
    }

    public function delete(string $alias, string $fieldName): void
    {
        $fd = $this->findExisting($alias, $fieldName);
        if (null !== $fd) {
            $this->repository->delete($fd);
        }
    }

    private function findExisting(string $alias, string $fieldName): ?Definition
    {
        foreach ($this->repository->findByEntityAlias($alias) as $fd) {
            if ($fd->name === $fieldName && $fd instanceof Definition) {
                return $fd;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    private function applyData(Definition $fd, array $data): void
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

        // isRequired is expressed entirely via validationRules['NotBlank'] -- no separate key.
        if (isset($data['validationRules']) && [] !== $data['validationRules']) {
            /** @var array<string, mixed> $vr */
            $vr = $data['validationRules'];
            $fd->validationRules = $vr;
        }

        if (isset($data['formConfig']) && [] !== $data['formConfig']) {
            /** @var array<string, mixed> $fc */
            $fc = $data['formConfig'];
            $fd->formConfig = $fc;
        }
        if (isset($data['apiConfig']) && [] !== $data['apiConfig']) {
            /** @var array<string, mixed> $ac */
            $ac = $data['apiConfig'];
            $fd->apiConfig = $ac;
        }
        if (isset($data['serializerConfig']) && [] !== $data['serializerConfig']) {
            /** @var array<string, mixed> $sc */
            $sc = $data['serializerConfig'];
            $fd->serializerConfig = $sc;
        }

        // Type-specific options
        if (isset($data['maxLength'])) {
            $fd->maxLength = (int) $data['maxLength'];
        }
        if (isset($data['placeholder'])) {
            $fd->placeholder = (string) $data['placeholder'];
        }
        if (isset($data['min'])) {
            $fd->min = (float) $data['min'];
        }
        if (isset($data['max'])) {
            $fd->max = (float) $data['max'];
        }
        if (isset($data['step'])) {
            $fd->step = (float) $data['step'];
        }
        if (isset($data['precision'])) {
            $fd->precision = (int) $data['precision'];
        }
        if (isset($data['selectOptions']) && [] !== $data['selectOptions']) {
            /** @var array<array{value: string, label: string}> $so */
            $so = $data['selectOptions'];
            $fd->selectOptions = $so;
        }
        if (isset($data['multiple'])) {
            $fd->multiple = (bool) $data['multiple'];
        }
        if (isset($data['relationTarget'])) {
            $fd->relationTarget = (string) $data['relationTarget'];
        }
        if (isset($data['relationCardinality'])) {
            $fd->relationCardinality = (string) $data['relationCardinality'];
        }
        if (isset($data['relationWidget'])) {
            $fd->relationWidget = (string) $data['relationWidget'];
        }
        if (isset($data['relationFilter'])) {
            $fd->relationFilter = (string) $data['relationFilter'];
        }
        if (isset($data['relationDisplayField'])) {
            $fd->relationDisplayField = (string) $data['relationDisplayField'];
        }
        if (isset($data['dateFormat'])) {
            $fd->dateFormat = (string) $data['dateFormat'];
        }
        if (isset($data['dateMin'])) {
            $fd->dateMin = (string) $data['dateMin'];
        }
        if (isset($data['dateMax'])) {
            $fd->dateMax = (string) $data['dateMax'];
        }
        if (isset($data['securityRead']) && [] !== $data['securityRead']) {
            /** @var string[] $sr */
            $sr = $data['securityRead'];
            $fd->securityRead = $sr;
        }
        if (isset($data['securityWrite']) && [] !== $data['securityWrite']) {
            /** @var string[] $sw */
            $sw = $data['securityWrite'];
            $fd->securityWrite = $sw;
        }
    }
}
