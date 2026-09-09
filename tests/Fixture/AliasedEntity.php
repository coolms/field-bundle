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
 * It used to be an entity class belonging to the application, which is the
 * only thing that kept the test from travelling with the code it tests --
 * a coupling the test did not actually have.
 */
final class AliasedEntity
{
}
