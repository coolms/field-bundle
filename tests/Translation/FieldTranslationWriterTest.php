<?php

declare(strict_types=1);

namespace CoolMS\Field\Bundle\Tests\Translation;

use CoolMS\Core\Identity\UserInterface;
use CoolMS\Core\Translation\InlineLabelCatalogueWriterInterface;
use CoolMS\Field\Entity\Definition;
use CoolMS\Field\Bundle\Tests\Fixture\FakeActor;
use CoolMS\Field\Bundle\Translation\FieldTranslationWriter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The seam that gives the inline-label write bridge a caller. Pins the
 * writer's own decisions for BOTH coordinates:
 *  - {@see FieldTranslationWriter::writeOptionLabels()} -- the
 *    `optionValue => locale => text` -> `childId => field => locale => value`
 *    reshape under the `option` childKind.
 *  - {@see FieldTranslationWriter::writeLabel()} -- the field's own `label`,
 *    wrapped `locale => text` -> `field('label') => locale => text` and sent
 *    via the entity-level `write()`.
 * Plus the shared no-op guards (null/empty payload) and the actor guard.
 *
 * The catalogue writer is a spy (not a mock) so the reshaped payload is
 * legible; `Security` is built real (it is `@final`, not language-final,
 * so mocking is avoided per project convention) over a token holding a
 * `FakeActor`, which is both a Core and a Symfony UserInterface.
 */
final class FieldTranslationWriterTest extends TestCase
{
    // -- writeOptionLabels() ------------------------------------------------

    #[Test]
    public function nullOptionLabelsWritesNothing(): void
    {
        $spy = new SpyCatalogueWriter();
        $writer = new FieldTranslationWriter($spy, $this->authenticatedSecurity());

        $writer->writeOptionLabels(new Definition(), null);

        self::assertSame(0, $spy->writeChildrenCalls);
    }

    #[Test]
    public function emptyOptionLabelsWritesNothing(): void
    {
        $spy = new SpyCatalogueWriter();
        $writer = new FieldTranslationWriter($spy, $this->authenticatedSecurity());

        $writer->writeOptionLabels(new Definition(), []);

        self::assertSame(0, $spy->writeChildrenCalls);
    }

    #[Test]
    public function noAuthenticatedActorWritesNoOptionLabels(): void
    {
        // The endpoints are ROLE_ADMIN-gated so this can't happen in
        // production, but the catalogue save needs a real actor -- be
        // defensive rather than pass a null actor down.
        $spy = new SpyCatalogueWriter();
        $writer = new FieldTranslationWriter($spy, $this->anonymousSecurity());

        $writer->writeOptionLabels(new Definition(), ['open' => ['uk' => 'Відкрито']]);

        self::assertSame(0, $spy->writeChildrenCalls);
    }

    #[Test]
    public function reshapesValueLocaleMapUnderOptionChildKindAndLabelField(): void
    {
        $spy = new SpyCatalogueWriter();
        $actor = new FakeActor();
        $definition = new Definition();
        $writer = new FieldTranslationWriter($spy, $this->authenticatedSecurity($actor));

        $writer->writeOptionLabels($definition, [
            'open' => ['uk' => 'Відкрито', 'de' => 'Offen'],
            'closed' => ['uk' => 'Закрито'],
        ]);

        self::assertSame(1, $spy->writeChildrenCalls);
        self::assertSame(Definition::TRANSLATABLE_OPTION_CHILD, $spy->capturedChildKind);
        self::assertSame(
            [
                'open' => ['label' => ['uk' => 'Відкрито', 'de' => 'Offen']],
                'closed' => ['label' => ['uk' => 'Закрито']],
            ],
            $spy->capturedChildLabels,
        );
        // The saved Definition is the parent the keys hang off; the
        // authenticated user is the catalogue-write actor.
        self::assertSame($definition, $spy->capturedChildParent);
        self::assertSame($actor, $spy->capturedChildActor);
    }

    // -- writeLabel() -------------------------------------------------------

    #[Test]
    public function nullLabelTranslationsWritesNothing(): void
    {
        $spy = new SpyCatalogueWriter();
        $writer = new FieldTranslationWriter($spy, $this->authenticatedSecurity());

        $writer->writeLabel(new Definition(), null);

        self::assertSame(0, $spy->writeCalls);
    }

