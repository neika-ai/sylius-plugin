<?php

declare(strict_types=1);

namespace Neika\SyliusPlugin\Tests\Unit;

use Doctrine\ORM\EntityManagerInterface;
use Neika\Sdk\Contract\ChatbotLocaleContextInterface;
use Neika\Sdk\Ingest\CatalogIngestClient;
use Neika\Sdk\Pairing\HostMatcher;
use Neika\Sdk\Widget\WidgetSnippet;
use Neika\SymfonyBundle\Contract\CredentialsProviderInterface;
use Neika\SymfonyBundle\Contract\CredentialsWriterInterface;
use Neika\SymfonyBundle\Credentials\EnvCredentialsProvider;
use Neika\SymfonyBundle\NeikaSymfonyBundle;
use Neika\SymfonyBundle\Reporting\BackendReportGuard;
use Neika\SyliusPlugin\Channel\ChannelResolver;
use Neika\SyliusPlugin\Channel\SiteKeyResolver;
use Neika\SyliusPlugin\Credentials\ChannelCredentialsProviderInterface;
use Neika\SyliusPlugin\Credentials\SyliusCredentialsProvider;
use Neika\SyliusPlugin\DependencyInjection\Compiler\SyliusContextAliasPass;
use Neika\SyliusPlugin\NeikaSyliusPlugin;
use Neika\SyliusPlugin\Ingest\CatalogChangeNotifier;
use Neika\SyliusPlugin\Locale\SyliusLocaleContext;
use Neika\SyliusPlugin\Tests\Unit\Fixtures\NeikaChannel;
use Neika\SyliusPlugin\Tests\Unit\Fixtures\NamedExtension;
use Neika\SyliusPlugin\Twig\ChatbotWidgetExtension;
use Neika\SyliusPlugin\Twig\ChatbotWidgetRuntime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\Channel;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Twig\Extension\ExtensionInterface;
use Twig\Extension\RuntimeExtensionInterface;

class NeikaSyliusPluginTest extends TestCase
{
    public function testTheWidgetIsRegisteredAsATwigHookWhenTheShopHasThem(): void
    {
        $container = $this->createContainer(['sylius_twig_hooks', 'sylius_ui']);

        $this->prependExtension($container);

        $hooks = $container->getExtensionConfig('sylius_twig_hooks');
        $widget = $hooks[0]['hooks']['sylius_shop.base#javascripts']['neika_widget'];

        self::assertSame('@NeikaSyliusPlugin/shop/widget.html.twig', $widget['template']);
        self::assertSame($this->getExpectedWidgetContext(), $widget['context']);
        self::assertSame([], $container->getExtensionConfig('sylius_ui'));
    }

    public function testTheWidgetIsRegisteredAsATemplateBlockOnSyliusWithoutTwigHooks(): void
    {
        require_once __DIR__ . '/Fixtures/sylius_1_template_block.php';

        $container = $this->createContainer(['sylius_ui']);

        $this->prependExtension($container);

        $events = $container->getExtensionConfig('sylius_ui');
        $widget = $events[0]['events']['sylius.shop.layout.javascripts']['blocks']['neika_widget'];

        self::assertSame('@NeikaSyliusPlugin/shop/widget.html.twig', $widget['template']);
        self::assertSame($this->getExpectedWidgetContext(), $widget['context']);
        self::assertSame([], $container->getExtensionConfig('sylius_twig_hooks'));
    }

    public function testADisabledWidgetIsRegisteredNowhere(): void
    {
        $container = $this->createContainer(['sylius_ui', 'sylius_twig_hooks'], ['enabled' => false]);

        $this->prependExtension($container);

        $hooks = $container->getExtensionConfig('sylius_twig_hooks');
        self::assertCount(1, $hooks);
        self::assertArrayNotHasKey('sylius_shop.base#javascripts', $hooks[0]['hooks']);
    }

    public function testTheWidgetDeferSettingReachesTheHookContext(): void
    {
        $container = $this->createContainer(['sylius_twig_hooks', 'sylius_ui'], ['defer' => false]);

        $this->prependExtension($container);

        $hooks = $container->getExtensionConfig('sylius_twig_hooks');
        $widget = $hooks[0]['hooks']['sylius_shop.base#javascripts']['neika_widget'];

        self::assertFalse($widget['context']['defer']);
    }

    public function testTheFromChatFieldIsRegisteredAsAnAdminTwigHook(): void
    {
        $container = $this->createContainer(['sylius_twig_hooks', 'sylius_ui']);

        $this->prependExtension($container);

        $hooks = $container->getExtensionConfig('sylius_twig_hooks');
        $fromChat = $hooks[1]['hooks']['sylius_admin.order.show.content.sections#right']['neika_from_chat'];

        self::assertSame('@NeikaSyliusPlugin/admin/order/show/from_chat.html.twig', $fromChat['template']);
    }

