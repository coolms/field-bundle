<?php

declare(strict_types=1);

namespace CoolMS\Field\Bundle\Tests\Reflection;

use CoolMS\Core\Attribute\FieldMeta;
use CoolMS\Core\Field\FieldConfigProviderInterface;
use CoolMS\Field\Bundle\Reflection\FieldMetaReader;
use CoolMS\Field\Entity\Definition;
use CoolMS\Field\Repository\DefinitionRepositoryInterface;
use CoolMS\Field\Service\FieldMetadataRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** Simple entity class used across registry tests. */
final class RegistryTestEntity
{
    #[FieldMeta(label: 'Attribute Title', formType: 'text', sortOrder: 5)]
    public string $title = '';

    public string $description = '';
}

/**
 * Lives in the bundle rather than beside the registry it tests: the registry
 * is exercised through the real `FieldMetaReader`, which is this package's
 * reflection seam. The domain package cannot see the reader, and stubbing it
 * would keep the subject and lose the integration.
 */
final class FieldMetadataRegistryTest extends TestCase
{
    private FieldMetaReader $reader;

    // --- tests ---------------------------------------------------------------

    /**
     * Test 1: Layers 1+2 only -- no providers, no DB rows -> reader result unchanged.
     */
    public function testLayersOneAndTwoOnlyReturnsReaderResult(): void
    {
        $registry = $this->makeRegistry($this->stubRepo());
        $fields = $registry->getAll(RegistryTestEntity::class, 'test_alias');

        self::assertArrayHasKey('title', $fields);
        self::assertArrayHasKey('description', $fields);
        self::assertSame('Attribute Title', $fields['title']->label);
        self::assertSame('text', $fields['title']->formType);
        self::assertSame(5, $fields['title']->sortOrder);
        // description has no FieldMeta -- label auto-derived from name
        self::assertSame('Description', $fields['description']->label);
    }

    /**
     * Test 2: Layer 3 wins over 1+2 -- provider overrides label.
     */
    public function testLayerThreeOverridesAttributeLabel(): void
    {
        $provider = $this->createStub(FieldConfigProviderInterface::class);
        $provider->method('getConfig')->willReturn([
            'title' => ['label' => 'Config Label'],
        ]);

        $registry = $this->makeRegistry($this->stubRepo(), $provider);
        $fields = $registry->getAll(RegistryTestEntity::class, 'test_alias');

        self::assertSame('Config Label', $fields['title']->label);
        // Other FieldMeta values must be preserved
        self::assertSame('text', $fields['title']->formType);
        self::assertSame(5, $fields['title']->sortOrder);
    }

    /**
     * Test 3: Layer 4 wins over 3 -- DB definition overrides label from provider.
     */
    public function testLayerFourWinsOverLayerThree(): void
    {
        $def = $this->makeDefinition('title', 'DB Label');

        $repo = $this->createStub(DefinitionRepositoryInterface::class);
        $repo->method('findByEntityAlias')->willReturn([$def]);

        $provider = $this->createStub(FieldConfigProviderInterface::class);
        $provider->method('getConfig')->willReturn([
            'title' => ['label' => 'Config Label'],
        ]);

        $registry = $this->makeRegistry($repo, $provider);
        $fields = $registry->getAll(RegistryTestEntity::class, 'test_alias');

        self::assertSame('DB Label', $fields['title']->label);
    }

    /**
     * Test 4: Cache hit -- DB repository called exactly once on two getAll() calls.
     */
    public function testCacheHitSkipsSecondPipelineRun(): void
    {
        $repo = $this->mockRepo();
        $repo->expects($this->once())
            ->method('findByEntityAlias')
            ->willReturn([]);

        $registry = $this->makeRegistry($repo);

        $first = $registry->getAll(RegistryTestEntity::class, 'test_alias');
        $second = $registry->getAll(RegistryTestEntity::class, 'test_alias');

        self::assertSame($first, $second);
    }

    /**
     * Test 5: Invalidate -- repository called again after invalidate().
     */
    public function testInvalidateClearsCache(): void
    {
        $repo = $this->mockRepo();
        $repo->expects($this->exactly(2))
            ->method('findByEntityAlias')
            ->willReturn([]);

        $registry = $this->makeRegistry($repo);

        $registry->getAll(RegistryTestEntity::class, 'test_alias');
        $registry->invalidate();
        $registry->getAll(RegistryTestEntity::class, 'test_alias');
    }

    /**
     * Invalidating a specific key clears only that entry; other aliases stay cached.
     */
    public function testInvalidateWithKeyOnlyClearsMatchingEntry(): void
    {
        $repo = $this->mockRepo();
        // alias_a, alias_b, alias_a-again = 3 calls total
        $repo->expects($this->exactly(3))
            ->method('findByEntityAlias')
            ->willReturn([]);

        $registry = $this->makeRegistry($repo);

        $registry->getAll(RegistryTestEntity::class, 'alias_a');
        $registry->getAll(RegistryTestEntity::class, 'alias_b');

        // Invalidate only alias_a -- alias_b stays in cache
        $registry->invalidate(RegistryTestEntity::class . '::alias_a');

        // alias_b: from cache (no extra call); alias_a: re-runs pipeline (1 extra call)
        $registry->getAll(RegistryTestEntity::class, 'alias_b');
        $registry->getAll(RegistryTestEntity::class, 'alias_a');
    }

    /**
     * DB Definition row for an unknown property (not in PHP class) is still included.
     */
    public function testDbDefinitionForUnknownPropertyIsAddedToResult(): void
    {
        $def = $this->makeDefinition('extra_field', 'Extra');

        $repo = $this->createStub(DefinitionRepositoryInterface::class);
        $repo->method('findByEntityAlias')->willReturn([$def]);

        $registry = $this->makeRegistry($repo);
        $fields = $registry->getAll(RegistryTestEntity::class, 'test_alias');

        self::assertArrayHasKey('extra_field', $fields);
        self::assertSame('Extra', $fields['extra_field']->label);
    }

    /**
     * Provider returning null is silently skipped.
     */
    public function testProviderReturningNullIsSkipped(): void
    {
        $provider = $this->createStub(FieldConfigProviderInterface::class);
        $provider->method('getConfig')->willReturn(null);

        $registry = $this->makeRegistry($this->stubRepo(), $provider);
        $fields = $registry->getAll(RegistryTestEntity::class, 'test_alias');

        // Provider did nothing -- attribute values preserved
        self::assertSame('Attribute Title', $fields['title']->label);
    }

    protected function setUp(): void
    {
        $this->reader = new FieldMetaReader();
    }

    // --- helpers -------------------------------------------------------------

    /** Stub repository that always returns an empty list. */
    private function stubRepo(): DefinitionRepositoryInterface
    {
        $stub = $this->createStub(DefinitionRepositoryInterface::class);
        $stub->method('findByEntityAlias')->willReturn([]);

        return $stub;
    }

    /** Mock repository for tests that assert exact call counts. */
    private function mockRepo(): DefinitionRepositoryInterface&MockObject
    {
        return $this->createMock(DefinitionRepositoryInterface::class);
    }

    private function makeRegistry(
        DefinitionRepositoryInterface $repo,
        FieldConfigProviderInterface ...$providers,
    ): FieldMetadataRegistry {
        return new FieldMetadataRegistry($this->reader, $repo, $providers);
    }

    private function makeDefinition(string $name, string $label): Definition
    {
        $def = new Definition();
        $def->name = $name;
        $def->label = $label;

        return $def;
    }
}
