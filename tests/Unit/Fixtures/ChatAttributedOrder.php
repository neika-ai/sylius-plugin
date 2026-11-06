<?php

declare(strict_types=1);

namespace Neika\SyliusPlugin\Tests\Unit\Fixtures;

use Neika\SyliusPlugin\Attribution\ChatAttributedOrderInterface;
use Neika\SyliusPlugin\Attribution\ChatAttributedOrderTrait;
use Sylius\Component\Core\Model\Order;

class ChatAttributedOrder extends Order implements ChatAttributedOrderInterface
{
    use ChatAttributedOrderTrait;
}
