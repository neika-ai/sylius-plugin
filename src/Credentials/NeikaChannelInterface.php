<?php

declare(strict_types=1);

namespace Neika\SyliusPlugin\Credentials;

use Sylius\Component\Core\Model\ChannelInterface;

interface NeikaChannelInterface extends ChannelInterface
{
    public function getNeikaSiteKey(): ?string;

    public function setNeikaSiteKey(?string $neikaSiteKey): void;

    public function setNeikaApiSecret(#[\SensitiveParameter] string $plainApiSecret): void;

    public function getNeikaIngestSecret(): ?string;

    public function setNeikaIngestSecret(#[\SensitiveParameter] ?string $neikaIngestSecret): void;
}
