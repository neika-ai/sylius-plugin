<?php

declare(strict_types=1);

namespace Neika\SyliusPlugin\Tests\Unit\Fixtures;

use Neika\SyliusPlugin\Credentials\NeikaChannelInterface;
use Neika\SyliusPlugin\Credentials\NeikaChannelTrait;
use Sylius\Component\Core\Model\Channel;

class NeikaChannel extends Channel implements NeikaChannelInterface
{
    use NeikaChannelTrait;
}
