<?php

declare(strict_types=1);

namespace CoolMS\Field\Bundle\ApiPlatform\Resource\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use CoolMS\Field\Bundle\ApiPlatform\Resource\DefinitionResource;
use CoolMS\Field\Bundle\Translation\FieldTranslationWriter;
use CoolMS\Core\Field\ReservedFieldNameException;
use CoolMS\Core\Field\ReservedFieldNames;
use CoolMS\CoreBundle\ApiPlatform\UriVariableUuidExtractorTrait;
use CoolMS\Entity\Registry\EntityAliasRegistry;
use CoolMS\Field\Entity\Definition;
use CoolMS\Field\Repository\DefinitionRepositoryInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** @implements ProcessorInterface<DefinitionResource, DefinitionResource> */
final readonly class DefinitionUpdateProcessor implements ProcessorInterface
{
    use UriVariableUuidExtractorTrait;

    public function __construct(
        private DefinitionRepositoryInterface $repository,
        private EntityAliasRegistry $aliasRegistry,
        private FieldTranslationWriter $translationWriter,
    ) {
    }

    /**
     * @param DefinitionResource $data Input resource populated from the request body
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): DefinitionResource
    {
        $uuid = $this->extractUuid($uriVariables);

        /** @var Definition|null $fd */
        $fd = $this->repository->find($uuid);
        if (null === $fd) {
            throw new NotFoundHttpException('Field definition not found.');
        }

        // Guard: if the request carries a non-empty entityAlias that differs from the
        // field's owner, this is an attempt to mutate an inherited field.
        // Reject it -- callers must create a new FieldDefinition for the child type instead.
        $targetAlias = $data->entityAlias;
        if ('' !== $targetAlias && $fd->entityAlias !== $targetAlias) {
            throw new UnprocessableEntityHttpException("Field '{$fd->name}' belongs to '{$fd->entityAlias}', not '{$targetAlias}'. " . 'To override an inherited field, create a new field definition for the child type.');
        }

        // Detect static-entity overrides (PHP-registered alias or FQCN).
        $isStatic = $this->aliasRegistry->hasSlug($fd->entityAlias)
            || str_contains($fd->entityAlias, '\\');

        // entityAlias is immutable after creation -- never allow it to be changed.
        if ('' !== $data->name) {
            if (ReservedFieldNames::isReserved($data->name)) {
                throw new UnprocessableEntityHttpException(ReservedFieldNameException::forField($data->name)->getMessage());
            }
            $fd->name = $data->name;
        }
        if ('' !== $data->type) {
            if ($isStatic && $data->type !== $fd->type) {
                throw new UnprocessableEntityHttpException('Cannot change field type for static entity overrides.');
            }
            $fd->type = $data->type;
        }
        if ('' !== $data->label) {
            $fd->label = $data->label;
        }

        // isRequired -> validationRules translation is now handled inside applyTo().
        $data->applyTo($fd);

        $this->repository->save($fd);

        // Persist any per-locale label translations the request carried into
        // the VFS XLIFF catalogue: the field's own `label` and each select
        // option's label. No-op when the body carries
        // neither `labelTranslations` nor `optionLabels`.
        $this->translationWriter->writeLabel($fd, $data->labelTranslations);
        $this->translationWriter->writeOptionLabels($fd, $data->optionLabels);

        return DefinitionResource::fromEntity($fd);
    }
}
