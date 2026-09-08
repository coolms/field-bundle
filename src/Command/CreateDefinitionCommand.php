<?php

declare(strict_types=1);

namespace CoolMS\FieldBundle\Command;

use CoolMS\Core\Field\ReservedFieldNameException;
use CoolMS\Core\Field\ReservedFieldNames;
use CoolMS\Field\Entity\Definition;
use CoolMS\Field\Registry\FieldTypeMap;
use CoolMS\Field\Repository\DefinitionRepositoryInterface;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'coolms:field:definition:create',
    description: 'Create a new field definition.',
)]
final class CreateDefinitionCommand extends Command
{
    /**
     * The same vocabulary the schema editor offers: {@see Definition::$type} is
     * a WIDGET word, and {@see FieldTypeMap} is what turns it into a storage
     * type. Offering `Definition::DATA_TYPES` here instead -- as this did -- put
     * a second vocabulary into the column from the CLI side, which is the
     * defect FieldTypeMap exists to close.
     *
     * Pick `money` over `number` for a total: `money` is fixed-point, `number`
     * maps to a binary double and rounds. `integer` is exact where `number` is
     * not; see the map's docblock.
     *
     * @var list<string>
     */
    private const array FIELD_TYPES = FieldTypeMap::AUTHORABLE;

    public function __construct(
        private readonly DefinitionRepositoryInterface $repository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('entity-alias', null, InputOption::VALUE_REQUIRED, 'Entity alias (e.g. product, customer)')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Field name (lowercase, underscores allowed)')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Field type (' . implode('|', self::FIELD_TYPES) . ')')
            ->addOption('label', null, InputOption::VALUE_REQUIRED, 'Human-readable label (optional)')
            ->addOption('required', null, InputOption::VALUE_NONE, 'Mark field as required (adds NotBlank constraint)')
            ->addOption('sort-order', null, InputOption::VALUE_REQUIRED, 'Sort order in forms (default 0)', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $helper = $this->getHelper('question');
        assert($helper instanceof QuestionHelper);

        // --- entityAlias ---
        $entityAlias = $input->getOption('entity-alias');
        if (null === $entityAlias) {
            $q = new Question('Entity alias (e.g. <info>product</info>): ');
            $q->setValidator(static fn ($v) => ('' === trim((string) $v))
                ? throw new InvalidArgumentException('Entity alias cannot be empty.') : trim($v));
            $entityAlias = $helper->ask($input, $output, $q);
        }

        // --- name ---
        $name = $input->getOption('name');
        if (null === $name) {
            $q = new Question('Field name (lowercase, underscores): ');
            $q->setValidator(static function ($v) {
                $v = trim((string) $v);
                if (!preg_match('/^[a-z0-9_]+$/', $v)) {
                    throw new InvalidArgumentException('Field name must match /^[a-z0-9_]+$/.');
                }

                return $v;
            });
            $name = $helper->ask($input, $output, $q);
        }

        // --- type ---
        $type = $input->getOption('type');
        if (null === $type) {
            $q = new ChoiceQuestion('Field type', self::FIELD_TYPES, 0);
            $type = $helper->ask($input, $output, $q);
        }

        // --- label (optional) ---
        $label = $input->getOption('label');
        if (null === $label) {
            $q = new Question(sprintf('Label [<info>%s</info>]: ', ucfirst($name)), '');
            $label = trim((string) $helper->ask($input, $output, $q));
        }

        $isRequired = (bool) $input->getOption('required');
        $sortOrder = (int) $input->getOption('sort-order');

        // Guard against reserved field names before persisting
        if (ReservedFieldNames::isReserved($name)) {
            throw ReservedFieldNameException::forField($name);
        }

        // Build entity
        $fd = new Definition();
        $fd->entityAlias = $entityAlias;
        $fd->name = $name;
        $fd->type = $type;
        $fd->sortOrder = $sortOrder;
        if ('' !== $label) {
            $fd->label = $label;
        }
        if ($isRequired) {
            $fd->validationRules = array_merge($fd->validationRules, ['NotBlank' => null]);
        }

        $this->repository->save($fd);

        $io->success(sprintf(
            'Definition created: [%s] %s.%s (%s) -- id: %s',
            $type,
            $entityAlias,
            $name,
            $isRequired ? 'required' : 'optional',
            $fd->id->toRfc4122(),
        ));

        return Command::SUCCESS;
    }
}
