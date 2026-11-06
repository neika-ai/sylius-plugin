<?php

declare(strict_types=1);

namespace Neika\SyliusPlugin\Tests\Unit\Fixtures;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

class NamedExtension extends Extension
{
    public function __construct(
        private readonly string $alias,
    ) {
    }

    public function getAlias(): string
    {
        return $this->alias;
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
    }
}
