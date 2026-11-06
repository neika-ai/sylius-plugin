<?php

declare(strict_types=1);

namespace Neika\SyliusPlugin\Tests\Unit\Fixtures;

use Neika\SyliusPlugin\Contract\ChannelTaxonRootsInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Taxonomy\Model\TaxonInterface;

class SharedTaxonRoots implements ChannelTaxonRootsInterface
{
    /**
     * @param list<TaxonInterface> $roots
     */
    public function __construct(
        private readonly array $roots,
    ) {
    }

    /**
     * @return list<TaxonInterface>
     */
    public function findIndexableRoots(ChannelInterface $channel): array
    {
        return $this->roots;
    }
}
