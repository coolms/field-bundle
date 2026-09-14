<?php

declare(strict_types=1);

namespace CoolMS\Field\Bundle\Tests\CacheWarmer;

use CoolMS\Core\Service\DataFormat;
use CoolMS\Entity\Factory\EntityFactoryFactoryInterface;
use CoolMS\Entity\Factory\EntityFactoryInterface;
use CoolMS\Entity\Registry\EntityAliasRegistry;
use CoolMS\Field\Bundle\CacheWarmer\FieldDefinitionSyncWarmer;
use CoolMS\Field\Bundle\Config\DirectoryFieldConfigProvider;
use CoolMS\Field\Bundle\Tests\Fixture\AliasedEntity;
use CoolMS\Field\Doctrine\EntityFieldNamesResolverInterface;
use CoolMS\Field\Entity\Definition;
use CoolMS\Field\Repository\DefinitionRepositoryInterface;
use FilesystemIterator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Covers the deletion side of the warmer's ownership contract (#1981).
 *
 * The upsert side has always had one: a `system: true` row is rebuilt from YAML on
 * every warmup and a user-owned row is left alone. The delete side had none, so a
 * row whose YAML was removed stayed forever -- `vfs_node.publiclyAccessible` sat
 * unowned from `Version20260521000002` until `schema:sync` built a generated column
 * from it. These pin the deletion rule and, just as importantly, the three cases it
 * must NOT reach.
 *
 * `DirectoryFieldConfigProvider` is the REAL one over a temporary project dir, not a
 * double: what counts as "declared" is the on-disk glob it performs, and a stubbed
 * config array would let the prune agree with a rule the loader does not actually
 * apply. The YAML files below are what a module ships.
 */
final class FieldDefinitionSyncWarmerTest extends TestCase
{
    private const string ALIAS = 'vfs_node';

    private string $projectDir;
    private DefinitionRepositoryInterface&MockObject $repository;

    /** @var array<string, true> */
    private array $nativeFields = [];

    /**
     * The reported defect: YAML gone, `system: true`, so the row is the warmer's
     * and nothing else will ever clean it up.
     */
    public function testDeletesSystemRowTheYamlNoLongerDeclares(): void
    {
        $orphan = $this->definition('publiclyAccessible', system: true);
        $this->declareField('title');
        $this->givenExisting($this->definition('title', system: true), $orphan);

        $this->repository->expects(self::once())->method('delete')->with($orphan);

        $this->warmer()->warmUp('/tmp/cache');
    }

    /**
     * A row with no `system` marker was authored through the UI. The warmer does not
     * own it and must not delete it just because no YAML mentions the name -- that is
     * the normal state of every UI-created field.
     */
    public function testKeepsUserOwnedRowTheYamlDoesNotDeclare(): void
    {
        $this->declareField('title');
        $this->givenExisting(
            $this->definition('title', system: true),
            $this->definition('operatorField', system: false),
        );

        $this->repository->expects(self::never())->method('delete');

        $this->warmer()->warmUp('/tmp/cache');
    }

    /**
     * The upsert loop refuses to CREATE a row for a Doctrine-mapped column, because
     * its v_* column would read `extras` and always be NULL. The prune is that rule
     * read backwards: `vfs_node.description` is such a row, and deleting it would
     * strip a native column's field from the admin schema.
     */
    public function testKeepsSystemRowWhoseNameIsANativeColumn(): void
    {
        $this->declareField('title');
        $this->nativeFields = ['description' => true];
        $this->givenExisting(
            $this->definition('title', system: true),
            $this->definition('description', system: true),
        );

        $this->repository->expects(self::never())->method('delete');

        $this->warmer()->warmUp('/tmp/cache');
    }

    /**
     * An empty config cannot be told apart from a module directory that is missing,
     * so the alias is skipped whole. Without this, six live `page_variant` rows --
     * whose YAML directory does not exist -- would be deleted on the next warmup.
     */
    public function testPrunesNothingForAnAliasThatDeclaresNoFieldsAtAll(): void
    {
        $this->givenExisting($this->definition('contentHash', system: true));

        $this->repository->expects(self::never())->method('delete');

        $this->warmer()->warmUp('/tmp/cache');
    }

    /** A declared field is refreshed, never removed. */
    public function testKeepsSystemRowStillDeclaredByYaml(): void
    {
        $this->declareField('title');
        $this->givenExisting($this->definition('title', system: true));

        $this->repository->expects(self::never())->method('delete');
        $this->repository->expects(self::once())->method('save');

        $this->warmer()->warmUp('/tmp/cache');
    }

    /**
     * One module dropping a field does not take another module's field for the same
     * alias with it: the provider merges every `config/modules/*` contribution, so
     * "declared" is the union.
     */
    public function testKeepsSystemRowDeclaredByADifferentModuleForTheSameAlias(): void
    {
        $this->declareField('title', module: 'content');
        $this->declareField('templateId', module: 'document');
        $this->givenExisting(
            $this->definition('title', system: true),
            $this->definition('templateId', system: true),
        );

        $this->repository->expects(self::never())->method('delete');

        $this->warmer()->warmUp('/tmp/cache');
    }

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/warmer-' . uniqid('', true);
        mkdir($this->projectDir . '/config/modules', 0o777, true);
        $this->repository = $this->createMock(DefinitionRepositoryInterface::class);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->projectDir)) {
            return;
        }
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->projectDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->projectDir);
    }

    /** Writes the field YAML a module ships, which is what "declared" means. */
    private function declareField(string $fieldName, string $module = 'content'): void
    {
        $dir = sprintf('%s/config/modules/%s/fields/%s', $this->projectDir, $module, self::ALIAS);
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }
        file_put_contents(
            $dir . '/' . $fieldName . '.yaml',
            sprintf("label: '%s'\ntype: text\n", ucfirst($fieldName)),
        );
    }

    private function givenExisting(Definition ...$definitions): void
    {
        $this->repository->method('findByEntityAlias')->willReturn($definitions);
    }

    private function definition(string $name, bool $system): Definition
    {
        $fd = new Definition();
        $fd->entityAlias = self::ALIAS;
        $fd->name = $name;
        $fd->type = 'text';
        if ($system) {
            $fd->options['system'] = true;
        }

        return $fd;
    }

    private function warmer(): FieldDefinitionSyncWarmer
    {
        $resolver = $this->createStub(EntityFieldNamesResolverInterface::class);
        $resolver->method('resolve')->willReturnCallback(fn (): array => $this->nativeFields);

        $factory = $this->createStub(EntityFactoryInterface::class);
        $factory->method('create')->willReturnCallback(
            /** @param array<string, mixed>|string $data */
            fn (array|string $data, ?DataFormat $format = null, array $context = []): Definition => $this->definition(is_array($data) ? (string) $data['name'] : '', system: false),
        );
        $factoryFactory = $this->createStub(EntityFactoryFactoryInterface::class);
        $factoryFactory->method('get')->willReturn($factory);

        return new FieldDefinitionSyncWarmer(
            new EntityAliasRegistry([AliasedEntity::class => self::ALIAS]),
            new DirectoryFieldConfigProvider($this->projectDir),
            $this->repository,
            $factoryFactory,
            $resolver,
            new NullLogger(),
        );
    }
}
