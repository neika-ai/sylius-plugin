<?php

declare(strict_types=1);

use Neika\SyliusPlugin\Contract\ChannelTaxonRootsInterface;
use Neika\SyliusPlugin\Contract\ProductIndexabilityInterface;
use Neika\SyliusPlugin\Contract\ProductViewFactoryInterface;
use Neika\SyliusPlugin\Product\ProductIndexability;
use Neika\SyliusPlugin\Product\ProductViewFactory;
use Neika\SyliusPlugin\Taxon\ChannelTaxonRoots;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return function (ContainerConfigurator $configurator): void {
    $services = $configurator->services();

    $services->defaults()
        ->autowire()
        ->autoconfigure();

    $services->load('Neika\\SyliusPlugin\\', __DIR__ . '/../src/')
        ->exclude([
            __DIR__ . '/../src/DTO',
            __DIR__ . '/../src/Enum',
            __DIR__ . '/../src/Exception',
            __DIR__ . '/../src/Tool/DTO',
            __DIR__ . '/../src/DataSource/CmsPagesDataSource.php',
            __DIR__ . '/../src/DataSource/BitBagCmsPagesDataSource.php',
            __DIR__ . '/../src/DependencyInjection',
            __DIR__ . '/../src/NeikaSyliusPlugin.php',
        ]);

    $services->alias(ChannelTaxonRootsInterface::class, ChannelTaxonRoots::class);
    $services->alias(ProductViewFactoryInterface::class, ProductViewFactory::class);
    $services->alias(ProductIndexabilityInterface::class, ProductIndexability::class);
};
