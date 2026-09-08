<?php

declare(strict_types=1);

namespace CoolMS\FieldBundle\Reflection;

use CoolMS\Core\Attribute\FieldMeta;
use CoolMS\Field\Contract\FieldMetaReaderInterface;
use CoolMS\Field\VO\FieldMetadata;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionProperty;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Reads FieldMetadata from PHP attributes on entity properties via Reflection.
 *
 * Three attribute layers are merged per property (later layers win):
 *   Layer 1 -- Symfony #[Assert\*] attributes -> constraints base
 *   Layer 2 -- Symfony #[Groups] attribute -> normalization/denormalization groups base
 *   Layer 3 -- #[FieldMeta] -> overrides and extends all of the above
 *
 * Fields without #[FieldMeta] are included with defaults -- not hidden.
 * Trait properties are visible via reflection as own properties.
 */
final class FieldMetaReader implements FieldMetaReaderInterface
{
    private const string ASSERT_NAMESPACE = 'Symfony\Component\Validator\Constraints\\';

    /**
     * Read metadata for all public non-static properties of $class.
     * Includes properties declared in traits used by the class.
     *
     * @param class-string $class
     *
     * @return array<string, FieldMetadata> indexed by property name
     */
    public function readAll(string $class): array
    {
        $result = [];
        $reflection = new ReflectionClass($class);

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }
            $result[$property->getName()] = $this->readProperty($property);
        }

        return $result;
    }

    /**
     * Read metadata for a single named property of $class.
     *
     * @param class-string|ReflectionProperty $classOrProperty
     */
    public function readProperty(string|ReflectionProperty $classOrProperty, ?string $propertyName = null): FieldMetadata
    {
        if (is_string($classOrProperty)) {
            $reflection = new ReflectionClass($classOrProperty);
            $property = $reflection->getProperty((string) $propertyName);
        } else {
            $property = $classOrProperty;
        }

        // Layer 1 -- #[Assert\*] attributes -> constraints
        $constraints = $this->readConstraints($property);

        // Layer 2 -- #[Groups] attribute -> serialization groups
        [$normGroups, $denormGroups] = $this->readGroups($property);

        // Layer 3 -- #[FieldMeta] -> override/extend
        $meta = $this->readFieldMeta($property);

        $name = $property->getName();

        return new FieldMetadata(
            name: $name,
            label: null !== $meta ? ($meta->label ?? $this->labelFromName($name)) : $this->labelFromName($name),
            formType: $meta?->formType,
            formOptions: null !== $meta ? $meta->formOptions : [],
            showInForm: null !== $meta ? $meta->showInForm : true,
            constraints: array_merge($constraints, null !== $meta ? $meta->constraints : []),
            normalizationGroups: array_merge($normGroups, null !== $meta ? $meta->normalizationGroups : []),
            denormalizationGroups: array_merge($denormGroups, null !== $meta ? $meta->denormalizationGroups : []),
            securityRead: null !== $meta ? $meta->securityRead : [],
            securityWrite: null !== $meta ? $meta->securityWrite : [],
            sortOrder: $meta?->sortOrder,
            private: null !== $meta ? $meta->private : false,
            hasMeta: null !== $meta,
        );
    }

    // -------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function readConstraints(ReflectionProperty $property): array
    {
        $constraints = [];
        foreach ($property->getAttributes() as $attr) {
            $name = $attr->getName();
            if (!str_starts_with($name, self::ASSERT_NAMESPACE)) {
                continue;
            }
            $shortName = substr($name, strlen(self::ASSERT_NAMESPACE));
            $args = $attr->getArguments();
            $constraints[$shortName] = [] === $args ? null : $args;
        }

        return $constraints;
    }

    /**
     * @return array{string[], string[]} [normGroups, denormGroups]
     */
    private function readGroups(ReflectionProperty $property): array
    {
        $normGroups = [];
        $denormGroups = [];

        // PHP 8.4 property hooks: try IS_INSTANCEOF fallback when primary read is empty.
        $attrs = $property->getAttributes(Groups::class);
        if ([] === $attrs) {
            $attrs = $property->getAttributes(Groups::class, ReflectionAttribute::IS_INSTANCEOF);
        }

        foreach ($attrs as $attr) {
            /** @var Groups $instance */
            $instance = $attr->newInstance();
            // Groups containing ':write' go to denormalization; everything else to normalization.
            foreach ($instance->groups as $group) {
                if (str_contains($group, ':write')) {
                    $denormGroups[] = $group;
                } else {
                    $normGroups[] = $group;
                }
            }
        }

        return [$normGroups, $denormGroups];
    }

    private function readFieldMeta(ReflectionProperty $property): ?FieldMeta
    {
        // Standard attribute read.
        $attrs = $property->getAttributes(FieldMeta::class);
        if ([] !== $attrs) {
            return $attrs[0]->newInstance();
        }

        // PHP 8.4 property hooks fallback: hooked properties may return []
        // for class-filtered getAttributes() -- IS_INSTANCEOF uses a different
        // code path that works correctly.
        $attrs = $property->getAttributes(FieldMeta::class, ReflectionAttribute::IS_INSTANCEOF);
        if ([] !== $attrs) {
            return $attrs[0]->newInstance();
        }

        return null;
    }

    /**
     * Convert camelCase property name to a human-readable label.
     * Examples: metaTitle -> Meta Title, ogImage -> Og Image.
     */
    private function labelFromName(string $name): string
    {
        return (string) preg_replace('/([A-Z])/', ' $1', $name)
                |> trim(...)
                |> ucfirst(...);
    }
}
