<?php

declare(strict_types=1);

namespace CoolMS\FieldBundle\ApiPlatform\Resource\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use CoolMS\FieldBundle\ApiPlatform\Resource\FormTypeOptionResource;
use CoolMS\Field\Registry\FormTypeRegistry;

/** @implements ProviderInterface<FormTypeOptionResource> */
final readonly class FormTypeOptionCollectionProvider implements ProviderInterface
{
    public function __construct(private FormTypeRegistry $registry)
    {
    }

    /**
     * @return FormTypeOptionResource[]
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        return array_map(
            static fn (array $opt) => new FormTypeOptionResource(
                value: $opt['value'],
                label: $opt['label'],
            ),
            $this->registry->all(),
        );
    }
}
