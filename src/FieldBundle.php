<?php

declare(strict_types=1);

namespace CoolMS\Field\Bundle;

use CoolMS\CoreBundle\AbstractCoolmsBundle;
use CoolMS\Field\Bundle\DependencyInjection\Compiler\FieldMetadataRegistryPass;
use CoolMS\Field\Bundle\DependencyInjection\Extension;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class FieldBundle extends AbstractCoolmsBundle
{
    public const string COMPONENT_NAME = 'field';

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new FieldMetadataRegistryPass());
    }

    public function getContainerExtension(): Extension
    {
        return new Extension();
    }
}
