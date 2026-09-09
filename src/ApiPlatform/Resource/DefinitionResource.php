<?php

declare(strict_types=1);

namespace CoolMS\Field\Bundle\ApiPlatform\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use CoolMS\Core\Application\ApiPlatform\Input\ReorderInput;
use CoolMS\Field\Entity\Definition;

#[ApiResource(
    shortName: 'FieldDefinition',
    operations: [
        new GetCollection(
            uriTemplate: '/field/definitions',
            name: 'field_definitions_list',
            provider: Provider\DefinitionProvider::class,
        ),
        new Get(
            uriTemplate: '/field/definitions/{id}',
            name: 'field_definitions_get',
            provider: Provider\DefinitionProvider::class,
        ),
        new Post(
            uriTemplate: '/field/definitions',
            status: 201,
            name: 'field_definitions_create',
            processor: Processor\DefinitionCreateProcessor::class,
        ),
        new Put(
            uriTemplate: '/field/definitions/{id}',
            name: 'field_definitions_update',
            // read: false -- readonly constructor-promoted properties cannot be mutated
            // via OBJECT_TO_POPULATE; a fresh instance from the PUT body is used instead.
            read: false,
            processor: Processor\DefinitionUpdateProcessor::class,
        ),
        new Patch(
            uriTemplate: '/field/definitions/reorder',
            name: 'field_definitions_reorder',
            input: ReorderInput::class,
            output: false,
            processor: Processor\DefinitionReorderProcessor::class,
            security: "is_granted('ROLE_ADMIN')",
            status: 204,
        ),
        new Patch(
            uriTemplate: '/field/definitions/{id}',
            name: 'field_definitions_patch',
            // read: false -- readonly constructor-promoted properties cannot be mutated
            // via OBJECT_TO_POPULATE; a fresh instance from the PATCH body is used instead.
            // Null-sentinel defaults for array/bool fields: null = not provided, skip in applyTo().
            read: false,
            processor: Processor\DefinitionUpdateProcessor::class,
        ),
        new Delete(
            uriTemplate: '/field/definitions/{id}',
            output: false,
            name: 'field_definitions_delete',
            provider: Provider\DefinitionProvider::class,
            processor: Processor\DefinitionDeleteProcessor::class,
        ),
        new Delete(
            uriTemplate: '/field/definitions',
            name: 'field_definitions_delete_by_alias',
            input: false,
            output: false,
            status: 204,
            processor: Processor\DefinitionDeleteByAliasProcessor::class,
        ),
    ],
    security: "is_granted('ROLE_ADMIN')",
)]
final class DefinitionResource
{
    public function __construct(
        public readonly ?string $id = null,
        public readonly string $entityAlias = '',
        public readonly string $name = '',
        public readonly string $label = '',

        /**
         * Empty string '' is the null-sentinel for PATCH/PUT (means "not provided").
         * fromEntity() always sets this to the entity's actual type.
         */
        public readonly string $type = '',

        /**
         * Null is the sentinel for PATCH/PUT (means "not provided, do not change").
         * fromEntity() always sets this to the entity's locked value.
         */
        public readonly ?bool $locked = null,

        /**
         * Origin of this field definition.
         * Currently always 'runtime' (created via API at run time).
         */
        public readonly string $source = 'runtime',

        /**
         * Null = not provided in PATCH/PUT body -- applyTo() skips this field.
         * fromEntity() always sets this to the entity's actual value.
         *
         * @var array<string, mixed>|null
         */
        public readonly ?array $validationRules = null,

        /** @var array<string, mixed>|null */
        public readonly ?array $normalizationGroups = null,

        /** @var array<string, mixed>|null */
        public readonly ?array $denormalizationGroups = null,

        /** @var array<string, mixed>|null */
        public readonly ?array $serializerConfig = null,

        /** @var array<string, mixed>|null */
        public readonly ?array $formConfig = null,

        /** @var array<string, mixed>|null */
        public readonly ?array $apiConfig = null,

        /**
         * Null means "not provided" -- the create processor will auto-assign max+10.
         * Always populated from the entity in fromEntity().
         */
        public readonly ?int $sortOrder = null,

        /**
         * Raw options bag -- null = not provided (applyTo() falls back to entity's current options).
         * fromEntity() always sets this to the entity's full options bag.
         *
         * @var array<string, mixed>|null
         */
        public readonly ?array $options = null,

        /** @var array<string, mixed>|null */
        public readonly ?array $securityRead = null,

        /** @var array<string, mixed>|null */
        public readonly ?array $securityWrite = null,

        /**
         * Input convenience: true adds 'NotBlank' to validationRules; false removes it; null = no change.
         * applyTo() translates this into validationRules on the entity.
         * Also populated on output (derived from the entity's validationRules['NotBlank']).
         */
        public readonly ?bool $isRequired = null,
        public readonly ?int $maxLength = null,
        public readonly ?string $placeholder = null,
        public readonly ?float $min = null,
        public readonly ?float $max = null,
        public readonly ?float $step = null,
        public readonly ?int $precision = null,

        /** @var array<string, mixed>|null */
        public readonly ?array $selectOptions = null,
        public readonly ?bool $multiple = null,
        public readonly ?string $relationTarget = null,
        public readonly ?string $relationCardinality = null,  // 'one' | 'many'
        public readonly ?string $relationWidget = null,  // 'select' | 'autocomplete' | 'tree'
        public readonly ?string $relationFilter = null,
        public readonly ?string $relationDisplayField = null,
        public readonly ?string $dateFormat = null,
        public readonly ?string $dateMin = null,
        public readonly ?string $dateMax = null,

        /**
         * Authoring input: per-option, per-locale label translations,
         * INPUT-only. Shape: `optionValue => (locale => text)`,
         * e.g. `['open' => ['uk' => 'Відкрито', 'de' => 'Offen']]`. The
         * Create/Update processors hand this to
         * `InlineLabelCatalogueWriter::writeChildren()` so the values land in
         * the VFS XLIFF catalogue keyed `definition.{uuid}.option.{value}.label`.
         * The English source label stays inline in `selectOptions`; only the
         * other locales are written here. `null` / `[]` = no-op (single-locale
         * deployments and the existing write path are unchanged).
         *
         * Also populated on the single-item READ (`GET /field/definitions/{id}`)
         * with the EXPLICIT overrides currently authored -- only locales an
         * operator actually translated, NOT the source echoed back -- so the
         * editor pre-fills exactly what exists and leaves the rest blank. The
         * collection read omits it (one catalogue read per row would be wasteful;
         * the list view doesn't need per-locale option labels).
         *
         * @var array<string, array<string, string>>|null
         */
        public readonly ?array $optionLabels = null,

        /**
         * Per-locale translations of the field's OWN display `label`, the
         * entity-level mirror of `optionLabels`. Shape: `locale => text`,
         * e.g. `['uk' => 'Колір', 'de' => 'Farbe']`. INPUT: the Create/Update
         * processors hand this to `FieldTranslationWriter::writeLabel()`, which
         * writes it via `InlineLabelCatalogueWriter::write($fd, ['label' => $map])`
         * into the VFS XLIFF catalogue keyed `definition.{uuid}.label` (domain
         * `field`). The source label stays inline in `options['label']`; only the
         * other locales land here.
         *
         * Also populated on the single-item READ (`GET /field/definitions/{id}`)
         * with the EXPLICIT overrides currently authored (only locales an operator
         * actually translated) so the editor pre-fills exactly what exists. The
         * collection read omits it (one catalogue read per row would be wasteful).
         * `null` / `[]` = no-op.
         *
         * @var array<string, string>|null
         */
        public readonly ?array $labelTranslations = null,
    ) {
    }

