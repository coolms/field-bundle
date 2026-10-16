<?php

declare(strict_types=1);

namespace CoolMS\Field\Bundle\Tests\Kernel;

use ApiPlatform\Symfony\Bundle\ApiPlatformBundle;
use CoolMS\Core\Bundle\CoreBundle;
use CoolMS\Entity\Bundle\EntityBundle;
use CoolMS\Field\Bundle\FieldBundle;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function dirname;

/**
 * An application that installs this bundle and writes nothing of its own: the
 * bundles the package requires, their configuration, and NO service
 * registration of this package's classes. Whatever the routes need has to come
 * from the bundle's extension, which is the point -- a consumer has no scan of
 * vendor/coolms/field-bundle, and before the extension registered the API
 * surface itself the routes below did not exist for one.
 *
 * One administrator in memory, over HTTP basic, because every operation
 * requires ROLE_ADMIN. SQLite in memory for the central entity manager the
 * bundle maps its entity into.
 */
final class BundleOnlyKernel extends Kernel
{
    use MicroKernelTrait;

    public const string ADMIN = 'admin';

    public const string PASSWORD = 'admin-password';

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SecurityBundle();
        yield new DoctrineBundle();
        yield new ApiPlatformBundle();
        yield new CoreBundle();
        yield new EntityBundle();
        yield new FieldBundle();
    }

    /** The package root: the bundle maps its entity from vendor/ under it. */
    public function getProjectDir(): string
    {
        return dirname(__DIR__, 2);
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/coolms-field-bundle-kernel/' . md5($this->getProjectDir()) . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/coolms-field-bundle-kernel/' . md5($this->getProjectDir()) . '/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'not-a-secret',
            'test' => true,
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'router' => ['utf8' => true],
            'serializer' => ['enabled' => true],
            'validation' => ['enabled' => true],
            'property_info' => ['enabled' => true],
            'translator' => ['enabled' => true, 'fallbacks' => ['en']],
            // coolms/core-bundle routes domain events to a transport of this
            // name; defining it is the installing application's part.
            'messenger' => ['transports' => ['sync' => 'sync://']],
        ]);

        $container->extension('security', [
            'password_hashers' => [
                'Symfony\Component\Security\Core\User\InMemoryUser' => 'plaintext',
            ],
            'providers' => [
                'in_memory' => [
                    'memory' => [
                        'users' => [
                            self::ADMIN => ['password' => self::PASSWORD, 'roles' => ['ROLE_ADMIN']],
                        ],
                    ],
                ],
            ],
            'firewalls' => [
                'main' => [
                    'stateless' => true,
                    'http_basic' => null,
                ],
            ],
        ]);

        $container->extension('doctrine', [
            'dbal' => [
                'default_connection' => 'central',
                'connections' => [
                    'central' => ['url' => 'sqlite:///:memory:'],
                ],
            ],
            'orm' => [
                'default_entity_manager' => 'central',
                'entity_managers' => [
                    'central' => ['connection' => 'central'],
                ],
            ],
        ]);

        $container->extension('api_platform', [
            'title' => 'field-bundle alone',
            // No resource directories: a resource is found because the
            // extension registered it, or not at all.
            'mapping' => ['paths' => []],
            'formats' => [
                'jsonld' => ['application/ld+json'],
                'json' => ['application/json'],
            ],
        ]);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', 'api_platform')->prefix('/api');
    }
}
