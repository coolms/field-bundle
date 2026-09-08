<?php

declare(strict_types=1);

namespace CoolMS\FieldBundle\Tests\ApiPlatform\Resource\Provider;

use CoolMS\Core\Translation\InlineLabelCatalogueReaderInterface;
use CoolMS\Core\Translation\LabelResolverInterface;
use CoolMS\Field\Entity\Definition;
use CoolMS\Field\Repository\DefinitionRepositoryInterface;
use CoolMS\FieldBundle\ApiPlatform\Resource\Provider\DefinitionProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * F5.b Phase 5 -- the FieldDefinition API provider localizes the display
 * `label` (stored in options['label']) through LabelResolver. The
 * `toResource()` projection is the read seam; these tests pin that it
 * delegates to the resolver for `label` while leaving sibling fields
 * (name, type, selectOptions) verbatim.
 *
 * Per-option (selectOptions) labels ARE localized too (F5.b Phase 5):
 * options are inline {value,label} arrays keyed by their stable `value`
 * via the LabelResolver inline-child seam (resolveChild). Pinned by
 * `localizesEachSelectOptionLabelViaResolveChild`.
 */
final class DefinitionProviderTest extends TestCase
{
    #[Test]
    public function toResourceLocalizesTheLabelViaResolver(): void
    {
        $definition = $this->definition(name: 'colour', label: 'Colour');

        $resolver = $this->createStub(LabelResolverInterface::class);
        $resolver->method('resolve')->willReturn('Колір'); // localized override

        $resource = $this->provider($resolver)->toResource($definition);

        self::assertSame('Колір', $resource->label);
        // Sibling fields stay raw -- only `label` routes through the resolver.
        self::assertSame('colour', $resource->name);
        self::assertSame('string', $resource->type);
    }

    #[Test]
    public function asksTheResolverForTheLabelField(): void
    {
        $definition = $this->definition(name: 'colour', label: 'Colour');

        $capturedField = null;
        $resolver = $this->createStub(LabelResolverInterface::class);
        $resolver->method('resolve')->willReturnCallback(
            static function (object $def, string $field) use (&$capturedField): string {
                $capturedField = $field;

                return 'whatever';
            },
        );

        $this->provider($resolver)->toResource($definition);

        self::assertSame('label', $capturedField);
    }

    #[Test]
    public function passesThroughRawLabelWhenResolverHasNoOverride(): void
    {
        // LabelResolver's documented fallback: with no XLIFF override it
        // returns the source value. A stub echoing the source models a
        // single-locale deployment -- behaviour-preserving.
        $definition = $this->definition(name: 'colour', label: 'Colour');

        $resolver = $this->createStub(LabelResolverInterface::class);
        $resolver->method('resolve')->willReturnCallback(
            static fn (Definition $def): string => $def->label, // echo source
        );

        self::assertSame('Colour', $this->provider($resolver)->toResource($definition)->label);
    }

    #[Test]
    public function localizesEachSelectOptionLabelViaResolveChild(): void
    {
        // Per-option labels route through the inline-child seam, keyed by
        // the option `value`. The provider passes the option's source
        // label as the fallback and the childKind `option`.
        $definition = $this->definition(name: 'status', label: 'Status');
        $definition->selectOptions = [
            ['value' => 'open', 'label' => 'Open'],
            ['value' => 'closed', 'label' => 'Closed'],
        ];

        $resolver = $this->createStub(LabelResolverInterface::class);
        $resolver->method('resolveChild')->willReturnCallback(
            static fn (object $p, string $kind, string $childId, string $field, string $src): string => match ($childId) {
                'open' => 'Відкрито',
                'closed' => 'Закрито',
                default => $src,
            },
        );

        $resource = $this->provider($resolver)->toResource($definition);

        self::assertSame(
            [
                ['value' => 'open', 'label' => 'Відкрито'],
                ['value' => 'closed', 'label' => 'Закрито'],
            ],
            $resource->selectOptions,
        );
    }

