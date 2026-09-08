<?php

declare(strict_types=1);

namespace CoolMS\FieldBundle\ApiPlatform\Resource\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use CoolMS\Field\Contract\FieldOverrideStorageInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Deletes a file- or DB-backed field override identified by ?entityAlias=&name= query params.
 *
 * Used by the Domain Explorer "Reset to default" action for entity-source fields whose
 * override was written as a YAML file (dev) rather than a DB FieldDefinition record (prod).
 * Routes through FieldOverrideStorageInterface so the correct storage backend is used.
 *
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class DefinitionDeleteByAliasProcessor implements ProcessorInterface
{
    public function __construct(
        private FieldOverrideStorageInterface $storage,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $request = $context['request'] ?? null;
        if (!$request instanceof Request) {
            throw new BadRequestHttpException('Request context is missing.');
        }

        $alias = trim($request->query->getString('entityAlias'));
        $fieldName = trim($request->query->getString('name'));

        if ('' === $alias) {
            throw new BadRequestHttpException('Query parameter "entityAlias" is required.');
        }

        if ('' === $fieldName) {
            throw new BadRequestHttpException('Query parameter "name" is required.');
        }

        $this->storage->delete($alias, $fieldName);

        return null;
    }
}
