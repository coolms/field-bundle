<?php

declare(strict_types=1);

namespace CoolMS\Field\Bundle\ApiPlatform\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use CoolMS\Field\Bundle\ApiPlatform\Resource\Provider\ConstraintMetadataCollectionProvider;
use CoolMS\Field\Bundle\Validation\ConstraintParameterDto;

/**
 * A validation constraint a field can carry: its name, its label and the
 * parameters it takes.
 */
// The names are the Symfony Validator constraints registered in the
// application; the admin's schema editor lists them when a constraint is added.
#[ApiResource(
    shortName: 'ConstraintMetadata',
    description: 'A validation constraint a field can carry: its name, its label and the parameters it takes.',
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
