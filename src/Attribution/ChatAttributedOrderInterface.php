<?php

declare(strict_types=1);

namespace Neika\SyliusPlugin\Attribution;

use Sylius\Component\Core\Model\OrderInterface;

interface ChatAttributedOrderInterface extends OrderInterface
{
    /**
     * @return list<string>
     */
    public function getChatClickIds(): array;

    /**
     * @param list<string> $chatClickIds
     */
    public function setChatClickIds(array $chatClickIds): void;
}
