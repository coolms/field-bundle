<?php

declare(strict_types=1);

namespace CoolMS\FieldBundle\Tests\Reflection;

use CoolMS\Core\Attribute\FieldMeta;
use CoolMS\FieldBundle\Reflection\FieldMetaReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

final class FieldMetaReaderTest extends TestCase
{
    private FieldMetaReader $reader;

    public function testReadAllReturnsMetadataIndexedByPropertyName(): void
    {
        $target = new class {
            #[Assert\NotBlank]
            #[Assert\Length(max: 100)]
            #[Groups(['content:page:read', 'content:page:write'])]
            #[FieldMeta(label: 'Title', formType: 'text', sortOrder: 1)]
            public string $title = '';

            public string $noMeta = '';
        };

        $all = $this->reader->readAll($target::class);

        self::assertArrayHasKey('title', $all);
        self::assertArrayHasKey('noMeta', $all);
    }

    public function testPropertyWithFullAttributeStack(): void
    {
        $target = new class {
            #[Assert\NotBlank]
            #[Assert\Length(max: 100)]
            #[Groups(['content:page:read', 'content:page:write'])]
            #[FieldMeta(label: 'Title', formType: 'text', sortOrder: 1)]
            public string $title = '';
        };

        $meta = $this->reader->readAll($target::class)['title'];

        self::assertSame('Title', $meta->label);
        self::assertSame('text', $meta->formType);
        self::assertSame(1, $meta->sortOrder);

        // Layer 1 -- Assert constraints present
        self::assertArrayHasKey('NotBlank', $meta->constraints);
        self::assertArrayHasKey('Length', $meta->constraints);
        self::assertNull($meta->constraints['NotBlank']);                   // no args
        self::assertSame(['max' => 100], $meta->constraints['Length']);     // named arg

        // Layer 2 -- Groups split on ':write' convention
        self::assertSame(['content:page:read'], $meta->normalizationGroups);
        self::assertSame(['content:page:write'], $meta->denormalizationGroups);
    }

    public function testPropertyWithNoAttributes(): void
    {
        $target = new class {
            public string $noMeta = '';
        };

        $meta = $this->reader->readAll($target::class)['noMeta'];

        // Label derived from property name: 'noMeta' -> 'No Meta'
        self::assertSame('No Meta', $meta->label);
        self::assertNull($meta->formType);
        self::assertSame([], $meta->constraints);
        self::assertSame([], $meta->normalizationGroups);
        self::assertSame([], $meta->denormalizationGroups);
        self::assertSame([], $meta->securityRead);
        self::assertSame([], $meta->securityWrite);
        self::assertNull($meta->sortOrder);
        self::assertTrue($meta->showInForm);    // default
    }

    public function testReadPropertyByNameString(): void
    {
        $target = new class {
            #[FieldMeta(label: 'Title', formType: 'text', sortOrder: 1)]
            public string $title = '';
        };

        $meta = $this->reader->readProperty($target::class, 'title');

        self::assertSame('Title', $meta->label);
        self::assertSame('text', $meta->formType);
        self::assertSame(1, $meta->sortOrder);
    }

    public function testStaticPropertiesAreExcluded(): void
    {
        $target = new class {
            public string $instance = '';
            public static string $static = '';
        };

        $all = $this->reader->readAll($target::class);

        self::assertArrayHasKey('instance', $all);
        self::assertArrayNotHasKey('static', $all);
    }

    public function testFieldMetaConstraintsExtendAssertConstraints(): void
    {
        $target = new class {
            #[Assert\NotBlank]
            #[FieldMeta(constraints: ['Length' => ['max' => 255]])]
            public string $merged = '';
        };

        $meta = $this->reader->readAll($target::class)['merged'];

        // Both Assert\NotBlank and FieldMeta::constraints['Length'] are present
        self::assertArrayHasKey('NotBlank', $meta->constraints);
        self::assertArrayHasKey('Length', $meta->constraints);
    }

    public function testLabelFromCamelCaseName(): void
    {
        $target = new class {
            public string $metaTitle = '';
            public string $ogImageUrl = '';
        };

        $all = $this->reader->readAll($target::class);

        self::assertSame('Meta Title', $all['metaTitle']->label);
        self::assertSame('Og Image Url', $all['ogImageUrl']->label);
    }

    public function testShowInFormDefaultsToTrue(): void
    {
        $target = new class {
            #[FieldMeta(showInForm: false)]
            public string $hidden = '';

            public string $visible = '';
        };

        $all = $this->reader->readAll($target::class);

        self::assertFalse($all['hidden']->showInForm);
        self::assertTrue($all['visible']->showInForm);
    }

    public function testSecurityReadAndWrite(): void
    {
        $target = new class {
            #[FieldMeta(securityRead: ['ROLE_ADMIN'], securityWrite: ['ROLE_SUPER_ADMIN'])]
            public string $sensitive = '';
        };

        $meta = $this->reader->readAll($target::class)['sensitive'];

        self::assertSame(['ROLE_ADMIN'], $meta->securityRead);
        self::assertSame(['ROLE_SUPER_ADMIN'], $meta->securityWrite);
    }

    protected function setUp(): void
    {
        $this->reader = new FieldMetaReader();
    }
}
