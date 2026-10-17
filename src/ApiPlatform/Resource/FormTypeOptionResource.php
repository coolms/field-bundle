<?php

declare(strict_types=1);

namespace CoolMS\Field\Bundle\ApiPlatform\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use CoolMS\Field\Bundle\ApiPlatform\Resource\Provider\FormTypeOptionCollectionProvider;

/**
 * A form widget a field can be rendered with: its value and its label.
 */
// The values are Symfony form type classes; the admin's schema editor offers
// them when a field's widget is overridden.
#[ApiResource(
    shortName: 'FormTypeOption',
    description: 'A form widget a field can be rendered with: its value and its label.',
    operations: [
        new GetCollection(
            uriTemplate: '/field/form-types',
            name: 'field_form_types_list',
            provider: FormTypeOptionCollectionProvider::class,
        ),
    ],
    security: "is_granted('ROLE_ADMIN')",
)]
final readonly class FormTypeOptionResource
{
    public function __construct(
        public string $value,  // Symfony FormType FQCN, e.g. 'Symfony\...\TextType'
        public string $label,  // Human-readable, e.g. 'Text'
    ) {
    }
}
