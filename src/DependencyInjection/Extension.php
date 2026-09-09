<?php

declare(strict_types=1);

namespace CoolMS\Field\Bundle\DependencyInjection;

use CoolMS\Field\Bundle\ApiPlatform\Resource\Processor\DefinitionCreateProcessor;
use CoolMS\Field\Bundle\ApiPlatform\Resource\Processor\DefinitionDeleteProcessor;
use CoolMS\Field\Bundle\ApiPlatform\Resource\Processor\DefinitionUpdateProcessor;
use CoolMS\Field\Bundle\ApiPlatform\Resource\Provider\DefinitionProvider;
use CoolMS\Field\Bundle\ApiPlatform\Resource\Provider\FormTypeOptionCollectionProvider;
use CoolMS\Field\Bundle\CacheWarmer\FieldDefinitionSyncWarmer;
use CoolMS\Field\Bundle\Command\CreateDefinitionCommand;
use CoolMS\Field\Bundle\Command\ListDefinitionsCommand;
use CoolMS\Field\Bundle\Config\DirectoryFieldConfigProvider;
use CoolMS\Field\Bundle\EntitySchema\FieldSchemaSource;
use CoolMS\Field\Bundle\Form\StaticFieldMetaLoader;
use CoolMS\Field\Bundle\FormType\BuiltinFormTypeProvider;
use CoolMS\Field\Bundle\Reflection\FieldMetaReader;
use CoolMS\Field\Bundle\Storage\DbFieldOverrideStorage;
use CoolMS\Field\Bundle\Storage\FieldOverrideStorageRouter;
use CoolMS\Field\Bundle\Storage\FileFieldOverrideStorage;
use CoolMS\Core\Field\FieldConfigProviderInterface;
use CoolMS\Core\Field\FieldWidgetProviderInterface;
use CoolMS\Core\Field\FormTypeProviderInterface;
use CoolMS\Core\Field\StaticEntityAliasProviderInterface;
use CoolMS\Core\Bundle\DependencyInjection\AbstractExtension;
use CoolMS\Entity\Contract\FieldMetadataSourceInterface;
use CoolMS\Entity\Contract\FieldSchemaSourceInterface;
use CoolMS\Entity\Factory\EntityFactoryFactoryInterface;
use CoolMS\Entity\Registry\EntityAliasRegistry;
use CoolMS\Entity\Bundle\DependencyInjection\EntityFactoryRegistrationTrait;
use CoolMS\Field\Contract\FieldMetaReaderInterface;
use CoolMS\Field\Contract\FieldOverrideStorageInterface;
use CoolMS\Field\Contract\FieldWidgetRegistryInterface;
use CoolMS\Field\Doctrine\DoctrineEntityFieldNamesResolver;
use CoolMS\Field\Doctrine\EntityFieldNamesResolverInterface;
use CoolMS\Field\Doctrine\Repository\DefinitionRepository;
use CoolMS\Field\Entity\Definition;
use CoolMS\Field\Entity\DefinitionInterface;
use CoolMS\Field\Registry\FieldWidgetRegistry;
use CoolMS\Field\Registry\FormTypeRegistry;
use CoolMS\Field\Repository\DefinitionRepositoryInterface;
use CoolMS\Field\Service\ConstraintBuilder;
use CoolMS\Field\Service\FieldMetadataRegistry;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Reference;

class Extension extends AbstractExtension implements PrependExtensionInterface
{
    use EntityFactoryRegistrationTrait;

    private const array RESOLVE_TARGET_ENTITIES = [
        DefinitionInterface::class => Definition::class,
    ];

