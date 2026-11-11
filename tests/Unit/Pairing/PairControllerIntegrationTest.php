<?php

declare(strict_types=1);

namespace Neika\SyliusPlugin\Tests\Unit\Pairing;

use Doctrine\ORM\EntityManagerInterface;
use Neika\Sdk\Pairing\HostMatcher;
use Neika\Sdk\Pairing\PairingClient;
use Neika\SymfonyBundle\Controller\PairController;
use Neika\SymfonyBundle\Credentials\EnvCredentialsProvider;
use Neika\SymfonyBundle\Pairing\PairingErrorCode;
use Neika\SymfonyBundle\Reporting\BackendReportGuard;
use Neika\SyliusPlugin\Credentials\SyliusCredentialsProvider;
use Neika\SyliusPlugin\Tests\Unit\Fixtures\NeikaChannel;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as PsrResponse;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Log\NullLogger;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Channel\Model\ChannelInterface;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

class PairControllerIntegrationTest extends TestCase
{
    private const VALID_CODE = 'AbCdEfGhIjKlMnOpQrStUvWxYz0123456789-_AbCdE';

    private const BACKEND_URL = 'https://chatbot.example.com';

    private const RETURN_URL = 'https://chatbot.example.com/dashboard/pairings/0199/finish';

    private NeikaChannel $czechChannel;

    private NeikaChannel $slovakChannel;

    protected function setUp(): void
    {
        $this->czechChannel = $this->createChannel('CZ_WEB', 'shop.cz');
        $this->slovakChannel = $this->createChannel('SK_WEB', 'www.shop.sk');
    }

    public function testPairingStoresTheSecretsOnEveryChannelOfTheWebsite(): void
    {
        $response = $this->pair('https://shop.cz', ['shop.cz', 'shop.sk'], 1);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(self::RETURN_URL, $response->getTargetUrl());
        self::assertSame('site_key_1', $this->czechChannel->getNeikaSiteKey());
        self::assertSame('site_key_1', $this->slovakChannel->getNeikaSiteKey());
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $this->czechChannel->getNeikaIngestSecret());
        self::assertSame($this->czechChannel->getNeikaIngestSecret(), $this->slovakChannel->getNeikaIngestSecret());
    }

    public function testACodeRedeemedOnAnotherShopIsRefusedAndWritesNothing(): void
    {
        $response = $this->pair('https://attacker.example.net', ['attacker.example.net'], 0);

        $this->assertWrongShop($response);
    }

    public function testAWebsiteThatDoesNotCoverEveryChannelIsRefusedAndWritesNothing(): void
    {
        $response = $this->pair('https://shop.cz', ['shop.cz'], 0);

        $this->assertWrongShop($response);
    }

    /**
     * @param list<string> $verifiedDomains
     */
    private function pair(string $baseUrl, array $verifiedDomains, int $expectedFlushCount): Response
    {
        $factory = new Psr17Factory();
        $httpClient = $this->createBackendClient($baseUrl, $verifiedDomains);
        $pairingClient = new PairingClient($httpClient, $factory, $factory, self::BACKEND_URL);
        $guard = new BackendReportGuard(new NullLogger(), self::BACKEND_URL, 'prod');
        $provider = $this->createProvider($expectedFlushCount);

        $controller = new PairController(
            $pairingClient,
            $guard,
            $this->createIdentityTranslator(),
            new HostMatcher(),
            self::BACKEND_URL,
            $provider,
        );
        $request = Request::create('/neika-chatbot/pair');

        return $controller->__invoke($request, self::VALID_CODE);
    }

    private function assertWrongShop(Response $response): void
    {
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertStringContainsString('<code>' . PairingErrorCode::WrongShop->value . '</code>', (string) $response->getContent());
        self::assertNull($this->czechChannel->getNeikaSiteKey());
        self::assertNull($this->slovakChannel->getNeikaSiteKey());
        self::assertNull($this->czechChannel->getNeikaIngestSecret());
        self::assertNull($this->slovakChannel->getNeikaIngestSecret());
    }

    private function createProvider(int $expectedFlushCount): SyliusCredentialsProvider
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly($expectedFlushCount))->method('flush');

        $channelContext = $this->createStub(ChannelContextInterface::class);
        $channelContext->method('getChannel')->willReturn($this->czechChannel);

        $envCredentialsProvider = new EnvCredentialsProvider('', '', '');

        return new SyliusCredentialsProvider(
            $envCredentialsProvider,
            $this->createRepository(),
            $channelContext,
            $entityManager,
            new HostMatcher(),
            [],
        );
    }

    /**
     * @return ChannelRepositoryInterface<ChannelInterface>
     */
    private function createRepository(): ChannelRepositoryInterface
    {
        $channelsByCode = [
            'CZ_WEB' => $this->czechChannel,
            'SK_WEB' => $this->slovakChannel,
        ];

        $repository = $this->createStub(ChannelRepositoryInterface::class);
        $repository->method('getClassName')->willReturn(NeikaChannel::class);
        $repository->method('findOneByCode')->willReturnCallback(
            fn (string $code): ?ChannelInterface => $channelsByCode[$code] ?? null,
        );

        return $repository;
    }

    /**
     * @param list<string> $verifiedDomains
     */
    private function createBackendClient(string $baseUrl, array $verifiedDomains): ClientInterface
    {
        $payload = [
            'siteKey' => 'site_key_1',
            'baseUrl' => $baseUrl,
            'channelCodes' => ['CZ_WEB', 'SK_WEB'],
            'returnUrl' => self::RETURN_URL,
            'verifiedDomains' => $verifiedDomains,
        ];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        $httpClient = $this->createStub(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn(new PsrResponse(200, [], $body));

        return $httpClient;
    }

    private function createIdentityTranslator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return $translator;
    }

    private function createChannel(string $code, string $hostname): NeikaChannel
    {
        $channel = new NeikaChannel();
        $channel->setCode($code);
        $channel->setHostname($hostname);

        return $channel;
    }
}
