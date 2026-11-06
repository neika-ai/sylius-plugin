<?php

declare(strict_types=1);

namespace Neika\SyliusPlugin\Twig;

use Neika\Sdk\Widget\WidgetSnippet;
use Neika\SyliusPlugin\Credentials\ChannelCredentialsProviderInterface;
use Twig\Extension\RuntimeExtensionInterface;

class ChatbotWidgetRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private readonly ChannelCredentialsProviderInterface $credentialsProvider,
        private readonly WidgetSnippet                       $widgetSnippet,
    ) {
    }

    public function getSiteKey(): string
    {
        $currentSite = $this->credentialsProvider->findCurrentSite();

        return $currentSite?->siteKey ?? '';
    }

    public function renderWidgetMarkup(
        ?string $backendUrl,
        string $siteKey,
        ?string $cdnUrl,
        ?string $locale,
        bool $defer = true,
    ): string {
        return $this->widgetSnippet->render((string) $backendUrl, $siteKey, (string) $cdnUrl, (string) $locale, $defer);
    }
}