    public function load(array $configs, ContainerBuilder $container): void
    {
        $this->setResolveTargetEntities($container, self::RESOLVE_TARGET_ENTITIES);
        $this->registerEntityFactory($container, array_keys(self::RESOLVE_TARGET_ENTITIES));

        // FieldConfigProvider implementations are auto-collected via this tag.
        $container->registerForAutoconfiguration(FieldConfigProviderInterface::class)
            ->addTag('coolms.field.config_provider');

        // FormTypeProvider implementations are auto-tagged so third-party bundles can extend the list.
        $container->registerForAutoconfiguration(FormTypeProviderInterface::class)
            ->addTag('coolms.field.form_type');

        // BuiltinFormTypeProvider -- provides the standard Symfony core form types.
        // Infrastructure/ is auto-scanned by services.yaml; tag is applied via autoconfiguration above.
        $container->autowire(BuiltinFormTypeProvider::class)->setPublic(false);

        // FormTypeRegistry -- lives in Domain/Registry/ which is excluded from auto-wiring.
        $container->register(FormTypeRegistry::class)
            ->setArgument('$providers', new TaggedIteratorArgument('coolms.field.form_type'))
            ->setPublic(false);

        // FormTypeOptionCollectionProvider -- must be public for API Platform state resolution.
        $container->autowire(FormTypeOptionCollectionProvider::class)->setPublic(true);

        // FieldWidgetProvider implementations (a module => admin field-widget for a
        // field type) are auto-tagged; a provider only exists while its module is
        // installed, so the widget is offered only then (e.g. Tag => `tags`).
        $container->registerForAutoconfiguration(FieldWidgetProviderInterface::class)
            ->addTag('coolms.field.widget_provider');

        // FieldWidgetRegistry -- lives in Domain/Registry/ (excluded from the App\:
        // glob, so the tagged-iterator arg can't be overwritten).
        $container->register(FieldWidgetRegistry::class)
            ->setArgument('$providers', new TaggedIteratorArgument('coolms.field.widget_provider'))
            ->setPublic(false);
        $container->setAlias(FieldWidgetRegistryInterface::class, FieldWidgetRegistry::class)
            ->setPublic(false);

        $container->autowire(DefinitionRepository::class)
            ->addTag('doctrine.repository_service');
        $container->setAlias(DefinitionRepositoryInterface::class, DefinitionRepository::class)
            ->setPublic(true);

        // ApiPlatform
        $container->autowire(DefinitionProvider::class)->setPublic(true);
        $container->autowire(DefinitionCreateProcessor::class)->setPublic(true);
        $container->autowire(DefinitionUpdateProcessor::class)->setPublic(true);
        $container->autowire(DefinitionDeleteProcessor::class)->setPublic(true);

        // Reflection. The contract lives in coolms/field and the reader
        // implements it here, so the alias is what lets a consumer depend on
        // the package's interface rather than on this module's class.
        $container->autowire(FieldMetaReader::class)->setPublic(false);
        $container->setAlias(FieldMetaReaderInterface::class, FieldMetaReader::class)
            ->setPublic(false);

        // Constraint building
        $container->autowire(ConstraintBuilder::class)->setPublic(false);

        // Registry. `$configProviders` is filled by FieldMetadataRegistryPass at
        // compile time, and the lockdown flags stop the container re-autowiring
        // the service after registration.
        $container->register(FieldMetadataRegistry::class)
            // !! EVERY argument is set here, explicitly, and that is not belt
            // and braces. While this class lived in the host application's own
            // namespace, the application's service glob autowired it and
            // quietly completed a registration that reads as complete. Moving
            // it into a package took the glob away and the container failed
            // with "Argument #1 ($reader) not passed" -- the registration had
            // never been self-sufficient, and the lockdown comment above
            // described a guard that was doing the opposite of what it looked
            // like. A registration in a package cannot borrow the host's
            // autowiring, so it has to name what it needs.
            ->setArgument('$reader', new Reference(FieldMetaReaderInterface::class))
            ->setArgument('$repository', new Reference(DefinitionRepositoryInterface::class))
            ->setArgument('$configProviders', [])
            ->setAutowired(false)
            ->setAutoconfigured(false)
            ->setPublic(true);

        // Entity extras seam. Entity (L0) owns the extras engine and declares
        // both ports; Field implements them, so the dependency points DOWN and
        // Entity never names a Field class. Registered explicitly because
        // FieldMetadataRegistry above is locked down (autowire off), and an
        // adapter is exactly the kind of two-line service the App\: scan would
        // otherwise re-register with different arguments.
        $container->register(FieldSchemaSource::class)
            ->setArgument('$repository', new Reference(DefinitionRepositoryInterface::class))
            ->setArgument('$metadataRegistry', new Reference(FieldMetadataRegistry::class))
            ->setAutowired(false)
            ->setAutoconfigured(false)
            ->setPublic(false);
        $container->setAlias(FieldSchemaSourceInterface::class, FieldSchemaSource::class)
            ->setPublic(false);
        $container->setAlias(FieldMetadataSourceInterface::class, FieldSchemaSource::class)
            ->setPublic(false);

        // Alias providers -- modules implement StaticEntityAliasProviderInterface and are
        // auto-collected via this tag into StaticFieldMetaLoader.
        $container->registerForAutoconfiguration(StaticEntityAliasProviderInterface::class)
            ->addTag('coolms.field.static_entity_alias_provider');

        // Form bridge -- tag added by FormBundle::registerForAutoconfiguration(FormConfigLoaderInterface)
        $container->autowire(StaticFieldMetaLoader::class)
            ->setArgument(
                '$aliasProviders',
                new TaggedIteratorArgument('coolms.field.static_entity_alias_provider'),
            )
            ->setPublic(false);

        // -- Field override storage --------------------------------------------
        // Registered explicitly so all scalar args are wired here in the Extension.
        // No #[Autowire] attributes needed -- this mirrors the pattern used by all
        // other bundle Extensions in the codebase and will work unchanged when the
        // module is extracted to a Coolms\ composer package.

        $container->register(DbFieldOverrideStorage::class)
            ->setArgument('$repository', new Reference(DefinitionRepositoryInterface::class))
            ->setArgument('$entityFactoryFactory', new Reference(EntityFactoryFactoryInterface::class))
            ->setAutowired(false)
            ->setPublic(false);

        $container->register(FileFieldOverrideStorage::class)
            ->setArgument('$projectDir', '%kernel.project_dir%')
            ->setArgument('$aliases', new Reference(EntityAliasRegistry::class))
            ->setAutowired(false)
            ->setPublic(false);

        $container->register(FieldOverrideStorageRouter::class)
            ->setArgument('$env', '%kernel.environment%')
            ->setArgument('$fileStorage', new Reference(FileFieldOverrideStorage::class))
            ->setArgument('$dbStorage', new Reference(DbFieldOverrideStorage::class))
            ->setArgument('$aliasRegistry', new Reference(EntityAliasRegistry::class))
            ->setAutowired(false)
            ->setPublic(false);

        $container->setAlias(FieldOverrideStorageInterface::class, FieldOverrideStorageRouter::class)
            ->setPublic(false);

        // -- Per-field file config provider ------------------------------------
        // Scans config/modules/*/fields/{alias}/*.yaml (new format) alongside
        // any legacy per-alias file when $configDir is set.
        $container->register(DirectoryFieldConfigProvider::class)
            ->setArgument('$projectDir', '%kernel.project_dir%')
            ->setPublic(false)
            ->addTag('coolms.field.config_provider');

        // Native field-names resolver -- used by the warmer to skip YAML files whose
        // name matches a Doctrine-mapped column/association/embeddable on the target entity.
        $container->autowire(DoctrineEntityFieldNamesResolver::class)->setPublic(false);
        $container->setAlias(EntityFieldNamesResolverInterface::class, DoctrineEntityFieldNamesResolver::class)
            ->setPublic(false);

        // Cache warmer: imports config-defined fields into coolms_field_definitions
        // so FieldDefinitionListener fires syncField for v_* generated columns.
        $container->autowire(FieldDefinitionSyncWarmer::class)
            ->setPublic(false)
            ->addTag('kernel.cache_warmer');

        // Commands
        $container->autowire(CreateDefinitionCommand::class)
            ->addTag('console.command');
        $container->autowire(ListDefinitionsCommand::class)
            ->addTag('console.command');
    }

