<?php

declare(strict_types=1);

namespace Neika\SyliusPlugin\Contract;

use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Taxonomy\Model\TaxonInterface;

interface ChannelTaxonRootsInterface
{
    /**
     * @return list<TaxonInterface>
     */
    public function findIndexableRoots(ChannelInterface $channel): array;
}
