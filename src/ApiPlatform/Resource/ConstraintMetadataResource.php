<?php

declare(strict_types=1);

namespace CoolMS\FieldBundle\ApiPlatform\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use CoolMS\FieldBundle\ApiPlatform\Resource\Provider\ConstraintMetadataCollectionProvider;
use CoolMS\FieldBundle\Validation\ConstraintParameterDto;

/**
 * Exposes registered Symfony Validator constraint metadata.
 * Used by the Schema Editor to populate the "Add Constraint" dropdown.
 */
#[ApiResource(
    shortName: 'ConstraintMetadata',
    operations: [
        new GetCollection(
            uriTemplate: '/dynamic-entity/constraints',
            name: 'dynamic_entity_constraints_list',
            provider: ConstraintMetadataCollectionProvider::class,
        ),
    ],
    security: "is_granted('ROLE_ADMIN')",
)]
final readonly class ConstraintMetadataResource
{
    /**
     * @param ConstraintParameterDto[] $parameters
     */
    public function __construct(
        public string $name,       // 'NotBlank', 'Length', 'Regex'
        public string $label,      // 'Required', 'String Length', 'Pattern'
        public array $parameters = [],
    ) {
    }
}
