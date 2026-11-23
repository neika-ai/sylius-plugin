<?php

declare(strict_types=1);

namespace Neika\SyliusPlugin\Tests\Unit\Tool;

use Doctrine\Common\Collections\ArrayCollection;
use Neika\Sdk\DTO\ToolCallContext;
use Neika\Sdk\DTO\ToolResult;
use Neika\SyliusPlugin\Contract\ProductIndexabilityInterface;
use Neika\SyliusPlugin\Contract\ProductViewFactoryInterface;
use Neika\SyliusPlugin\DTO\ProductItem;
use Neika\SyliusPlugin\Tool\DTO\OrderStatusArguments;
use Neika\SyliusPlugin\Tool\GetOrderStatusTool;
use PHPUnit\Framework\TestCase;
use Sylius\Bundle\MoneyBundle\Formatter\MoneyFormatterInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Payment\Model\PaymentMethodTranslationInterface;
use Sylius\Component\Shipping\Model\ShippingMethodTranslationInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class GetOrderStatusToolTest extends TestCase
{
    private function createTool(?OrderInterface $order, string $storedNumber = '000000042'): GetOrderStatusTool
    {
        $orderRepository = $this->createStub(OrderRepositoryInterface::class);
        $orderRepository->method('findOneByNumber')->willReturnCallback(
            fn (string $number): ?OrderInterface => $number === $storedNumber ? $order : null,
        );

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            fn (string $id, array $parameters = [], ?string $domain = null): string => $domain . ':' . $id . '|' . json_encode($parameters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        $moneyFormatter = $this->createStub(MoneyFormatterInterface::class);
        $moneyFormatter->method('format')->willReturnCallback(
            fn (int $amount, string $currencyCode, ?string $locale = null): string => sprintf('%d %s %s', $amount, $currencyCode, $locale),
        );

        $productViewFactory = $this->createStub(ProductViewFactoryInterface::class);
        $productViewFactory->method('createItem')->willReturn(new ProductItem(
            code: 'MOSER-1400',
            productCode: 'MOSER',
            name: 'Moser 1400 (current catalogue name)',
            url: 'https://shop.example/cs_CZ/products/moser-1400',
            priceMinor: 94900,
            currency: 'CZK',
            imageUrl: null,
            inStock: true,
        ));

        $productIndexability = $this->createStub(ProductIndexabilityInterface::class);
        $productIndexability->method('isIndexable')->willReturnCallback(
            fn (ProductVariantInterface $variant): bool => $variant->getCode() === 'MOSER-1400',
        );

        return new GetOrderStatusTool($orderRepository, $translator, $moneyFormatter, $productViewFactory, $productIndexability);
    }

    private function createOrder(string $customerEmail, string $state = OrderInterface::STATE_NEW): OrderInterface
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getEmail')->willReturn($customerEmail);

        $shippingMethod = $this->createStub(ShippingMethodInterface::class);
        $shippingMethod->method('getTranslation')->willReturn($this->createShippingMethodTranslation('Osobní odběr'));

        $shipment = $this->createStub(ShipmentInterface::class);
        $shipment->method('getTracking')->willReturn('TRACK123');
        $shipment->method('getMethod')->willReturn($shippingMethod);
        $shipments = [$shipment];

        $paymentMethod = $this->createStub(PaymentMethodInterface::class);
        $paymentMethod->method('getTranslation')->willReturn($this->createPaymentMethodTranslation('Hotově při převzetí'));

        $payment = $this->createStub(PaymentInterface::class);
        $payment->method('getMethod')->willReturn($paymentMethod);

        $order = $this->createStub(OrderInterface::class);
        $order->method('getState')->willReturn($state);
        $order->method('getCustomer')->willReturn($customer);
        $order->method('getNumber')->willReturn('000000042');
        $order->method('getPaymentState')->willReturn('paid');
        $order->method('getShippingState')->willReturn('shipped');
        $order->method('getTotal')->willReturn(123456);
        $order->method('getCurrencyCode')->willReturn('CZK');
        $order->method('getShipments')->willReturn(new ArrayCollection($shipments));
        $order->method('getLastPayment')->willReturn($payment);
        $order->method('getChannel')->willReturn($this->createStub(ChannelInterface::class));
        $order->method('getItems')->willReturn(new ArrayCollection([
            $this->createOrderItem(2, 'MOSER-1400', 'Moser 1400', 'Black'),
            $this->createOrderItem(1, 'OLD-BLADE', 'Discontinued blade', 'Discontinued blade'),
        ]));

        return $order;
    }

    private function createOrderItem(int $quantity, string $variantCode, string $productName, string $variantName): OrderItemInterface
    {
        $variant = $this->createStub(ProductVariantInterface::class);
        $variant->method('getCode')->willReturn($variantCode);

        $orderItem = $this->createStub(OrderItemInterface::class);
        $orderItem->method('getQuantity')->willReturn($quantity);
        $orderItem->method('getVariant')->willReturn($variant);
        $orderItem->method('getProductName')->willReturn($productName);
        $orderItem->method('getVariantName')->willReturn($variantName);

        return $orderItem;
    }

    private function createShippingMethodTranslation(string $name): ShippingMethodTranslationInterface
    {
        $translation = $this->createStub(ShippingMethodTranslationInterface::class);
        $translation->method('getName')->willReturn($name);

        return $translation;
    }

    private function createPaymentMethodTranslation(string $name): PaymentMethodTranslationInterface
    {
        $translation = $this->createStub(PaymentMethodTranslationInterface::class);
        $translation->method('getName')->willReturn($name);

        return $translation;
    }

    private function createContext(): ToolCallContext
    {
        return new ToolCallContext('0b9cdd42-4b1f-4f1f-9a55-111111111111', 'cs_CZ');
    }

    /**
     * @return array<string, string>
     */
    private function readPrivateValues(ToolResult $result): array
    {
        $privateValues = [];
        foreach ($result->privateValues as $privateValue) {
            $privateValues[$privateValue->key] = $privateValue->value;
        }

        return $privateValues;
    }

    public function testMatchingEmailReturnsSummary(): void
    {
        $tool = $this->createTool($this->createOrder('customer@example.com'));

        $result = $tool->execute(
            new OrderStatusArguments('000000042', 'customer@example.com'),
            $this->createContext(),
        );

        self::assertFalse($result->isError);
        $text = $result->content[0]->text;
        self::assertStringContainsString('get_order_status.summary', $text);
        self::assertStringContainsString('paid', $text);
        self::assertStringContainsString('shipped', $text);
        self::assertStringContainsString('{{customer_order_number}}', $text);
        self::assertStringContainsString('{{tracking_code}}', $text);
        self::assertStringNotContainsString('TRACK123', $text);
        self::assertStringNotContainsString('000000042', $text);
        self::assertSame(
            ['customer_order_number' => '000000042', 'tracking_code' => 'TRACK123'],
            $this->readPrivateValues($result),
        );
        self::assertSame([], $result->blocks);
    }

    public function testSummaryNamesStatesMethodsAndItemsTheWayTheShopShowsThem(): void
    {
        $tool = $this->createTool($this->createOrder('customer@example.com'));

        $result = $tool->execute(
            new OrderStatusArguments('000000042', 'customer@example.com'),
            $this->createContext(),
        );

        $text = $result->content[0]->text;
        self::assertStringContainsString('"%state%":"messages:sylius.ui.new|[]"', $text);
        self::assertStringContainsString('"%paymentState%":"messages:sylius.ui.paid|[]"', $text);
        self::assertStringContainsString('"%shippingState%":"messages:sylius.ui.shipped|[]"', $text);
        self::assertStringContainsString('"%total%":"123456 CZK cs_CZ"', $text);
        self::assertStringContainsString('get_order_status.shipping_method|{\"%method%\":\"Osobní odběr\"}', $text);
        self::assertStringContainsString('get_order_status.payment_method|{\"%method%\":\"Hotově při převzetí\"}', $text);
        self::assertStringContainsString('- 2× Moser 1400 — Black (MOSER-1400): https://shop.example/cs_CZ/products/moser-1400', $text);
        self::assertStringContainsString('- 1× Discontinued blade (OLD-BLADE)\"}', $text);
    }

    public function testPastedOrderNumberAndEmailAreCleanedBeforeTheLookup(): void
    {
        $tool = $this->createTool($this->createOrder('customer@example.com'));

        $result = $tool->execute(
            new OrderStatusArguments(' #000-000 042. ', ' customer@example.com '),
            $this->createContext(),
        );

        self::assertStringContainsString('get_order_status.summary', $result->content[0]->text);
    }

    public function testOrderNumberWithSeparatorsMatchesAsTyped(): void
    {
        $tool = $this->createTool($this->createOrder('customer@example.com'), 'FR-000042');

        $result = $tool->execute(
            new OrderStatusArguments('FR-000042', 'customer@example.com'),
            $this->createContext(),
        );

        self::assertStringContainsString('get_order_status.summary', $result->content[0]->text);
    }

    public function testGuestOrderEmailMatchesCaseInsensitively(): void
    {
        $tool = $this->createTool($this->createOrder('Guest@Example.COM'));

        $result = $tool->execute(
            new OrderStatusArguments('000000042', 'guest@example.com'),
            $this->createContext(),
        );

        self::assertFalse($result->isError);
        self::assertStringContainsString('get_order_status.summary', $result->content[0]->text);
    }

    public function testEmailMismatchReturnsNotFoundWithoutError(): void
    {
        $tool = $this->createTool($this->createOrder('customer@example.com'));

        $result = $tool->execute(
            new OrderStatusArguments('000000042', 'attacker@example.com'),
            $this->createContext(),
        );

        self::assertFalse($result->isError);
        $text = $result->content[0]->text;
        self::assertStringContainsString('get_order_status.not_found', $text);
        self::assertStringContainsString('{{customer_order_number}}', $text);
        self::assertStringNotContainsString('000000042', $text);
        self::assertSame(['customer_order_number' => '000000042'], $this->readPrivateValues($result));
    }

    public function testCartOrderIsReportedAsNotFound(): void
    {
        $tool = $this->createTool($this->createOrder('customer@example.com', OrderInterface::STATE_CART));

        $result = $tool->execute(
            new OrderStatusArguments('000000042', 'customer@example.com'),
            $this->createContext(),
        );

        self::assertFalse($result->isError);
        self::assertStringContainsString('get_order_status.not_found', $result->content[0]->text);
    }

    public function testMissingOrderIsReportedAsNotFound(): void
    {
        $tool = $this->createTool(null);

        $result = $tool->execute(
            new OrderStatusArguments('000000042', 'customer@example.com'),
            $this->createContext(),
        );

        self::assertFalse($result->isError);
        self::assertStringContainsString('get_order_status.not_found', $result->content[0]->text);
    }
}