    // -------------------------------------------------------------------------

    /**
     * Build a resource DTO from a Definition entity.
     * All fields are always set explicitly so GET/PUT responses are complete.
     *
     * The optional `$label` / `$selectOptions` overrides let
     * the read provider inject locale-resolved display strings (via
     * LabelResolver) while the write-response processors keep passing the
     * entity's canonical values (an author who just typed a label/option
     * should see it echoed back, not a translated overlay). When null, the
     * entity's raw value is used -- so single-locale deployments and the
     * write path are unchanged.
     *
     * `$optionLabels` / `$labelTranslations` are the editor pre-fill payloads
     * (explicit per-locale overrides for the options and the field label
     * respectively) the single-item read provider injects; the write
     * processors and the collection read leave them null (input-only there).
     *
     * @param array<array{value: string, label: string}>|null $selectOptions
     * @param array<string, array<string, string>>|null       $optionLabels
     * @param array<string, string>|null                      $labelTranslations
     */
    public static function fromEntity(
        Definition $fd,
        ?string $label = null,
        ?array $selectOptions = null,
        ?array $optionLabels = null,
        ?array $labelTranslations = null,
    ): self {
        return new self(
            id: $fd->id->toRfc4122(),
            entityAlias: $fd->entityAlias,
            name: $fd->name,
            label: $label ?? $fd->label,
            type: $fd->type,
            locked: $fd->locked,
            source: 'runtime',
            validationRules: $fd->validationRules,
            normalizationGroups: $fd->normalizationGroups,
            denormalizationGroups: $fd->denormalizationGroups,
            serializerConfig: $fd->serializerConfig,
            formConfig: $fd->formConfig,
            apiConfig: $fd->apiConfig,
            sortOrder: $fd->sortOrder,
            options: $fd->options,
            securityRead: $fd->securityRead,
            securityWrite: $fd->securityWrite,
            isRequired: $fd->isRequired,
            maxLength: $fd->maxLength,
            placeholder: $fd->placeholder,
            min: $fd->min,
            max: $fd->max,
            step: $fd->step,
            precision: $fd->precision,
            selectOptions: $selectOptions ?? $fd->selectOptions,
            multiple: $fd->multiple,
            relationTarget: $fd->relationTarget,
            relationCardinality: $fd->relationCardinality,
            relationWidget: $fd->relationWidget,
            relationFilter: $fd->relationFilter,
            relationDisplayField: $fd->relationDisplayField,
            dateFormat: $fd->dateFormat,
            dateMin: $fd->dateMin,
            dateMax: $fd->dateMax,
            optionLabels: $optionLabels,
            labelTranslations: $labelTranslations,
        );
    }