    #[Test]
    public function emptyLabelTranslationsWritesNothing(): void
    {
        $spy = new SpyCatalogueWriter();
        $writer = new FieldTranslationWriter($spy, $this->authenticatedSecurity());

        $writer->writeLabel(new Definition(), []);

        self::assertSame(0, $spy->writeCalls);
    }

    #[Test]
    public function noAuthenticatedActorWritesNoLabel(): void
    {
        $spy = new SpyCatalogueWriter();
        $writer = new FieldTranslationWriter($spy, $this->anonymousSecurity());

        $writer->writeLabel(new Definition(), ['uk' => 'Колір']);

        self::assertSame(0, $spy->writeCalls);
    }

    #[Test]
    public function wrapsLocaleMapUnderTheLabelFieldForTheEntityWrite(): void
    {
        $spy = new SpyCatalogueWriter();
        $actor = new FakeActor();
        $definition = new Definition();
        $writer = new FieldTranslationWriter($spy, $this->authenticatedSecurity($actor));

        $writer->writeLabel($definition, ['uk' => 'Колір', 'de' => 'Farbe']);

        self::assertSame(1, $spy->writeCalls);
        // The field's own label routes through the entity-level write(),
        // wrapped under the single translatable field `label`.
        self::assertSame(
            ['label' => ['uk' => 'Колір', 'de' => 'Farbe']],
            $spy->capturedLocalizedLabels,
        );
        self::assertSame($definition, $spy->capturedParent);
        self::assertSame($actor, $spy->capturedActor);
        // Did not touch the inline-child path.
        self::assertSame(0, $spy->writeChildrenCalls);
    }

    #[Test]
    public function passesNullClearThroughForTheLabel(): void
    {
        // A null value for a locale clears that override (revert to source);
        // the writer must pass it through verbatim, not drop it.
        $spy = new SpyCatalogueWriter();
        $writer = new FieldTranslationWriter($spy, $this->authenticatedSecurity());

        $writer->writeLabel(new Definition(), ['uk' => null]);

        self::assertSame(1, $spy->writeCalls);
        self::assertSame(['label' => ['uk' => null]], $spy->capturedLocalizedLabels);
    }

    private function authenticatedSecurity(?FakeActor $user = null): Security
    {
        $user ??= new FakeActor();
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        $container = new Container();
        $container->set('security.token_storage', $tokenStorage);

        return new Security($container);
    }

    private function anonymousSecurity(): Security
    {
        $container = new Container();
        $container->set('security.token_storage', new TokenStorage()); // no token

        return new Security($container);
    }
}

/**
 * Captures both {@see InlineLabelCatalogueWriterInterface} entrypoints so the
 * reshape can be asserted directly. `write()` (entity field) and
 * `writeChildren()` (inline child) record into separate slots.
 */
final class SpyCatalogueWriter implements InlineLabelCatalogueWriterInterface
{
    public int $writeCalls = 0;
    public ?object $capturedParent = null;

    /** @var array<string, array<string, ?string>> */
    public array $capturedLocalizedLabels = [];

    public ?UserInterface $capturedActor = null;
    public int $writeChildrenCalls = 0;
    public ?object $capturedChildParent = null;
    public ?string $capturedChildKind = null;

    /** @var array<string, array<string, array<string, ?string>>> */
    public array $capturedChildLabels = [];

    public ?UserInterface $capturedChildActor = null;

    public function write(object $translatable, array $localizedLabels, UserInterface $actor): void
    {
        ++$this->writeCalls;
        $this->capturedParent = $translatable;
        $this->capturedLocalizedLabels = $localizedLabels;
        $this->capturedActor = $actor;
    }

    public function writeChildren(object $parent, string $childKind, array $childLabels, UserInterface $actor): void
    {
        ++$this->writeChildrenCalls;
        $this->capturedChildParent = $parent;
        $this->capturedChildKind = $childKind;
        $this->capturedChildLabels = $childLabels;
        $this->capturedChildActor = $actor;
    }
}
