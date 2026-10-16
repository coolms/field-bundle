<?php

declare(strict_types=1);

namespace CoolMS\Field\Bundle\Tests\Kernel;

use CoolMS\Field\Entity\Definition;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

use function dirname;
use function restore_exception_handler;
use function set_exception_handler;

use const JSON_THROW_ON_ERROR;

/**
 * The bundle, installed with nothing of the application's own, serves its API.
 *
 * Each route is asked over HTTP through a kernel that registers the bundles and
 * configures them, and registers none of this package's classes itself (see
 * BundleOnlyKernel). A route the extension does not provide answers 404 here;
 * a provider it does not register, or a form-type provider it does not tag,
 * answers with an error or an empty list.
 */
final class BundleServesItsRoutesTest extends TestCase
{
    private BundleOnlyKernel $kernel;

    /** PHP's exception handler before the boot: the framework installs one, and the test leaves none behind. */
    private mixed $handlerBefore = null;

    public function testTheFieldDefinitionsAreServedFromTheRepository(): void
    {
        $definition = new Definition();
        $definition->entityAlias = 'product';
        $definition->name = 'colour';
        $definition->type = 'string';
        $em = $this->entityManager();
        $em->persist($definition);
        $em->flush();
        $em->clear();

        $response = $this->askAsAdmin('/api/field/definitions');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        $names = array_column($this->members($response), 'name');
        self::assertSame(['colour'], $names, 'the definition stored is the one the route returns');
    }

    public function testTheBuiltinFormTypesAreOffered(): void
    {
        $response = $this->askAsAdmin('/api/field/form-types');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        // Empty unless the built-in provider carries the form-type tag.
        self::assertNotSame([], $this->members($response), 'the built-in form types are listed');
    }

    public function testTheConstraintMetadataRouteAnswers(): void
    {
        $response = $this->askAsAdmin('/api/dynamic-entity/constraints');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    }

    public function testAnAnonymousCallerIsRefusedRatherThanNotFound(): void
    {
        $response = $this->kernel->handle(Request::create('/api/field/definitions', server: [
            'HTTP_ACCEPT' => 'application/ld+json',
        ]));

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode(), (string) $response->getContent());
    }

    protected function setUp(): void
    {
        new Filesystem()->remove(dirname(new BundleOnlyKernel('test', false)->getCacheDir()));
        $this->handlerBefore = set_exception_handler(null);
        restore_exception_handler();
        $this->kernel = new BundleOnlyKernel('test', false);
        try {
            $this->kernel->boot();
        } catch (Throwable $e) {
            // A boot that fails has installed its handler all the same.
            $this->restoreExceptionHandler();

            throw $e;
        }

        $em = $this->entityManager();
        new SchemaTool($em)->createSchema([$em->getClassMetadata(Definition::class)]);
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
        $this->restoreExceptionHandler();
    }

    private function restoreExceptionHandler(): void
    {
        for ($guard = 0; $guard < 10; ++$guard) {
            $current = set_exception_handler(null);
            restore_exception_handler();
            if ($current === $this->handlerBefore) {
                break;
            }
            restore_exception_handler();
        }
    }

    private function askAsAdmin(string $path): Response
    {
        return $this->kernel->handle(Request::create($path, server: [
            'HTTP_ACCEPT' => 'application/ld+json',
            'PHP_AUTH_USER' => BundleOnlyKernel::ADMIN,
            'PHP_AUTH_PW' => BundleOnlyKernel::PASSWORD,
        ]));
    }

    /** @return list<array<string, mixed>> */
    private function members(Response $response): array
    {
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        $members = $body['member'] ?? $body['hydra:member'] ?? null;
        self::assertIsArray($members, 'a collection: ' . (string) $response->getContent());

        return array_values($members);
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = $this->kernel->getContainer()->get('doctrine.orm.central_entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
