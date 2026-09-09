<?php

declare(strict_types=1);

namespace CoolMS\Field\Bundle\Tests\Fixture;

use CoolMS\Core\Identity\UserInterface as CoolmsUserInterface;
use Symfony\Component\Security\Core\User\UserInterface as SymfonyUserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The smallest thing that can be the signed-in actor in this package's tests.
 *
 * Two interfaces, because two different pieces of code look at it:
 * `Security::getUser()` returns Symfony's contract, and
 * {@see \CoolMS\Field\Bundle\Translation\FieldTranslationWriter::resolveActor()}
 * narrows that to the platform's own {@see CoolmsUserInterface} before it will
 * write anything. A fake implementing only one of the two passes the type
 * check it happens to satisfy and then silently takes the anonymous branch.
 *
 * The application's `FakeI18nUser` was used here before, and it implements the
 * Identity module's richer contract -- a contract nothing on this path asks
 * for. That import is the only reason the writer's test could not travel with
 * the writer.
 */
final class FakeActor implements CoolmsUserInterface, SymfonyUserInterface
{
    public Uuid $id;
    public Uuid $primaryGroupId;
    public bool $isRoot = false;
    public bool $isSystem = false;

    /** @var list<string> */
    public array $roles = [];

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->primaryGroupId = Uuid::v7();
    }

    /**
     * @return Uuid[]
     */
    public function getAllGroupIds(): array
    {
        return [$this->primaryGroupId];
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return $this->roles;
    }

    public function getUserIdentifier(): string
    {
        return 'test';
    }

    public function eraseCredentials(): void
    {
    }
}
