<?php

declare(strict_types=1);

namespace Neika\SyliusPlugin\Contract;

use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;

interface ProductIndexabilityInterface
{
    public function isIndexable(ProductVariantInterface $variant, ChannelInterface $channel): bool;
}
