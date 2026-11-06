<?php

declare(strict_types=1);

namespace Neika\SyliusPlugin;

use BitBag\SyliusCmsPlugin\Entity\Page as BitBagPage;
use Neika\SyliusPlugin\DataSource\BitBagCmsPagesDataSource;
use Neika\SyliusPlugin\DataSource\CmsPagesDataSource;
use Neika\SyliusPlugin\DependencyInjection\Compiler\SyliusContextAliasPass;
use Neika\SyliusPlugin\Text\RichTextToText;
use MonsieurBiz\SyliusCmsPagePlugin\Entity\Page;
use MonsieurBiz\SyliusRichEditorPlugin\Twig\RichEditorExtension;
use Sylius\Bundle\UiBundle\Registry\TemplateBlock;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

class NeikaSyliusPlugin extends AbstractBundle
{
    protected string $extensionAlias = 'neika_sylius';

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new SyliusContextAliasPass());
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->arrayNode('channel_site_keys')
                    ->useAttributeAsKey('channel')
                    ->normalizeKeys(false)
                    ->validate()
                        ->ifTrue(fn (array $channelSiteKeys): bool => $this->hasEmptyChannelCode($channelSiteKeys))
                        ->thenInvalid('Every channel site key must be keyed by a non-empty channel code, got %s.')
                    ->end()
                    ->scalarPrototype()
                        ->validate()
                            ->ifTrue(fn (mixed $siteKey): bool => !is_string($siteKey))
                            ->thenInvalid('Every channel site key must be a string, got %s.')
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    public function prependExtension(ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        $this->prependFromChat($container);
        $this->prependWidget($container);
    }

    public function loadExtension(array $config, ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        $configurator->parameters()
            ->set('neika_sylius.channel_site_keys', $config['channel_site_keys']);

        $configurator->import(__DIR__ . '/../config/services.php');

        $isCmsPagePluginInstalled = class_exists(Page::class);
        if ($isCmsPagePluginInstalled) {
            $configurator->services()
                ->set(CmsPagesDataSource::class)
                ->autowire()
                ->autoconfigure()
                ->arg('$pageRepository', service('monsieurbiz_cms_page.repository.page'));
        }

        $isRichEditorPluginInstalled = class_exists(RichEditorExtension::class);
        if ($isRichEditorPluginInstalled) {
            $configurator->services()
                ->set(RichTextToText::class)
                ->autowire()
                ->autoconfigure()
                ->arg('$richEditor', service(RichEditorExtension::class));
        }

        $isBitBagCmsPluginInstalled = class_exists(BitBagPage::class);
        if ($isBitBagCmsPluginInstalled) {
            $configurator->services()
                ->set(BitBagCmsPagesDataSource::class)
                ->autowire()
                ->autoconfigure()
                ->arg('$pageRepository', service('bitbag_sylius_cms_plugin.repository.page'));
        }
    }

    private function prependFromChat(ContainerBuilder $container): void
    {
        $hasTwigHooks = $container->hasExtension('sylius_twig_hooks');
        if ($hasTwigHooks) {
            $this->prependFromChatHook($container);

            return;
        }

        $hasTemplateEvents = $this->hasTemplateEvents($container);
        if ($hasTemplateEvents) {
            $this->prependFromChatTemplateBlock($container);
        }
    }

    private function prependFromChatHook(ContainerBuilder $container): void
    {
        $container->prependExtensionConfig('sylius_twig_hooks', [
            'hooks' => [
                'sylius_admin.order.show.content.sections#right' => [
                    'neika_from_chat' => [
                        'template' => '@NeikaSyliusPlugin/admin/order/show/from_chat.html.twig',
                        'priority' => -100,
                    ],
                ],
            ],
        ]);
    }

    private function prependFromChatTemplateBlock(ContainerBuilder $container): void
    {
        $container->prependExtensionConfig('sylius_ui', [
            'events' => [
                'sylius.admin.order.show.sidebar' => [
                    'blocks' => [
                        'neika_from_chat' => [
                            'template' => '@NeikaSyliusPlugin/admin/order/from_chat.html.twig',
                            'priority' => -100,
                        ],
                    ],
                ],
            ],
        ]);
    }

    private function prependWidget(ContainerBuilder $container): void
    {
        $neikaConfig = $this->mergeNeikaConfig($container);
        if ($neikaConfig['widget']['enabled'] === false) {
            return;
        }

        $widgetContext = [
            'backend_url' => (string) $neikaConfig['backend_url'],
            'widget_cdn_url' => (string) $neikaConfig['widget']['cdn_url'],
            'defer' => (bool) $neikaConfig['widget']['defer'],
        ];

        $hasTwigHooks = $container->hasExtension('sylius_twig_hooks');
        if ($hasTwigHooks) {
            $this->prependWidgetHook($container, $widgetContext);

            return;
        }

        $hasTemplateEvents = $this->hasTemplateEvents($container);
        if ($hasTemplateEvents) {
            $this->prependWidgetTemplateBlock($container, $widgetContext);
        }
    }

    /**
     * @param array<string, string|bool> $widgetContext
     */
    private function prependWidgetHook(ContainerBuilder $container, array $widgetContext): void
    {
        $container->prependExtensionConfig('sylius_twig_hooks', [
            'hooks' => [
                'sylius_shop.base#javascripts' => [
                    'neika_widget' => [
                        'template' => $this->getWidgetTemplate(),
                        'priority' => 0,
                        'context' => $widgetContext,
                    ],
                ],
            ],
        ]);
    }

    private function hasTemplateEvents(ContainerBuilder $container): bool
    {
        $hasUiExtension = $container->hasExtension('sylius_ui');

        if (!$hasUiExtension) {
            return false;
        }

        return class_exists(TemplateBlock::class);
    }

    /**
     * @param array<string, string|bool> $widgetContext
     */
    private function prependWidgetTemplateBlock(ContainerBuilder $container, array $widgetContext): void
    {
        $container->prependExtensionConfig('sylius_ui', [
            'events' => [
                'sylius.shop.layout.javascripts' => [
                    'blocks' => [
                        'neika_widget' => [
                            'template' => $this->getWidgetTemplate(),
                            'priority' => 0,
                            'context' => $widgetContext,
                        ],
                    ],
                ],
            ],
        ]);
    }

    /**
     * @param array<array-key, mixed> $channelSiteKeys
     */
    private function hasEmptyChannelCode(array $channelSiteKeys): bool
    {
        $channelCodes = array_map(strval(...), array_keys($channelSiteKeys));

        return in_array('', $channelCodes, true);
    }

    private function getWidgetTemplate(): string
    {
        return '@NeikaSyliusPlugin/shop/widget.html.twig';
    }

    private function getNeikaConfigAlias(): string
    {
        return 'neika';
    }

    /**
     * @return array{backend_url: string, widget: array{enabled: bool, cdn_url: string, defer: bool}}
     */
    private function mergeNeikaConfig(ContainerBuilder $container): array
    {
        $mergedConfig = [
            'backend_url' => '',
            'widget' => [
                'enabled' => true,
                'cdn_url' => '',
                'defer' => true,
            ],
        ];

        foreach ($container->getExtensionConfig($this->getNeikaConfigAlias()) as $rawConfig) {
            if (isset($rawConfig['backend_url'])) {
                $mergedConfig['backend_url'] = $rawConfig['backend_url'];
            }
            foreach (['enabled', 'cdn_url', 'defer'] as $key) {
                if (isset($rawConfig['widget'][$key])) {
                    $mergedConfig['widget'][$key] = $rawConfig['widget'][$key];
                }
            }
        }

        return $mergedConfig;
    }
}