    public function testTheFromChatFieldIsRegisteredAsAnAdminTemplateBlockOnSyliusWithoutTwigHooks(): void
    {
        require_once __DIR__ . '/Fixtures/sylius_1_template_block.php';

        $container = $this->createContainer(['sylius_ui']);

        $this->prependExtension($container);

        $events = $container->getExtensionConfig('sylius_ui');
        $fromChat = $events[1]['events']['sylius.admin.order.show.sidebar']['blocks']['neika_from_chat'];

        self::assertSame('@NeikaSyliusPlugin/admin/order/from_chat.html.twig', $fromChat['template']);
    }

    public function testTheChannelSiteKeysDefaultToNone(): void
    {
        $config = $this->processConfiguration([]);

        self::assertSame([], $config['channel_site_keys']);
    }

    /**
     * @param array<array-key, string> $channelSiteKeys
     */
    #[DataProvider('provideValidChannelSiteKeys')]
    public function testValidChannelSiteKeysAreKeptVerbatim(array $channelSiteKeys): void
    {
        $config = $this->processConfiguration([
            'channel_site_keys' => $channelSiteKeys,
        ]);

        self::assertSame($channelSiteKeys, $config['channel_site_keys']);
    }

    /**
     * @return iterable<string, array{array<array-key, string>}>
     */
    public static function provideValidChannelSiteKeys(): iterable
    {
        yield 'literal keys' => [['CZ_WEB' => 'cz-key', 'SK_WEB' => 'sk-key']];
        yield 'env placeholders' => [['CZ_WEB' => '%env(CHATBOT_SITE_KEY_CZ)%']];
        yield 'hyphenated channel codes are not normalized' => [['cz-web' => 'cz-key']];
        yield 'numeric channel codes' => [[123 => 'numeric-key']];
        yield 'empty site key' => [['CZ_WEB' => '']];
    }

    /**
     * @param array<array-key, mixed> $channelSiteKeys
     */
    #[DataProvider('provideInvalidChannelSiteKeys')]
    public function testInvalidChannelSiteKeysAreRejected(array $channelSiteKeys): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->processConfiguration([
            'channel_site_keys' => $channelSiteKeys,
        ]);
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function provideInvalidChannelSiteKeys(): iterable
    {
        yield 'integer site key' => [['CZ_WEB' => 123]];
        yield 'boolean site key' => [['CZ_WEB' => true]];
        yield 'null site key' => [['CZ_WEB' => null]];
        yield 'nested site key' => [['CZ_WEB' => ['cz-key']]];
        yield 'empty channel code' => [['' => 'cz-key']];
    }

    public function testTheChannelSiteKeysBecomeAContainerParameter(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());
        $extension = (new NeikaSyliusPlugin())->getContainerExtension();

        $extension->load([[
            'channel_site_keys' => ['CZ_WEB' => 'cz-key'],
        ]], $container);