    #[Test]
    public function asksResolveChildWithOptionCoordinatesAndSourceFallback(): void
    {
        $definition = $this->definition(name: 'status', label: 'Status');
        $definition->selectOptions = [['value' => 'open', 'label' => 'Open']];

        $captured = [];
        $resolver = $this->createStub(LabelResolverInterface::class);
        $resolver->method('resolveChild')->willReturnCallback(
            static function (object $p, string $kind, string $childId, string $field, string $src) use (&$captured): string {
                $captured = ['kind' => $kind, 'childId' => $childId, 'field' => $field, 'src' => $src];

                return $src;
            },
        );

        $this->provider($resolver)->toResource($definition);

        self::assertSame(
            ['kind' => 'option', 'childId' => 'open', 'field' => 'label', 'src' => 'Open'],
            $captured,
        );
    }

    #[Test]
    public function passesThroughRawOptionLabelsWhenResolverHasNoOverride(): void
    {
        // Single-locale deployment: resolveChild echoes the source value.
        $definition = $this->definition(name: 'status', label: 'Status');
        $definition->selectOptions = [
            ['value' => 'open', 'label' => 'Open'],
            ['value' => 'closed', 'label' => 'Closed'],
        ];

        $resolver = $this->createStub(LabelResolverInterface::class);
        $resolver->method('resolveChild')->willReturnCallback(
            static fn (object $p, string $kind, string $childId, string $field, string $src): string => $src,
        );

        self::assertSame(
            [
                ['value' => 'open', 'label' => 'Open'],
                ['value' => 'closed', 'label' => 'Closed'],
            ],
            $this->provider($resolver)->toResource($definition)->selectOptions,
        );
    }

    #[Test]
    public function withoutOptionLabelsFlagLeavesPrefillNull(): void
    {
        // The collection read (and the write-response projection) skip the
        // catalogue: optionLabels is input-only there.
        $definition = $this->definition(name: 'status', label: 'Status');
        $definition->selectOptions = [['value' => 'open', 'label' => 'Open']];

        $resolver = $this->createStub(LabelResolverInterface::class);
        $resolver->method('resolveChild')->willReturnCallback(
            static fn (object $p, string $k, string $c, string $f, string $src): string => $src,
        );
        $reader = $this->createMock(InlineLabelCatalogueReaderInterface::class);
        $reader->expects(self::never())->method('readChildLabels');

        $resource = $this->provider($resolver, $reader)->toResource($definition);

        self::assertNull($resource->optionLabels);
    }

    #[Test]
    public function withOptionLabelsFlagPrefillsFromTheCatalogueReader(): void
    {
        // The single-item read injects the editor pre-fill: the explicit
        // per-locale option overrides currently authored.
        $definition = $this->definition(name: 'status', label: 'Status');
        $definition->selectOptions = [
            ['value' => 'open', 'label' => 'Open'],
            ['value' => 'closed', 'label' => 'Closed'],
        ];

        $resolver = $this->createStub(LabelResolverInterface::class);
        $resolver->method('resolveChild')->willReturnCallback(
            static fn (object $p, string $k, string $c, string $f, string $src): string => $src,
        );

        $captured = [];
        $reader = $this->createStub(InlineLabelCatalogueReaderInterface::class);
        $reader->method('readChildLabels')->willReturnCallback(
            static function (object $p, string $kind, array $childIds, string $field) use (&$captured): array {
                $captured = ['kind' => $kind, 'childIds' => $childIds, 'field' => $field];

                return ['open' => ['uk' => 'Відкрито']];
            },
        );

        $resource = $this->provider($resolver, $reader)->toResource($definition, includePrefill: true);

        self::assertSame(['open' => ['uk' => 'Відкрито']], $resource->optionLabels);
        // Reader is addressed with the option childKind, the option values as
        // child ids, and the `label` field -- mirroring the write coordinates.
        self::assertSame(
            ['kind' => 'option', 'childIds' => ['open', 'closed'], 'field' => 'label'],
            $captured,
        );
    }

