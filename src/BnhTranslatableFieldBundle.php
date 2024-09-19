<?php

namespace Bnh\TranslatableFieldBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Bnh\TranslatableFieldBundle\DependencyInjection\Compiler\TemplatingPass;

class BnhTranslatableFieldBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new TemplatingPass());
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
