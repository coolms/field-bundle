<?php

declare(strict_types=1);

namespace CoolMS\FieldBundle\FormType;

use CoolMS\Core\Field\FormTypeProviderInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RangeType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;

/**
 * Provides the standard Symfony core form types for the Schema Editor dropdown.
 * Tagged as `coolms.field.form_type` via autoconfiguration in FieldBundle Extension.
 */
final class BuiltinFormTypeProvider implements FormTypeProviderInterface
{
    /** @return array<array{value: string, label: string}> */
    public function getFormTypeOptions(): array
    {
        return [
            ['value' => TextType::class, 'label' => 'Text'],
            ['value' => TextareaType::class, 'label' => 'Textarea'],
            ['value' => EmailType::class, 'label' => 'Email'],
            ['value' => PasswordType::class, 'label' => 'Password'],
            ['value' => UrlType::class, 'label' => 'URL'],
            ['value' => NumberType::class, 'label' => 'Number (float)'],
            ['value' => IntegerType::class, 'label' => 'Integer'],
            ['value' => CheckboxType::class, 'label' => 'Checkbox'],
            ['value' => ChoiceType::class, 'label' => 'Choice / Select'],
            ['value' => DateType::class, 'label' => 'Date'],
            ['value' => DateTimeType::class, 'label' => 'Date & Time'],
            ['value' => ColorType::class, 'label' => 'Color'],
            ['value' => RangeType::class, 'label' => 'Range'],
            ['value' => HiddenType::class, 'label' => 'Hidden'],
        ];
    }
}
