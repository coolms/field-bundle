<?php

declare(strict_types=1);

namespace CoolMS\Field\Bundle\Tests\Form;

use CoolMS\Core\Attribute\FieldMeta;
use CoolMS\Core\Field\StaticEntityAliasProviderInterface;
use CoolMS\Field\Bundle\Form\StaticFieldMetaLoader;
use CoolMS\Field\Bundle\Reflection\FieldMetaReader;
use CoolMS\Field\Repository\DefinitionRepositoryInterface;
use CoolMS\Field\Service\FieldMetadataRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Helpers -- entity stubs that drive specific FieldMeta scenarios.
 * FieldMetaReader uses PHP reflection, so real attribute decorations are required.
 */

/** Field with formType set and showInForm = true (default). */
final class EntityWithFormType
{
    #[FieldMeta(label: 'Title', formType: 'text')]
    public string $title = '';
}

/** Field explicitly hidden from forms. */
final class EntityWithHiddenField
{
    #[FieldMeta(label: 'Secret', showInForm: false)]
    public string $secret = '';
}

/** Field with NotBlank constraint -- expects required = true in options. */
final class EntityWithNotBlank
{
    #[Assert\NotBlank]
    #[FieldMeta(label: 'Name')]
    public string $name = '';
}

/** Field with only a label -- no formType, no constraints. */
final class EntityLabelOnly
{
    #[FieldMeta(label: 'Bio')]
    public string $bio = '';
}

// ---------------------------------------------------------------------------

final class StaticFieldMetaLoaderTest extends TestCase
{
    private FieldMetaReader $reader;
    private DefinitionRepositoryInterface $repo;

    // --- tests ---------------------------------------------------------------

    /**
     * Test 1: Unknown formId (not in aliasMap) -> returns [].
     */
    public function testUnknownFormIdReturnsEmptyArray(): void
    {
        $loader = $this->makeLoader([]);

        self::assertSame([], $loader->loadFieldOverrides('unknown_form'));
    }

    /**
     * Test 2: showInForm = false -> field excluded from result.
     */
    public function testShowInFormFalseExcludesField(): void
    {
        $loader = $this->makeLoader(['my_form' => EntityWithHiddenField::class]);

        $result = $loader->loadFieldOverrides('my_form');

        self::assertArrayNotHasKey('secret', $result);
        self::assertSame([], $result);
    }

    /**
     * Test 3: showInForm = true, formType set -> entry has 'type' key.
     */
    public function testShowInFormTrueWithFormTypeProducesTypeKey(): void
    {
        $loader = $this->makeLoader(['my_form' => EntityWithFormType::class]);

        $result = $loader->loadFieldOverrides('my_form');

        self::assertArrayHasKey('title', $result);
        self::assertSame('text', $result['title']['type']);
        self::assertSame('Title', $result['title']['options']['label']);
    }

    /**
     * Test 4: NotBlank in constraints -> options['required'] = true.
     */
    public function testNotBlankConstraintSetsRequired(): void
    {
        $loader = $this->makeLoader(['my_form' => EntityWithNotBlank::class]);

        $result = $loader->loadFieldOverrides('my_form');

        self::assertArrayHasKey('name', $result);
        self::assertTrue($result['name']['options']['required']);
        self::assertArrayHasKey('constraints', $result['name']);
        self::assertArrayHasKey('NotBlank', $result['name']['constraints']);
    }

    /**
     * Test 5: No formType, no constraints -> entry has only 'options' with label.
     */
    public function testNoFormTypeNoConstraintsProducesOnlyOptions(): void
    {
        $loader = $this->makeLoader(['my_form' => EntityLabelOnly::class]);

        $result = $loader->loadFieldOverrides('my_form');

        self::assertArrayHasKey('bio', $result);
        self::assertArrayNotHasKey('type', $result['bio']);
        self::assertArrayNotHasKey('constraints', $result['bio']);
        self::assertSame('Bio', $result['bio']['options']['label']);
    }

    /**
     * Test 6: getPriority() returns 10.
     */
    public function testGetPriorityReturnsTen(): void
    {
        $loader = $this->makeLoader([]);

        self::assertSame(10, $loader->getPriority());
    }

    protected function setUp(): void
    {
        $this->reader = new FieldMetaReader();

        $repo = $this->createStub(DefinitionRepositoryInterface::class);
        $repo->method('findByEntityAlias')->willReturn([]);
        $this->repo = $repo;
    }

    // --- helpers -------------------------------------------------------------

    /**
     * Wraps a plain alias map in an anonymous provider so tests can use the
     * same convenient array syntax while exercising the real provider path.
     *
     * @param array<string, class-string> $aliasMap
     */
    private function makeLoader(array $aliasMap): StaticFieldMetaLoader
    {
        $registry = new FieldMetadataRegistry($this->reader, $this->repo, []);

        $providers = [] === $aliasMap ? [] : [
            new class($aliasMap) implements StaticEntityAliasProviderInterface {
                /** @param array<string, class-string> $map */
                public function __construct(private readonly array $map)
                {
                }

                public function getAliases(): array
                {
                    return $this->map;
                }
            },
        ];

        return new StaticFieldMetaLoader($registry, $providers);
    }
}
