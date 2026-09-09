<?php

declare(strict_types=1);

namespace CoolMS\Field\Bundle\Translation;

use CoolMS\Core\Identity\UserInterface;
use CoolMS\Core\Translation\InlineLabelCatalogueWriterInterface;
use CoolMS\Field\Entity\Definition;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Persists the per-locale label translations supplied on a FieldDefinition
 * write into the VFS XLIFF catalogue, via the platform inline-label write
 * bridge. Two coordinates:
 *
 *  - {@see writeOptionLabels()} -- per-option labels, keyed by
 *    the option `value` under the `option` childKind. The seam the
 *    {@see InlineLabelCatalogueWriterInterface::writeChildren()} bridge was
 *    built for.
 *  - {@see writeLabel()} -- the field's OWN display `label`, the
 *    entity-level mirror, written via
 *    {@see InlineLabelCatalogueWriterInterface::write()} keyed
 *    `definition.{uuid}.label`.
 *
 * Shared by the Create/Update processors so the actor resolution + payload
 * reshape live in one place. (Renamed from `FieldOptionLabelWriter` when the
 * field-label coordinate was added -- same responsibility, now both labels.)
 *
 * **Source value is NOT written here.** The default-locale source label stays
 * inline (in `selectOptions[*].label` / `options['label']`); only the
 * translated locales the author supplied land in the catalogue (same contract
 * as the read side, which falls back to the source when a locale has no
 * override).
 */
final readonly class FieldTranslationWriter
{
    public function __construct(
        private InlineLabelCatalogueWriterInterface $catalogueWriter,
        private Security $security,
    ) {
    }

    /**
     * @param array<string, array<string, ?string>>|null $optionLabels
     *                                                                 Map of `optionValue => (locale => translated text)`. `null`
     *                                                                 / `[]` is a no-op. A `null` text for a locale clears that
     *                                                                 option's override (reverts to the inline source label); an
     *                                                                 empty string is a deliberate blank translation. Both are
     *                                                                 passed through to the write bridge verbatim.
     */
    public function writeOptionLabels(Definition $definition, ?array $optionLabels): void
    {
        if (null === $optionLabels || [] === $optionLabels) {
            return;
        }

        $actor = $this->resolveActor();
        if (null === $actor) {
            return;
        }

        // The write bridge keys children as `childId => field => locale =>
        // value`; options carry a single translatable field (`label`), so
        // wrap each option's locale map under `label`.
        $childLabels = [];
        foreach ($optionLabels as $optionValue => $localeValues) {
            $childLabels[(string) $optionValue] = ['label' => $localeValues];
        }

        $this->catalogueWriter->writeChildren(
            $definition,
            Definition::TRANSLATABLE_OPTION_CHILD,
            $childLabels,
            $actor,
        );
    }

    /**
     * @param array<string, ?string>|null $labelTranslations
     *                                                       Map of `locale => translated label`. `null` / `[]` is a no-op.
     *                                                       A `null` value for a locale clears that override (reverts to the
     *                                                       inline source label); an empty string is a deliberate blank
     *                                                       translation. Passed through to the write bridge verbatim, wrapped
     *                                                       under the `label` field.
     */
    public function writeLabel(Definition $definition, ?array $labelTranslations): void
    {
        if (null === $labelTranslations || [] === $labelTranslations) {
            return;
        }

        $actor = $this->resolveActor();
        if (null === $actor) {
            return;
        }

        $this->catalogueWriter->write(
            $definition,
            ['label' => $labelTranslations],
            $actor,
        );
    }

    /**
     * The write endpoints are ROLE_ADMIN-gated, so an anonymous caller can't
     * reach here -- but the catalogue save needs a real actor (blame + VFS
     * ownership), so be defensive and skip rather than pass a null actor down.
     */
    private function resolveActor(): ?UserInterface
    {
        $actor = $this->security->getUser();

        return $actor instanceof UserInterface ? $actor : null;
    }
}
