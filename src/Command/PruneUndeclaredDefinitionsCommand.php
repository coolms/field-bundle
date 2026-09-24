<?php

declare(strict_types=1);

namespace CoolMS\Field\Bundle\Command;

use CoolMS\Field\Bundle\Maintenance\UndeclaredDefinitionPruner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The explicit step that deletes the sync warmer's field definitions no YAML
 * declares any more. It ran inside every cache warm-up until 2026-09-24; see
 * {@see UndeclaredDefinitionPruner} for why a warm-up never deletes and for the
 * rule itself.
 *
 * A dry run unless `--execute`; under `--no-interaction` it refuses to act
 * without `--force`; it reports "N of M".
 */
#[AsCommand(
    name: 'coolms:field:prune-undeclared',
    description: 'Delete the system field definitions no module YAML declares any more. A dry run unless --execute.',
)]
final class PruneUndeclaredDefinitionsCommand extends Command
{
    public function __construct(
        private readonly UndeclaredDefinitionPruner $pruner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Delete. Without it the command reports and changes nothing.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Do not ask. Required with --execute under --no-interaction.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $execute = (bool) $input->getOption('execute');
        $force = (bool) $input->getOption('force');
        if ($execute && !$input->isInteractive() && !$force) {
            $io->error('Refusing to delete unattended: --execute under --no-interaction needs --force.');

            return Command::INVALID;
        }

        ['undeclared' => $undeclared, 'examined' => $examined] = $this->pruner->plan();
        $io->writeln(sprintf(
            '%s %d of %d field definition(s): the warmer\'s own, no longer declared by any YAML.',
            $execute ? 'Deleting' : 'Would delete',
            count($undeclared),
            $examined,
        ));
        foreach ($undeclared as $fd) {
            $io->writeln(sprintf('  %s.%s', $fd->entityAlias, $fd->name));
        }

        if (!$execute) {
            $io->note('Dry run: nothing was changed. Pass --execute to delete.');

            return Command::SUCCESS;
        }
        if ([] === $undeclared) {
            return Command::SUCCESS;
        }
        if (!$force && !$io->confirm(sprintf('Delete these %d definition(s)?', count($undeclared)), false)) {
            $io->comment('Nothing was changed.');

            return Command::SUCCESS;
        }

        $io->success(sprintf('Deleted %d of %d field definition(s).', $this->pruner->prune($undeclared), $examined));

        return Command::SUCCESS;
    }
}
