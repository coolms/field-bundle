<?php

declare(strict_types=1);

namespace CoolMS\Field\Bundle\ApiPlatform\Resource\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use CoolMS\Core\Application\ApiPlatform\Input\ReorderInput;
use CoolMS\Field\Repository\DefinitionRepositoryInterface;

/** @implements ProcessorInterface<ReorderInput, void> */
final readonly class DefinitionReorderProcessor implements ProcessorInterface
{
    public function __construct(
        private DefinitionRepositoryInterface $repository,
    ) {
    }

    /**
     * @param ReorderInput $data
     */
    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): null {
        if ([] === $data->items) {
            return null;
        }

        $idToPosition = [];
        foreach ($data->items as $item) {
            $idToPosition[$item->id] = $item->sortOrder;
        }

        $this->repository->reorderBatch($idToPosition);

        return null;
    }
}