    /**
     * Explicit, not inherited: the base implementation derives the alias from the
     * class short name, and "Extension" minus the "Extension" suffix is the empty
     * string. Every service id this extension builds from the alias -- the entity
     * factory locator among them -- would collide with the next module that also
     * forgot to override it, and the loser's services vanish without an error.
     */
    public function getAlias(): string
    {
        return 'field';
    }

    public function prepend(ContainerBuilder $container): void
    {
        $container->prependExtensionConfig('doctrine', [
            'orm' => [
                'resolve_target_entities' => self::RESOLVE_TARGET_ENTITIES,
                'entity_managers' => [
                    'central' => [
                        'mappings' => [
                            'FieldBundle' => [
                                // !! XML, not attributes, and `is_bundle` false
                                // with it. Domain must not import the ORM, so
                                // the mapping lives beside the other Doctrine
                                // adapters, in coolms/field-doctrine.
                                //
                                // `is_bundle` false because `dir` would
                                // otherwise resolve against the bundle
                                // directory; the path is project-relative for
                                // the same reason core-doctrine's is
                                // vendor-relative -- it has to hold wherever
                                // the code is installed from.
                                //
                                // !! The driver owns this whole prefix. A new
                                // entity under CoolMS\Field\Entity with no
                                // matching .orm.xml is simply not mapped and
                                // NOTHING reports it -- the class just never
                                // becomes an entity. Add the file in the same
                                // commit as the class.
                                'is_bundle' => false,
                                'type' => 'xml',
                                'dir' => '%kernel.project_dir%/vendor/coolms/field-doctrine/src/mapping',
                                'prefix' => 'CoolMS\Field\Entity',
                                'alias' => 'Field',
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }
}
