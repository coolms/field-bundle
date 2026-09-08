<?php

declare(strict_types=1);

namespace CoolMS\FieldBundle\Validation;

/**
 * Describes a single constructor parameter of a Symfony Validator constraint.
 */
final readonly class ConstraintParameterDto
{
    public function __construct(
        public string $name,
        public string $type,     // 'integer' | 'string' | 'boolean' | 'float'
        public bool $required = false,
        public mixed $default = null,
    ) {
    }
}
