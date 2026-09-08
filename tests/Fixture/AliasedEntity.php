<?php

declare(strict_types=1);

namespace CoolMS\FieldBundle\Tests\Fixture;

/**
 * A class name to hang an entity alias on.
 *
 * {@see \CoolMS\FieldBundle\Tests\CacheWarmer\FieldDefinitionSyncWarmerTest}
 * builds an `EntityAliasRegistry` so the warmer can turn the alias written in
 * a YAML field file into a class. The warmer never reflects on the class --
 * the field-names resolver and the entity factory are both stubs in that test
 * -- so what is being exercised is the ALIAS, and the class exists only to be
 * the other end of the mapping.
 *
 * It used to be `App\VFS\Domain\Entity\Node`, which made the test unable to
 * leave the application for a coupling it did not actually have.
 */
final class AliasedEntity
{
}
