<?php

declare(strict_types=1);

namespace CoolMS\Field\Bundle\Validation;

use CoolMS\Field\Bundle\ApiPlatform\Resource\ConstraintMetadataResource;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Modules that want to expose custom Symfony Validator constraints in the
 * Schema Editor implement this interface.
 *
 * Implementations are auto-tagged as 'coolms.field.constraint_provider'
 * and collected by ConstraintRegistry.
 */
#[AutoconfigureTag('coolms.field.constraint_provider')]
interface ConstraintMetadataProviderInterface
{
    /** @return ConstraintMetadataResource[] */
    public function getConstraints(): array;
}
