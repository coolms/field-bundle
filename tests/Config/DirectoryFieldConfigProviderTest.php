<?php

declare(strict_types=1);

namespace CoolMS\Field\Bundle\Tests\Config;

use CoolMS\Core\Config\PhpFileLoader;
use CoolMS\Core\Config\XmlFileLoader;
use CoolMS\Core\Config\YamlFileLoader;
use CoolMS\Field\Bundle\Config\DirectoryFieldConfigProvider;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class DirectoryFieldConfigProviderTest extends TestCase
{
    private string $tmpDir;

    /**
     * Test 1: Returns parsed array when YAML file exists.
     */
    public function testReturnsArrayWhenYamlFileExists(): void
    {
        file_put_contents(
            $this->tmpDir . '/product.yaml',
            "title:\n  label: 'Product Title'\n  sortOrder: 0\n",
        );

        $result = $this->makeProvider()->getConfig('product');

        self::assertIsArray($result);
        self::assertArrayHasKey('title', $result);
        self::assertSame('Product Title', $result['title']['label']);
        self::assertSame(0, $result['title']['sortOrder']);
    }

    /**
     * Test 2: Returns null when no file exists for the alias.
     */
    public function testReturnsNullWhenNoFileForAlias(): void
    {
        $result = $this->makeProvider()->getConfig('nonexistent_alias');

        self::assertNull($result);
    }

    /**
     * Test 3: Tries yaml before xml before php (yaml wins when all exist).
     */
    public function testPrefersYamlOverXmlAndPhp(): void
    {
        file_put_contents(
            $this->tmpDir . '/entity.yaml',
            "field:\n  label: 'From YAML'\n",
        );
        file_put_contents(
            $this->tmpDir . '/entity.php',
            "<?php return ['field' => ['label' => 'From PHP']];\n",
        );

        $result = $this->makeProvider()->getConfig('entity');

        self::assertIsArray($result);
        self::assertSame('From YAML', $result['field']['label']);
    }

    /**
     * Test 3b: Falls back to PHP when neither yaml nor xml exist.
     */
    public function testFallsBackToPhpLoader(): void
    {
        file_put_contents(
            $this->tmpDir . '/entity.php',
            "<?php return ['field' => ['label' => 'From PHP']];\n",
        );

        $result = $this->makeProvider()->getConfig('entity');

        self::assertIsArray($result);
        self::assertSame('From PHP', $result['field']['label']);
    }

    /**
     * .yml extension is also accepted.
     */
    public function testAcceptsYmlExtension(): void
    {
        file_put_contents(
            $this->tmpDir . '/entity.yml',
            "name:\n  label: 'From YML'\n",
        );

        $result = $this->makeProvider()->getConfig('entity');

        self::assertIsArray($result);
        self::assertSame('From YML', $result['name']['label']);
    }

    /**
     * Returns null when directory has files for other aliases but not this one.
     */
    public function testReturnsNullWhenOtherAliasesExistButNotThis(): void
    {
        file_put_contents(
            $this->tmpDir . '/other.yaml',
            "field:\n  label: 'Other'\n",
        );

        $result = $this->makeProvider()->getConfig('product');

        self::assertNull($result);
    }

    /**
     * Trailing slash in configDir is handled correctly.
     */
    public function testHandlesTrailingSlashInConfigDir(): void
    {
        file_put_contents(
            $this->tmpDir . '/product.yaml',
            "title:\n  label: 'Title'\n",
        );

        $provider = new DirectoryFieldConfigProvider(
            projectDir: sys_get_temp_dir(),
            configDir: $this->tmpDir . '/',
            loaders: [new YamlFileLoader()],
        );

        $result = $provider->getConfig('product');

        self::assertIsArray($result);
        self::assertArrayHasKey('title', $result);
    }

    /**
     * Per-field files at {projectDir}/config/modules/{module}/fields/{alias}/{field}.yaml
     * are discovered and returned keyed by field name.
     * basename(dirname(file)) == alias and basename(file, .yaml) == fieldName.
     */
    public function testLoadsPerFieldFilesFromModulesDirectory(): void
    {
        // Build: {tmpDir}/config/modules/content/fields/my_alias/myField.yaml
        $fieldDir = $this->tmpDir . '/config/modules/content/fields/my_alias';
        mkdir($fieldDir, 0o777, true);
        file_put_contents(
            $fieldDir . '/myField.yaml',
            "type: text\nlabel: 'My Field'\nsortOrder: 5\n",
        );

        // projectDir = $this->tmpDir  ->  glob searches {tmpDir}/config/modules/*/fields/*/*.yaml
        $provider = new DirectoryFieldConfigProvider(
            projectDir: $this->tmpDir,
        );

        $result = $provider->getConfig('my_alias');

        self::assertIsArray($result);
        self::assertArrayHasKey('myField', $result);
        self::assertSame('text', $result['myField']['type']);
        self::assertSame('My Field', $result['myField']['label']);
        self::assertSame(5, $result['myField']['sortOrder']);
    }

    /**
     * Per-field files for a different alias are not included in the result.
     */
    public function testPerFieldFilesDoNotLeakAcrossAliases(): void
    {
        $otherDir = $this->tmpDir . '/config/modules/content/fields/other_alias';
        mkdir($otherDir, 0o777, true);
        file_put_contents($otherDir . '/someField.yaml', "type: text\nlabel: 'Other'\n");

        $provider = new DirectoryFieldConfigProvider(projectDir: $this->tmpDir);

        self::assertNull($provider->getConfig('my_alias'));
    }

    /**
     * Per-field files win over the legacy per-alias file when the same field name
     * is defined in both locations.
     */
    public function testPerFieldFilesOverrideLegacyPerAliasFile(): void
    {
        // Legacy per-alias file defines 'title' with label 'Legacy'
        file_put_contents(
            $this->tmpDir . '/product.yaml',
            "title:\n  label: 'Legacy'\n  sortOrder: 1\n",
        );

        // Per-field file redefines 'title' with label 'Override'
        $fieldDir = $this->tmpDir . '/config/modules/content/fields/product';
        mkdir($fieldDir, 0o777, true);
        file_put_contents(
            $fieldDir . '/title.yaml',
            "type: text\nlabel: 'Override'\nsortOrder: 99\n",
        );

        $provider = new DirectoryFieldConfigProvider(
            projectDir: $this->tmpDir,
            configDir: $this->tmpDir,
            loaders: [new YamlFileLoader()],
        );

        $result = $provider->getConfig('product');

        self::assertIsArray($result);
        self::assertSame('Override', $result['title']['label']);
        self::assertSame(99, $result['title']['sortOrder']);
    }

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/field_config_test_' . uniqid('', true);
        mkdir($this->tmpDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    /**
     * Recursively removes a directory and all its contents.
     * Handles both flat and nested structures created by tests.
     */
    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }

    private function makeProvider(): DirectoryFieldConfigProvider
    {
        return new DirectoryFieldConfigProvider(
            projectDir: sys_get_temp_dir(),
            configDir: $this->tmpDir,
            loaders: [new YamlFileLoader(), new XmlFileLoader(), new PhpFileLoader()],
        );
    }
}
