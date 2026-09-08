<?php

declare(strict_types=1);

namespace CoolMS\FieldBundle;

use CoolMS\CoreBundle\AbstractCoolmsBundle;
use CoolMS\FieldBundle\DependencyInjection\Compiler\FieldMetadataRegistryPass;
use CoolMS\FieldBundle\DependencyInjection\Extension;
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
