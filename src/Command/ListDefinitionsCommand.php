<?php

declare(strict_types=1);

namespace CoolMS\FieldBundle\Command;

use CoolMS\Field\Entity\Definition;
use CoolMS\Field\Repository\DefinitionRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'coolms:field:definition:list',
    description: 'List field definitions (optionally filtered by entity alias).',
)]
final class ListDefinitionsCommand extends Command
{
    public function __construct(
        private readonly DefinitionRepositoryInterface $repository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'entity-alias',
            InputArgument::OPTIONAL,
            'Entity alias to filter by (omit to list all)',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $alias = $input->getArgument('entity-alias');

        if (null !== $alias) {
            /** @var Definition[] $definitions */
            $definitions = $this->repository->findBy(
                ['entityAlias' => $alias],
                ['sortOrder' => 'ASC'],
            );
            $io->title(sprintf('Field definitions for entity alias: %s', $alias));
        } else {
            /** @var Definition[] $definitions */
            $definitions = iterator_to_array($this->repository->findAll());
            $io->title('All field definitions');
        }

        if ([] === $definitions) {
            $io->info('No field definitions found.');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($definitions as $fd) {
            $rows[] = [
                substr($fd->id->toRfc4122(), 0, 8) . '…',
                $fd->entityAlias,
                $fd->name,
                $fd->type,
                $fd->label,
                $fd->sortOrder,
                $fd->isRequired ? '<comment>yes</comment>' : 'no',
            ];
        }

        $io->table(
            ['ID (short)', 'Entity Alias', 'Name', 'Type', 'Label', 'Sort', 'Required'],
            $rows,
        );

        $io->note(sprintf('%d field(s) found.', count($rows)));

        return Command::SUCCESS;
    }
}
