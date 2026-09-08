<?php

declare(strict_types=1);

namespace CoolMS\FieldBundle\ApiPlatform\Resource\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use CoolMS\FieldBundle\ApiPlatform\Resource\ConstraintMetadataResource;
use CoolMS\FieldBundle\Validation\ConstraintRegistry;

/**
 * Provides the list of all registered constraint metadata for the Schema Editor.
 *
 * @implements ProviderInterface<object>
 */
final readonly class ConstraintMetadataCollectionProvider implements ProviderInterface
{
    public function __construct(private ConstraintRegistry $registry)
    {
    }

    /**
     * @return ConstraintMetadataResource[]
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        return $this->registry->all();
    }
}
