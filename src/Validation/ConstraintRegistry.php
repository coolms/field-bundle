<?php

declare(strict_types=1);

namespace CoolMS\Field\Bundle\Validation;

use CoolMS\Field\Bundle\ApiPlatform\Resource\ConstraintMetadataResource;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Collects and exposes metadata about available Symfony Validator constraints.
 *
 * Core constraints are registered as built-ins. Modules can register additional
 * constraints by implementing ConstraintMetadataProviderInterface (auto-tagged).
 */
final readonly class ConstraintRegistry
{
    /** @param iterable<ConstraintMetadataProviderInterface> $providers */
    public function __construct(
        #[AutowireIterator('coolms.field.constraint_provider')]
        private iterable $providers,
    ) {
    }

    /**
     * Returns all registered constraint metadata, built-ins first then module-provided.
     *
     * @return ConstraintMetadataResource[]
     */
    public function all(): array
    {
        $constraints = $this->builtIn();

        foreach ($this->providers as $provider) {
            foreach ($provider->getConstraints() as $constraint) {
                $constraints[] = $constraint;
            }
        }

        return $constraints;
    }

    // -------------------------------------------------------------------------

    /** @return ConstraintMetadataResource[] */
    private function builtIn(): array
    {
        $p = ConstraintParameterDto::class;

        return [
            new ConstraintMetadataResource(
                name: 'NotBlank',
                label: 'Required',
            ),
            new ConstraintMetadataResource(
                name: 'Length',
                label: 'String Length',
                parameters: [
                    new $p(name: 'min', type: 'integer'),
                    new $p(name: 'max', type: 'integer'),
                ],
            ),
            new ConstraintMetadataResource(
                name: 'Range',
                label: 'Number Range',
                parameters: [
                    new $p(name: 'min', type: 'float'),
                    new $p(name: 'max', type: 'float'),
                ],
            ),
            new ConstraintMetadataResource(
                name: 'Regex',
                label: 'Pattern',
                parameters: [
                    new $p(name: 'pattern', type: 'string', required: true),
                ],
            ),
            new ConstraintMetadataResource(
                name: 'Email',
                label: 'Email Address',
            ),
            new ConstraintMetadataResource(
                name: 'Url',
                label: 'URL',
            ),
            new ConstraintMetadataResource(
                name: 'Count',
                label: 'Collection Count',
                parameters: [
                    new $p(name: 'min', type: 'integer'),
                    new $p(name: 'max', type: 'integer'),
                ],
            ),
            new ConstraintMetadataResource(
                name: 'Positive',
                label: 'Positive Number',
            ),
            new ConstraintMetadataResource(
                name: 'NotNull',
                label: 'Not Null',
            ),
        ];
    }
}
