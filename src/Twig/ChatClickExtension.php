<?php

declare(strict_types=1);

namespace Neika\SyliusPlugin\Twig;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class ChatClickExtension extends AbstractExtension
{
    public function __construct(
        #[Autowire(param: 'neika.backend_url')]
        private readonly string $backendUrl,
    ) {
    }

    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('neika_click_url', $this->getClickUrl(...)),
        ];
    }

    public function getClickUrl(string $clickId): string
    {
        $backendUrl = rtrim($this->backendUrl, '/');
        $encodedClickId = rawurlencode($clickId);

        return $backendUrl . '/clicks/' . $encodedClickId;
    }
}
