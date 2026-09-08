<?php

declare(strict_types=1);

namespace CoolMS\FieldBundle\Tests\EntitySchema;

use CoolMS\Core\Attribute\FieldMeta;
use CoolMS\Field\Entity\Definition;
use CoolMS\Field\Repository\DefinitionRepositoryInterface;
use CoolMS\Field\Service\FieldMetadataRegistry;
use CoolMS\FieldBundle\EntitySchema\FieldSchemaSource;
use CoolMS\FieldBundle\Reflection\FieldMetaReader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Field's half of the Entity extras seam: stored Definitions -> the neutral
 * schema shape Entity's ports declare.
 *
 * The lifting rules below (appliesTo / group / widget out of `options`, `locked`
 * derived from it) used to live inside EntitySchemaLookup. They are Field's
 * storage format leaking into a shared shape, so they belong on this side of
 * the port -- and Entity no longer has to know that `options` exists.
 */
final class FieldSchemaSourceTest extends TestCase
{
    // -- appliesTo -----------------------------------------------------------

    #[Test]
    public function liftsAppliesToFromOptionsToTopLevel(): void
    {
        $source = $this->makeSource([
            $this->makeDefinition('vfs_node', 'instanceNameSuffix', [
                'appliesTo' => ['mimeType' => 'text/x-dtmpl', 'type' => 'file'],
            ]),
        ]);

        $fields = $source->getRuntimeFields('vfs_node');

        self::assertSame(
            ['mimeType' => 'text/x-dtmpl', 'type' => 'file'],
            $fields['instanceNameSuffix']['appliesTo'],
        );
    }

    #[Test]
    public function yieldsNullAppliesToWhenOptionsOmitsIt(): void
    {
        $source = $this->makeSource([$this->makeDefinition('vfs_node', 'title')]);

        self::assertNull($source->getRuntimeFields('vfs_node')['title']['appliesTo']);
    }

    #[Test]
    public function yieldsNullAppliesToWhenOptionsHoldsANonArray(): void
    {
        $source = $this->makeSource([
            $this->makeDefinition('vfs_node', 'title', ['appliesTo' => 'not-a-map']),
        ]);

        self::assertNull($source->getRuntimeFields('vfs_node')['title']['appliesTo']);
    }

    // -- widget / group ------------------------------------------------------

    #[Test]
    public function liftsWidgetFromOptionsToTopLevel(): void
    {
        // A per-field widget override (e.g. a taxonomy field's tree) rides in
        // options through the record mirror and must surface as a top-level key
        // so the field-panel resolver can forward it to the widget provider.
        $source = $this->makeSource([
            $this->makeDefinition('vfs_node', 'categoryIds', ['widget' => ['tree' => 'regions']]),
        ]);

        self::assertSame(['tree' => 'regions'], $source->getRuntimeFields('vfs_node')['categoryIds']['widget']);
    }

    #[Test]
    public function yieldsNullWidgetWhenOptionsOmitsIt(): void
    {
        $source = $this->makeSource([$this->makeDefinition('vfs_node', 'title')]);

        self::assertNull($source->getRuntimeFields('vfs_node')['title']['widget']);
    }

    #[Test]
    public function liftsGroupFromOptionsOnlyWhenItIsAString(): void
    {
        $source = $this->makeSource([
            $this->makeDefinition('vfs_node', 'title', ['group' => 'seo']),
            $this->makeDefinition('vfs_node', 'other', ['group' => ['not', 'a', 'string']]),
        ]);

        $fields = $source->getRuntimeFields('vfs_node');

        self::assertSame('seo', $fields['title']['group']);
        self::assertNull($fields['other']['group']);
    }

    // -- locked / source / security -----------------------------------------

    #[Test]
    public function derivesLockedFromOptionsAndDefaultsToFalse(): void
    {
        // Note the asymmetry with static config, which defaults `locked` to
        // TRUE: a module-shipped field is protected unless it says otherwise,
        // a record created at runtime is not.
        $source = $this->makeSource([
            $this->makeDefinition('vfs_node', 'plain'),
            $this->makeDefinition('vfs_node', 'protected', ['locked' => true]),
        ]);

        $fields = $source->getRuntimeFields('vfs_node');

        self::assertFalse($fields['plain']['locked']);
        self::assertTrue($fields['protected']['locked']);
    }

    #[Test]
    public function marksEveryRecordDefinedFieldAsRuntimeSource(): void
    {
        $source = $this->makeSource([$this->makeDefinition('vfs_node', 'title')]);

        self::assertSame('runtime', $source->getRuntimeFields('vfs_node')['title']['source']);
    }

    #[Test]
    public function mapsSecurityRolesIntoTheReadWritePair(): void
    {
        $def = $this->makeDefinition('vfs_node', 'secret');
        $def->securityRead = ['ROLE_ADMIN'];
        $def->securityWrite = ['ROLE_SUPER_ADMIN'];

        $fields = $this->makeSource([$def])->getRuntimeFields('vfs_node');

        self::assertSame(
            ['read' => ['ROLE_ADMIN'], 'write' => ['ROLE_SUPER_ADMIN']],
            $fields['secret']['security'],
        );
    }

    #[Test]
    public function returnsAnEmptyMapForAnAliasWithNoRecords(): void
    {
        self::assertSame([], $this->makeSource([])->getRuntimeFields('vfs_node'));
    }

    // -- the introspection port ---------------------------------------------

    #[Test]
    public function summariesCarryOnlyTypeAndLabelKeyedByFieldName(): void
    {
        $def = $this->makeDefinition('vfs_node', 'title');
        $def->type = 'text';
        $def->label = 'Title';

        self::assertSame(
            ['title' => ['type' => 'text', 'label' => 'Title']],
            $this->makeSource([$def])->getRuntimeFieldSummaries('vfs_node'),
        );
    }

    #[Test]
    public function resolvedMetadataFlattensTheValueObjectToTheNeutralShape(): void
    {
        $meta = $this->makeSource([])->getResolvedFieldMetadata(FieldSchemaSourceFixture::class, 'fixture');

        self::assertArrayHasKey('visible', $meta);
        self::assertSame(
            ['private', 'hasMeta', 'label', 'formType', 'sortOrder'],
            array_keys($meta['visible']),
            'The port promises exactly these keys -- a consumer reads them by name',
        );
        self::assertFalse($meta['visible']['private']);
        self::assertTrue($meta['visible']['hasMeta'], 'The property carries #[FieldMeta]');
        self::assertSame('Visible', $meta['visible']['label']);

        self::assertTrue($meta['hidden']['private'], 'private:true must survive the flattening');
    }

    // -- Helpers ------------------------------------------------------------

    /**
     * @param Definition[] $definitions
     */
    private function makeSource(array $definitions): FieldSchemaSource
    {
        $repo = $this->createStub(DefinitionRepositoryInterface::class);
        $repo->method('findByEntityAlias')->willReturn($definitions);

        return new FieldSchemaSource($repo, new FieldMetadataRegistry(new FieldMetaReader(), $repo, []));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function makeDefinition(string $entityAlias, string $name, array $options = []): Definition
    {
        $def = new Definition();
        $def->entityAlias = $entityAlias;
        $def->name = $name;
        $def->type = 'text';
        $def->options = $options;

        return $def;
    }
}

/**
 * @internal test-only class for the reflection-backed metadata pass
 */
final class FieldSchemaSourceFixture
{
    #[FieldMeta(label: 'Visible')]
    public string $visible = '';

    #[FieldMeta(private: true)]
    public string $hidden = '';
}
