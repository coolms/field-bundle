<?php

declare(strict_types=1);

namespace CoolMS\Field\Bundle\ApiPlatform\Resource\Provider;

use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use CoolMS\Field\Bundle\ApiPlatform\Resource\DefinitionResource;
use CoolMS\Core\Translation\InlineLabelCatalogueReaderInterface;
use CoolMS\Core\Translation\LabelResolverInterface;
use CoolMS\Core\Bundle\ApiPlatform\UriVariableUuidExtractorTrait;
use CoolMS\Field\Entity\Definition;
use CoolMS\Field\Repository\DefinitionRepositoryInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProviderInterface<DefinitionResource> */
final readonly class DefinitionProvider implements ProviderInterface
{
    use UriVariableUuidExtractorTrait;

    public function __construct(
        private DefinitionRepositoryInterface $repository,
        private LabelResolverInterface $labelResolver,
        private InlineLabelCatalogueReaderInterface $catalogueReader,
    ) {
    }

    /**
     * @return DefinitionResource|DefinitionResource[]|null
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        if ($operation instanceof GetCollection) {
            $entityAlias = ($context['filters'] ?? [])['entityAlias'] ?? null;

            /** @var Definition[] $definitions */
            $definitions = null !== $entityAlias
                ? $this->repository->findByEntityAlias($entityAlias)
                : iterator_to_array($this->repository->findAll());

            return array_map(fn (Definition $fd) => $this->toResource($fd), $definitions);
        }

        $uuid = $this->tryExtractUuid($uriVariables);
        if (null === $uuid) {
            return null;
        }

        /** @var Definition|null $fd */
        $fd = $this->repository->find($uuid);

        if (null === $fd) {
            throw new NotFoundHttpException('Field definition not found.');
        }

        // Single-item read carries the authoring pre-fill (explicit per-locale
        // overrides for both the field label and its options); the collection
        // read omits it (see toResource()).
        return $this->toResource($fd, includePrefill: true);
    }

    /**
     * Project a Definition entity to its read resource, localizing the
     * display `label` AND each select option's `label` for the current
     * request locale.
     *
     * The resolver returns the raw source value when no XLIFF override
     * exists for the request locale, so single-locale deployments are
     * behaviour-preserving. RQL filter/sort (handled in the repository)
     * still operate on the canonical DB `label`. Per-option labels use the
     * inline-child seam, keyed by the option `value` (its stable local id)
     * -- no entity promotion, options stay inline {value,label} arrays.
     *
     * `$includePrefill` adds the editor pre-fill payloads -- the explicit
     * per-locale overrides an operator has authored, for BOTH the field's own
     * `label` and each select option's label -- which only the single-item GET
     * needs. The collection read skips them: extra catalogue reads per row would
     * be wasteful and the list view never shows per-locale labels.
     */
    public function toResource(Definition $fd, bool $includePrefill = false): DefinitionResource
    {
        return DefinitionResource::fromEntity(
            $fd,
            $this->labelResolver->resolve($fd, 'label'),
            $this->localizeOptions($fd),
            $includePrefill ? $this->readOptionLabels($fd) : null,
            $includePrefill ? $this->readLabelTranslations($fd) : null,
        );
    }

    /**
     * Read the explicit per-locale overrides for this field's OWN display
     * `label`, for the editor pre-fill. Mirrors the write coordinates
     * (`keyFor()` -> `definition.{uuid}.label`, domain `field`), so what the
     * editor reads back is exactly what `FieldTranslationWriter::writeLabel()`
     * wrote. Returns null when no locale has been translated so fromEntity()
     * leaves `labelTranslations` null (single-locale deployments unchanged).
     *
     * @return array<string, string>|null `locale => override`
     */
    private function readLabelTranslations(Definition $fd): ?array
    {
        $byField = $this->catalogueReader->readLabels($fd, ['label']);

        return $byField['label'] ?? null;
    }

    /**
     * Read the explicit per-locale label overrides for this field's select
     * options, for the editor pre-fill. Returns null for fields with no
     * options (fromEntity() leaves `optionLabels` null). Mirrors the write
     * coordinates: same `option` childKind, same `value`-as-childId keying,
     * so what the editor reads back is exactly what writeChildren() wrote.
     *
     * @return array<string, array<string, string>>|null `optionValue => (locale => override)`
     */
    private function readOptionLabels(Definition $fd): ?array
    {
        $options = $fd->selectOptions;
        if ([] === $options) {
            return null;
        }

        $values = array_values(array_map(static fn (array $option): string => $option['value'], $options));

        return $this->catalogueReader->readChildLabels(
            $fd,
            Definition::TRANSLATABLE_OPTION_CHILD,
            $values,
            'label',
        );
    }

    /**
     * Resolve each select option's `label` through the inline-child seam.
     * Returns null for fields with no options so fromEntity() falls back
     * to the entity's (empty) selectOptions verbatim -- no behaviour change
     * for non-select fields. Extra option keys (beyond value/label) are
     * preserved.
     *
     * @return array<array{value: string, label: string}>|null
     */
    private function localizeOptions(Definition $fd): ?array
    {
        $options = $fd->selectOptions;
        if ([] === $options) {
            return null;
        }

        return array_map(
            // Options carry the declared shape {value: string, label: string};
            // localize the label, keyed by the stable `value`, preserving any
            // extra keys via spread.
            fn (array $option): array => [
                ...$option,
                'label' => $this->labelResolver->resolveChild(
                    $fd,
                    Definition::TRANSLATABLE_OPTION_CHILD,
                    $option['value'],
                    'label',
                    $option['label'],
                ),
            ],
            $options,
        );
    }
}
