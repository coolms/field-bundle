<?php

declare(strict_types=1);

namespace CoolMS\Field\Bundle\ApiPlatform\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use CoolMS\Field\Bundle\ApiPlatform\Resource\Provider\FormTypeOptionCollectionProvider;

/**
 * Exposes available Symfony form type options for the Schema Editor.
 * Used to populate the "Form Type" select in the static-override dialog.
 */
#[ApiResource(
    shortName: 'FormTypeOption',
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
