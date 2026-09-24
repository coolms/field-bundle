<?php

declare(strict_types=1);

namespace CoolMS\Field\Bundle\Tests\ApiPlatform\Resource;

use CoolMS\Field\Bundle\ApiPlatform\Resource\DefinitionResource;
use CoolMS\Field\Entity\Definition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `options.system` marks a row the sync warmer owns -- and will delete once no
 * YAML declares it. A request body must be able neither to set it nor to clear
 * it; every other key of the bag stays the client's to write.
 */
final class DefinitionResourceSystemMarkerTest extends TestCase
{
    #[Test]
    public function aNewDefinitionCannotBeMarkedByItsBody(): void
    {
        $fd = new Definition();

        $body = new DefinitionResource(options: ['system' => true, 'group' => 'billing']);
        $body->applyTo($fd);

        self::assertArrayNotHasKey('system', $fd->options);
        self::assertSame('billing', $fd->options['group'], 'the rest of the bag is still written');
    }

    #[Test]
    public function anOperatorsDefinitionCannotBeMarkedByAnUpdate(): void
    {
        $fd = new Definition();
        $fd->options = ['label' => 'Colour'];

        $body = new DefinitionResource(options: ['label' => 'Colour', 'system' => true]);
        $body->applyTo($fd);

        self::assertArrayNotHasKey('system', $fd->options);
    }

    #[Test]
    public function aBagWithoutTheMarkerLeavesTheWarmersRowTheWarmers(): void
    {
        $fd = new Definition();
        $fd->options = ['system' => true, 'label' => 'Before'];

        $body = new DefinitionResource(options: ['label' => 'After']);
        $body->applyTo($fd);

        self::assertTrue($fd->options['system']);
        self::assertSame('After', $fd->options['label']);
    }

    #[Test]
    public function aBodyCannotClearTheMarker(): void
    {
        $fd = new Definition();
        $fd->options = ['system' => true];

        $body = new DefinitionResource(options: ['system' => false]);
        $body->applyTo($fd);

        self::assertTrue($fd->options['system']);
    }

    #[Test]
    public function aBodyWithoutABagKeepsTheMarker(): void
    {
        $fd = new Definition();
        $fd->options = ['system' => true, 'label' => 'Kept'];

        $body = new DefinitionResource(label: 'Renamed');
        $body->applyTo($fd);

        self::assertTrue($fd->options['system']);
        self::assertSame('Renamed', $fd->options['label']);
    }
}