    /**
     * Apply this resource's fields onto a Definition entity.
     *
     * Null-sentinel fields (arrays and bool locked) are skipped when null, allowing
     * PATCH bodies to omit fields without wiping existing entity values.
     *
     * The isRequired convenience shortcut is applied here: true adds 'NotBlank' to
     * validationRules; false removes it. No need to pre-mutate the resource in processors.
     *
     * Individual type-specific top-level fields are merged INTO the raw options bag
     * so that clients using either style (top-level or nested) work correctly.
     * Top-level individual fields take precedence when both are present.
     */
    public function applyTo(Definition $fd): void
    {
        // Resolve effective validationRules (honour isRequired shortcut).
        if (null !== $this->validationRules) {
            $validationRules = $this->validationRules;
            if (true === $this->isRequired) {
                $validationRules['NotBlank'] ??= null;
            } elseif (false === $this->isRequired) {
                unset($validationRules['NotBlank']);
            }
            $fd->validationRules = $validationRules;
        } elseif (null !== $this->isRequired) {
            // isRequired-only shortcut: patch the entity's existing rules in-place
            if (true === $this->isRequired) {
                $fd->validationRules['NotBlank'] ??= null;
            } else {
                unset($fd->validationRules['NotBlank']);
            }
        }

        // Bulk fields -- null = not provided, skip
        if (null !== $this->normalizationGroups) {
            $fd->normalizationGroups = $this->normalizationGroups;
        }
        if (null !== $this->denormalizationGroups) {
            $fd->denormalizationGroups = $this->denormalizationGroups;
        }
        if (null !== $this->serializerConfig) {
            $fd->serializerConfig = $this->serializerConfig;
        }
        if (null !== $this->formConfig) {
            $fd->formConfig = $this->formConfig;
        }
        if (null !== $this->apiConfig) {
            $fd->apiConfig = $this->apiConfig;
        }

        // sortOrder: only overwrite when explicitly provided (null = auto-assign in processor).
        if (null !== $this->sortOrder) {
            $fd->sortOrder = $this->sortOrder;
        }

        // Build effective options: start from provided bag or fall back to entity's current options.
        $opts = $this->options ?? $fd->options;

        // Label (stored in options['label'])
        if ('' !== $this->label) {
            $opts['label'] = $this->label;
        }

        // Security -- only override when explicitly provided
        if (null !== $this->securityRead) {
            $opts['security']['read'] = $this->securityRead;
        }
        if (null !== $this->securityWrite) {
            $opts['security']['write'] = $this->securityWrite;
        }

        // text / textarea
        if (null !== $this->maxLength) {
            $opts['maxLength'] = $this->maxLength;
        }
        if (null !== $this->placeholder) {
            $opts['placeholder'] = $this->placeholder;
        }

        // number
        if (null !== $this->min) {
            $opts['min'] = $this->min;
        }
        if (null !== $this->max) {
            $opts['max'] = $this->max;
        }
        if (null !== $this->step) {
            $opts['step'] = $this->step;
        }
        if (null !== $this->precision) {
            $opts['precision'] = $this->precision;
        }

        // select
        if (null !== $this->selectOptions && [] !== $this->selectOptions) {
            $opts['selectOptions'] = $this->selectOptions;
        }
        if (null !== $this->multiple) {
            $opts['multiple'] = $this->multiple;
        }

        // relation
        if (null !== $this->relationTarget) {
            $opts['relationTarget'] = $this->relationTarget;
        }
        if (null !== $this->relationCardinality) {
            $opts['relationCardinality'] = $this->relationCardinality;
        }
        if (null !== $this->relationWidget) {
            $opts['relationWidget'] = $this->relationWidget;
        }
        if (null !== $this->relationFilter) {
            $opts['relationFilter'] = $this->relationFilter;
        }
        if (null !== $this->relationDisplayField) {
            $opts['relationDisplayField'] = $this->relationDisplayField;
        }

        // date
        if (null !== $this->dateFormat) {
            $opts['dateFormat'] = $this->dateFormat;
        }
        if (null !== $this->dateMin) {
            $opts['dateMin'] = $this->dateMin;
        }
        if (null !== $this->dateMax) {
            $opts['dateMax'] = $this->dateMax;
        }

        $fd->options = $opts;

        // locked -- null = not provided, do not change
        if (null !== $this->locked) {
            $fd->locked = $this->locked;
        }
    }
}
