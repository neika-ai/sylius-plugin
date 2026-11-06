<?php

declare(strict_types=1);

namespace Neika\SyliusPlugin\Credentials;

use Neika\Sdk\DTO\SiteCredentials;
use Neika\SymfonyBundle\Contract\CredentialsProviderInterface;
use Sylius\Component\Channel\Model\ChannelInterface;

interface ChannelCredentialsProviderInterface extends CredentialsProviderInterface
{
    public function findForChannel(ChannelInterface $channel): ?SiteCredentials;
}
