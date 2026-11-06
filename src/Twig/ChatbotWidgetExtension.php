<?php

declare(strict_types=1);

namespace Neika\SyliusPlugin\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class ChatbotWidgetExtension extends AbstractExtension
{
    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('neika_site_key', [ChatbotWidgetRuntime::class, 'getSiteKey']),
            new TwigFunction(
                'neika_widget_markup',
                [ChatbotWidgetRuntime::class, 'renderWidgetMarkup'],
                ['is_safe' => ['html']],
            ),
        ];
    }
}
