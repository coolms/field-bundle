<?php

declare(strict_types=1);

namespace CoolMS\FieldBundle\Form;

use CoolMS\Core\Field\StaticEntityAliasProviderInterface;
use CoolMS\Core\Form\FormConfigLoaderInterface;
use CoolMS\Field\Service\FieldMetadataRegistry;

/**
 * Bridges FieldMetadataRegistry into the Form module's override pipeline.
 *
 * Priority 10 -- lowest in the chain, overridden by YAML configs (20)
 * and DB-backed DatabaseFormLoader (30).
 *
 * Resolves formId (entity alias) to a PHP class via alias providers tagged
 * 'coolms.field.static_entity_alias_provider', then reads #[FieldMeta] +
 * #[Assert\*] + #[Groups] attributes through FieldMetadataRegistry
 * (Layers 1+2 only -- Layer 4 DB is handled by DatabaseFormLoader at
 * higher priority).
 *
 * Only fields with showInForm = true are included.
 */
final class StaticFieldMetaLoader implements FormConfigLoaderInterface
{
    /** @var array<string, class-string>|null -- lazily built from providers */
    private ?array $resolvedMap = null;

    /**
     * @param iterable<StaticEntityAliasProviderInterface> $aliasProviders
     */
    public function __construct(
        private readonly FieldMetadataRegistry $registry,
        private readonly iterable $aliasProviders = [],
    ) {
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function loadFieldOverrides(string $formId): array
    {
        $class = $this->aliasMap()[$formId] ?? null;
        if (null === $class) {
            return [];
        }

        $overrides = [];
        foreach ($this->registry->getAll($class, $formId) as $name => $meta) {
            if (!$meta->showInForm) {
                continue;
            }

            $entry = [];

            if (null !== $meta->formType) {
                $entry['type'] = $meta->formType;
            }

            $options = $meta->formOptions;
            $options['label'] = $meta->label;

            if ([] !== $meta->constraints && array_key_exists('NotBlank', $meta->constraints)) {
                $options['required'] = true;
            }

            $entry['options'] = $options;

            if ([] !== $meta->constraints) {
                $entry['constraints'] = $meta->constraints;
            }

            $overrides[$name] = $entry;
        }

        return $overrides;
    }

    public function getPriority(): int
    {
        return 10;
    }

    /** @return array<string, class-string> */
    private function aliasMap(): array
    {
        if (null === $this->resolvedMap) {
            $this->resolvedMap = [];
            foreach ($this->aliasProviders as $provider) {
                $this->resolvedMap = array_merge(
                    $this->resolvedMap,
                    $provider->getAliases(),
                );
            }
        }

        return $this->resolvedMap;
    }
}
