<?php

declare(strict_types=1);

namespace CoolMS\FieldBundle\DependencyInjection\Compiler;

use CoolMS\Field\Service\FieldMetadataRegistry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Collects every service tagged 'coolms.field.config_provider' and
 * injects them as the $configProviders constructor argument of
 * FieldMetadataRegistry.
 *
 * Mirrors LinkTargetResolverPass and EditorContributorPass: keeps the
 * collector wiring inside the owning module so the App\: prototype scan
 * in config/services.yaml cannot overwrite the explicit args, and so
 * the module is fully self-contained for future composer extraction.
 *
 * No-op when FieldMetadataRegistry isn't registered (e.g., partial
 * test boot that excludes the Field bundle).
 */
final class FieldMetadataRegistryPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(FieldMetadataRegistry::class)) {
            return;
        }

        $taggedServices = $container->findTaggedServiceIds('coolms.field.config_provider');
        $references = array_map(
            static fn (string $id): Reference => new Reference($id),
            array_keys($taggedServices),
        );

        $container->getDefinition(FieldMetadataRegistry::class)
            ->setArgument('$configProviders', $references);
    }
}