        self::assertSame(['CZ_WEB' => 'cz-key'], $container->getParameter('neika_sylius.channel_site_keys'));
    }

    /**
     * @param list<class-string<AbstractBundle>> $bundleClasses
     */
    #[DataProvider('provideBundleOrders')]
    public function testTheChannelContextsWinWhicheverBundleIsRegisteredFirst(array $bundleClasses): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());
        $this->registerBundles($container, $bundleClasses);
        $container->loadFromExtension('neika', []);
        $container->loadFromExtension('neika_sylius',[]);
        $this->skipPassesAfterAliasing($container);

        $container->compile();

        $credentialsProviderAlias = (string) $container->getAlias(CredentialsProviderInterface::class);
        $channelCredentialsProviderAlias = (string) $container->getAlias(ChannelCredentialsProviderInterface::class);
        $localeContextAlias = (string) $container->getAlias(ChatbotLocaleContextInterface::class);
        self::assertSame(SyliusCredentialsProvider::class, $credentialsProviderAlias);
        self::assertSame(SyliusCredentialsProvider::class, $channelCredentialsProviderAlias);
        self::assertSame(SyliusLocaleContext::class, $localeContextAlias);
    }

    /**
     * @param class-string $channelClass
     */
    #[DataProvider('provideChannelClasses')]
    public function testTheCredentialsWriterIsAliasedOnlyForAChannelModelWithTheTrait(string $channelClass, bool $isWriterExpected): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('sylius.model.channel.class', $channelClass);

        (new SyliusContextAliasPass())->process($container);

        $hasWriter = $container->hasAlias(CredentialsWriterInterface::class);
        self::assertSame($isWriterExpected, $hasWriter);
    }

    /**
     * @return iterable<string, array{class-string, bool}>
     */
    public static function provideChannelClasses(): iterable
    {
        yield 'channel with the trait' => [NeikaChannel::class, true];
        yield 'stock channel' => [Channel::class, false];
    }

    public function testAnAppCredentialsProviderIsKeptAndServesTheSymfonyBundleToo(): void
    {
        $container = new ContainerBuilder();
        $container->setAlias(ChannelCredentialsProviderInterface::class, 'app.neika_credentials');

        (new SyliusContextAliasPass())->process($container);

        $channelCredentialsProviderAlias = (string) $container->getAlias(ChannelCredentialsProviderInterface::class);
        $credentialsProviderAlias = (string) $container->getAlias(CredentialsProviderInterface::class);
        self::assertSame('app.neika_credentials', $channelCredentialsProviderAlias);
        self::assertSame('app.neika_credentials', $credentialsProviderAlias);
    }

    public function testAnAppCredentialsProviderGetsNoChannelWriter(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('sylius.model.channel.class', NeikaChannel::class);
        $container->setAlias(ChannelCredentialsProviderInterface::class, 'app.neika_credentials');

        (new SyliusContextAliasPass())->process($container);

        self::assertFalse($container->hasAlias(CredentialsWriterInterface::class));
    }

    public function testAnAppProviderForOnlyTheSymfonyInterfaceFailsTheBuild(): void
    {
        $container = new ContainerBuilder();
        $container->setAlias(CredentialsProviderInterface::class, 'app.neika_credentials');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(ChannelCredentialsProviderInterface::class);

        (new SyliusContextAliasPass())->process($container);
    }

    public function testTheSymfonyBundleDefaultProviderIsReplaced(): void
    {
        $container = new ContainerBuilder();
        $container->setAlias(CredentialsProviderInterface::class, EnvCredentialsProvider::class);

        (new SyliusContextAliasPass())->process($container);

        $credentialsProviderAlias = (string) $container->getAlias(CredentialsProviderInterface::class);
        self::assertSame(SyliusCredentialsProvider::class, $credentialsProviderAlias);
    }

    public function testAnAppCredentialsWriterIsKept(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('sylius.model.channel.class', NeikaChannel::class);
        $container->setAlias(CredentialsWriterInterface::class, 'app.neika_writer');

        (new SyliusContextAliasPass())->process($container);

        $writerAlias = (string) $container->getAlias(CredentialsWriterInterface::class);
        self::assertSame('app.neika_writer', $writerAlias);
    }

    public function testTheCredentialsWriterIsNotAliasedWithoutAChannelModel(): void
    {
        $container = new ContainerBuilder();

        (new SyliusContextAliasPass())->process($container);

        self::assertFalse($container->hasAlias(CredentialsWriterInterface::class));
    }

    /**
     * @return iterable<string, array{list<class-string<AbstractBundle>>}>
     */
    public static function provideBundleOrders(): iterable
    {
        yield 'Symfony bundle first' => [[NeikaSymfonyBundle::class, NeikaSyliusPlugin::class]];
        yield 'Sylius bundle first' => [[NeikaSyliusPlugin::class, NeikaSymfonyBundle::class]];
    }

    public function testTheSiteKeyServicesWireInACompiledContainer(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());
        $container->registerForAutoconfiguration(ExtensionInterface::class)->addTag('twig.extension');
        $container->registerForAutoconfiguration(RuntimeExtensionInterface::class)->addTag('twig.runtime');
        $this->registerShopServices($container);

        $container->setParameter('neika.backend_url', 'https://backend.test');

        $bundle = new NeikaSyliusPlugin();
        $bundle->build($container);
        $bundle->getContainerExtension()->load([[
            'channel_site_keys' => ['CZ_WEB' => 'cz-key', 'SK_WEB' => '%env(CHATBOT_TEST_SITE_KEY_SK)%'],
        ]], $container);
        $this->keepOnlyWiredBundleServices($container, $this->getWiredServiceIds());
        $_ENV['CHATBOT_TEST_SITE_KEY_SK'] = 'sk-key';

        try {
            $container->compile(resolveEnvPlaceholders: true);
        } finally {
            unset($_ENV['CHATBOT_TEST_SITE_KEY_SK']);
        }

        $this->setShopServices($container);
        self::assertSame(['CZ_WEB' => 'cz-key', 'SK_WEB' => 'sk-key'], $container->getParameter('neika_sylius.channel_site_keys'));
        self::assertInstanceOf(SiteKeyResolver::class, $container->get(SiteKeyResolver::class));
        self::assertInstanceOf(CatalogChangeNotifier::class, $container->get(CatalogChangeNotifier::class));
        self::assertInstanceOf(ChatbotWidgetRuntime::class, $container->get(ChatbotWidgetRuntime::class));
        self::assertTrue($container->getDefinition(ChatbotWidgetExtension::class)->hasTag('twig.extension'));
        self::assertTrue($container->getDefinition(ChatbotWidgetRuntime::class)->hasTag('twig.runtime'));
    }

    /**
     * @param list<class-string<AbstractBundle>> $bundleClasses
     */
    private function registerBundles(ContainerBuilder $container, array $bundleClasses): void
    {
        foreach ($bundleClasses as $bundleClass) {
            $bundle = new $bundleClass();
            $container->registerExtension($bundle->getContainerExtension());
            $bundle->build($container);
        }
    }

    private function skipPassesAfterAliasing(ContainerBuilder $container): void
    {
        $passConfig = $container->getCompilerPassConfig();
        $passConfig->setOptimizationPasses([]);
        $passConfig->setRemovingPasses([]);
        $passConfig->setAfterRemovingPasses([]);
    }

    /**
     * @return list<class-string>
     */
    private function getWiredServiceIds(): array
    {
        return [
            SiteKeyResolver::class,
            CatalogChangeNotifier::class,
            ChannelResolver::class,
            ChatbotWidgetExtension::class,
            ChatbotWidgetRuntime::class,
            SyliusCredentialsProvider::class,
        ];
    }

    /**
     * @param list<class-string> $wiredServiceIds
     */
    private function keepOnlyWiredBundleServices(ContainerBuilder $container, array $wiredServiceIds): void
    {
        foreach (array_keys($container->getDefinitions()) as $serviceId) {
            $isBundleService = str_starts_with($serviceId, 'Neika\\SyliusPlugin\\');
            if (!$isBundleService) {
                continue;
            }

            $isWired = in_array($serviceId, $wiredServiceIds, true);
            if ($isWired) {
                $container->getDefinition($serviceId)->setPublic(true);

                continue;
            }

            $container->removeDefinition($serviceId);
        }
    }

    /**
     * @return array<string, class-string>
     */
    private function getShopServiceClasses(): array
    {
        return [
            CatalogIngestClient::class => CatalogIngestClient::class,
            BackendReportGuard::class => BackendReportGuard::class,
            WidgetSnippet::class => WidgetSnippet::class,
            LoggerInterface::class => LoggerInterface::class,
            ChannelRepositoryInterface::class => ChannelRepositoryInterface::class,
            EnvCredentialsProvider::class => EnvCredentialsProvider::class,
            EntityManagerInterface::class => EntityManagerInterface::class,
            ChannelContextInterface::class => ChannelContextInterface::class,
            HostMatcher::class => HostMatcher::class,
        ];
    }

    private function registerShopServices(ContainerBuilder $container): void
    {
        foreach ($this->getShopServiceClasses() as $serviceId => $serviceClass) {
            $container->register($serviceId, $serviceClass)->setSynthetic(true)->setPublic(true);
        }
    }

    private function setShopServices(ContainerBuilder $container): void
    {
        foreach ($this->getShopServiceClasses() as $serviceId => $serviceClass) {
            $container->set($serviceId, $this->createStub($serviceClass));
        }
    }

    /**
     * @param array<string, mixed> $rawConfig
     *
     * @return array<string, mixed>
     */
    private function processConfiguration(array $rawConfig): array
    {
        $container = new ContainerBuilder();
        $extension = (new NeikaSyliusPlugin())->getContainerExtension();
        $configuration = $extension->getConfiguration([], $container);

        return (new Processor())->processConfiguration($configuration, [$rawConfig]);
    }

    /**
     * @return array<string, string|bool>
     */
    private function getExpectedWidgetContext(): array
    {
        return [
            'backend_url' => 'https://backend.test',
            'widget_cdn_url' => '',
            'defer' => true,
        ];
    }

    /**
     * @param list<string>         $extensionAliases
     * @param array<string, mixed> $widgetConfig
     */
    private function createContainer(array $extensionAliases, array $widgetConfig = []): ContainerBuilder
    {
        $container = new ContainerBuilder();

        foreach ($extensionAliases as $alias) {
            $container->registerExtension(new NamedExtension($alias));
        }

        $container->prependExtensionConfig('neika', [
            'backend_url' => 'https://backend.test',
            'widget' => $widgetConfig,
        ]);

        return $container;
    }

    private function prependExtension(ContainerBuilder $container): void
    {
        $instanceof = [];
        $configurator = new ContainerConfigurator(
            $container,
            new PhpFileLoader($container, new FileLocator(__DIR__)),
            $instanceof,
            __FILE__,
            __FILE__,
        );

        (new NeikaSyliusPlugin())->prependExtension($configurator, $container);
    }
}