    #[Test]
    public function optionLabelsPrefillIsNullForFieldsWithoutOptions(): void
    {
        $definition = $this->definition(name: 'colour', label: 'Colour'); // no selectOptions

        $resolver = $this->createStub(LabelResolverInterface::class);
        $reader = $this->createMock(InlineLabelCatalogueReaderInterface::class);
        $reader->expects(self::never())->method('readChildLabels');

        $resource = $this->provider($resolver, $reader)->toResource($definition, includePrefill: true);

        self::assertNull($resource->optionLabels);
    }

    // -- Field-label translations prefill (#706) -----------------------------

    #[Test]
    public function withPrefillFlagPrefillsLabelTranslationsFromTheReader(): void
    {
        // The single-item read injects the editor pre-fill for the field's OWN
        // label: the explicit per-locale overrides currently authored.
        $definition = $this->definition(name: 'colour', label: 'Colour');

        $resolver = $this->createStub(LabelResolverInterface::class);
        $resolver->method('resolve')->willReturnCallback(
            static fn (Definition $def): string => $def->label,
        );

        $captured = [];
        $reader = $this->createStub(InlineLabelCatalogueReaderInterface::class);
        $reader->method('readLabels')->willReturnCallback(
            static function (object $p, array $fields) use (&$captured): array {
                $captured = ['fields' => $fields];

                return ['label' => ['uk' => 'Колір', 'de' => 'Farbe']];
            },
        );

        $resource = $this->provider($resolver, $reader)->toResource($definition, includePrefill: true);

        self::assertSame(['uk' => 'Колір', 'de' => 'Farbe'], $resource->labelTranslations);
        // The reader is addressed with the `label` field -- mirroring the
        // write coordinates (keyFor -> definition.{uuid}.label).
        self::assertSame(['fields' => ['label']], $captured);
    }

    #[Test]
    public function labelTranslationsPrefillIsNullWhenNoLocaleAuthored(): void
    {
        // Reader returns nothing for `label` -> fromEntity() leaves it null,
        // so single-locale deployments are behaviour-preserving.
        $definition = $this->definition(name: 'colour', label: 'Colour');

        $resolver = $this->createStub(LabelResolverInterface::class);
        $resolver->method('resolve')->willReturnCallback(
            static fn (Definition $def): string => $def->label,
        );
        $reader = $this->createStub(InlineLabelCatalogueReaderInterface::class);
        $reader->method('readLabels')->willReturn([]); // no overrides anywhere

        $resource = $this->provider($resolver, $reader)->toResource($definition, includePrefill: true);

        self::assertNull($resource->labelTranslations);
    }

    #[Test]
    public function withoutPrefillFlagLeavesLabelTranslationsNull(): void
    {
        // The collection read (and the write-response projection) skip the
        // catalogue: labelTranslations is input-only there.
        $definition = $this->definition(name: 'colour', label: 'Colour');

        $resolver = $this->createStub(LabelResolverInterface::class);
        $resolver->method('resolve')->willReturnCallback(
            static fn (Definition $def): string => $def->label,
        );
        $reader = $this->createMock(InlineLabelCatalogueReaderInterface::class);
        $reader->expects(self::never())->method('readLabels');

        $resource = $this->provider($resolver, $reader)->toResource($definition);

        self::assertNull($resource->labelTranslations);
    }

    private function provider(
        LabelResolverInterface $resolver,
        ?InlineLabelCatalogueReaderInterface $reader = null,
    ): DefinitionProvider {
        return new DefinitionProvider(
            $this->createStub(DefinitionRepositoryInterface::class),
            $resolver,
            $reader ?? $this->stubReader(),
        );
    }

    private function stubReader(): InlineLabelCatalogueReaderInterface
    {
        $reader = $this->createStub(InlineLabelCatalogueReaderInterface::class);
        $reader->method('readChildLabels')->willReturn([]);
        $reader->method('readLabels')->willReturn([]);

        return $reader;
    }

    private function definition(string $name, string $label): Definition
    {
        $definition = new Definition();
        $definition->entityAlias = 'product';
        $definition->name = $name;
        $definition->type = 'string';
        $definition->label = $label; // routes into options['label']

        return $definition;
    }
}
