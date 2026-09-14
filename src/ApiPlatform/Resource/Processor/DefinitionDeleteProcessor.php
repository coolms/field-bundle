<?php

declare(strict_types=1);

namespace CoolMS\Field\Bundle\ApiPlatform\Resource\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use CoolMS\Core\Bundle\ApiPlatform\UriVariableUuidExtractorTrait;
use CoolMS\Field\Bundle\ApiPlatform\Resource\DefinitionResource;
use CoolMS\Field\Entity\Definition;
use CoolMS\Field\Repository\DefinitionRepositoryInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProcessorInterface<mixed, void> */
final readonly class DefinitionDeleteProcessor implements ProcessorInterface
{
    use UriVariableUuidExtractorTrait;

    public function __construct(
        private DefinitionRepositoryInterface $repository,
    ) {
    }

    /**
     * @param DefinitionResource $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $uuid = $this->extractUuid($uriVariables);

        /** @var Definition|null $fd */
        $fd = $this->repository->find($uuid);
        if (null === $fd) {
            throw new NotFoundHttpException('Field definition not found.');
        }

        if ($fd->locked) {
            throw new AccessDeniedHttpException('This field is locked and cannot be deleted.');
        }

        $this->repository->delete($fd);

        return null;
    }
}
