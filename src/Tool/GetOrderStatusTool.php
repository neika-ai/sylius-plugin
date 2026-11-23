<?php

declare(strict_types=1);

namespace Neika\SyliusPlugin\Tool;

use Neika\Sdk\Contract\ChatbotToolInterface;
use Neika\Sdk\DTO\ContentItem;
use Neika\Sdk\DTO\FormDefinition;
use Neika\Sdk\DTO\FormField;
use Neika\Sdk\DTO\PrivateValue;
use Neika\Sdk\DTO\ToolCallContext;
use Neika\Sdk\DTO\ToolDefinition;
use Neika\Sdk\DTO\ToolResult;
use Neika\Sdk\DTO\ToolUiHints;
use Neika\Sdk\Enum\FormFieldType;
use Neika\SymfonyBundle\NeikaSymfonyBundle;
use Neika\SymfonyBundle\Translation\ChannelFallbackTranslator;
use Neika\SyliusPlugin\Contract\ProductIndexabilityInterface;
use Neika\SyliusPlugin\Contract\ProductViewFactoryInterface;
use Neika\SyliusPlugin\Tool\DTO\OrderStatusArguments;
use Sylius\Bundle\MoneyBundle\Formatter\MoneyFormatterInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Order\Model\OrderInterface as BaseOrderInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

class GetOrderStatusTool implements ChatbotToolInterface
{
    private const ORDER_NUMBER_KEY = 'customer_order_number';
    private const EMAIL_KEY = 'customer_email';
    private const TRACKING_CODE_KEY = 'tracking_code';

    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,

        #[Autowire(service: ChannelFallbackTranslator::class)]
        private readonly TranslatorInterface $translator,

        private readonly MoneyFormatterInterface      $moneyFormatter,
        private readonly ProductViewFactoryInterface  $productViewFactory,
        private readonly ProductIndexabilityInterface $productIndexability,
    ) {
    }

    public function getDefinition(): ToolDefinition
    {
        return new ToolDefinition(
            'get_order_status',
            'neika.tool.get_order_status.description',
            new ToolUiHints(new FormDefinition(
                'get_order_status',
                'neika.tool.get_order_status.form.title',
                [
                    new FormField(
                        'orderNumber',
                        'neika.tool.get_order_status.form.order_number',
                        FormFieldType::Text,
                        true,
                        placeholder: self::ORDER_NUMBER_KEY,
                    ),
                    new FormField(
                        'email',
                        'neika.tool.get_order_status.form.email',
                        FormFieldType::Email,
                        true,
                        placeholder: self::EMAIL_KEY,
                    ),
                ],
                'neika.tool.get_order_status.form.submit',
            )),
            translationDomain: NeikaSymfonyBundle::TRANSLATION_DOMAIN,
        );
    }

    public function getArgumentsClass(): string
    {
        return OrderStatusArguments::class;
    }

    public function execute(object $arguments, ToolCallContext $context): ToolResult
    {
        $order = $this->findOrder($arguments);
        $isVisible = $order instanceof OrderInterface && $this->isOwnedBy($order, $arguments->getTrimmedEmail());
        if (!$isVisible) {
            return new ToolResult(
                [new ContentItem($this->translator->trans(
                    'neika.tool.get_order_status.not_found',
                    ['%number%' => $this->formatSlot(self::ORDER_NUMBER_KEY)],
                    NeikaSymfonyBundle::TRANSLATION_DOMAIN,
                    $context->locale,
                ))],
                privateValues: [new PrivateValue(self::ORDER_NUMBER_KEY, $arguments->getTrimmedOrderNumber())],
            );
        }

        $trackingValues = $this->createTrackingValues($order);
        $locale = $context->locale;

        return new ToolResult(
            [new ContentItem($this->translator->trans(
                'neika.tool.get_order_status.summary',
                [
                    '%number%' => $this->formatSlot(self::ORDER_NUMBER_KEY),
                    '%state%' => $this->translateState((string) $order->getState(), $locale),
                    '%paymentState%' => $this->translateState((string) $order->getPaymentState(), $locale),
                    '%shippingState%' => $this->translateState((string) $order->getShippingState(), $locale),
                    '%total%' => $this->moneyFormatter->format($order->getTotal(), (string) $order->getCurrencyCode(), $locale),
                    '%shippingMethod%' => $this->formatMethod('shipping_method', $this->findShippingMethodNames($order, $locale), $locale),
                    '%paymentMethod%' => $this->formatMethod('payment_method', $this->findPaymentMethodNames($order, $locale), $locale),
                    '%tracking%' => $this->formatTracking($trackingValues, $locale),
                    '%items%' => $order->getTotalQuantity(),
                    '%itemLines%' => $this->formatItems($order, $locale),
                ],
                NeikaSymfonyBundle::TRANSLATION_DOMAIN,
                $locale,
            ))],
            privateValues: [new PrivateValue(self::ORDER_NUMBER_KEY, (string) $order->getNumber()), ...$trackingValues],
        );
    }

    private function findOrder(OrderStatusArguments $arguments): ?OrderInterface
    {
        $order = $this->orderRepository->findOneByNumber($arguments->getTrimmedOrderNumber());

        if ($order instanceof OrderInterface) {
            return $order;
        }

        $normalizedOrder = $this->orderRepository->findOneByNumber($arguments->getNormalizedOrderNumber());

        return $normalizedOrder instanceof OrderInterface ? $normalizedOrder : null;
    }

    private function isOwnedBy(OrderInterface $order, string $email): bool
    {
        $isPlaced = $order->getState() !== BaseOrderInterface::STATE_CART;
        if (!$isPlaced) {
            return false;
        }

        $customer = $order->getCustomer();
        if ($customer === null) {
            return false;
        }

        $customerEmail = $customer->getEmail();
        if ($customerEmail === null) {
            return false;
        }

        return strcasecmp($customerEmail, $email) === 0;
    }

    private function translateState(string $state, string $locale): string
    {
        $labelKey = sprintf('sylius.ui.%s', $state);
        $label = $this->translator->trans($labelKey, [], 'messages', $locale);

        return $label === $labelKey ? $state : $label;
    }

    /**
     * @return list<string>
     */
    private function findShippingMethodNames(OrderInterface $order, string $locale): array
    {
        $names = [];
        foreach ($order->getShipments() as $shipment) {
            $method = $shipment instanceof ShipmentInterface ? $shipment->getMethod() : null;
            $name = (string) $method?->getTranslation($locale)->getName();
            $hasName = $name !== '';
            if ($hasName) {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @return list<string>
     */
    private function findPaymentMethodNames(OrderInterface $order, string $locale): array
    {
        $name = (string) $order->getLastPayment()?->getMethod()?->getTranslation($locale)->getName();

        return $name === '' ? [] : [$name];
    }

    /**
     * @param list<string> $names
     */
    private function formatMethod(string $key, array $names, string $locale): string
    {
        if ($names === []) {
            return '';
        }

        return $this->translator->trans(
            sprintf('neika.tool.get_order_status.%s', $key),
            ['%method%' => implode(', ', $names)],
            NeikaSymfonyBundle::TRANSLATION_DOMAIN,
            $locale,
        );
    }

    private function formatItems(OrderInterface $order, string $locale): string
    {
        $orderChannel = $order->getChannel();
        $channel = $orderChannel instanceof ChannelInterface ? $orderChannel : null;
        $lines = [];
        foreach ($order->getItems() as $orderItem) {
            if ($orderItem instanceof OrderItemInterface) {
                $lines[] = $this->formatItemLine($orderItem, $channel, $locale);
            }
        }

        if ($lines === []) {
            return '';
        }

        return $this->translator->trans(
            'neika.tool.get_order_status.items',
            ['%lines%' => implode("\n", $lines)],
            NeikaSymfonyBundle::TRANSLATION_DOMAIN,
            $locale,
        );
    }

    private function formatItemLine(OrderItemInterface $orderItem, ?ChannelInterface $channel, string $locale): string
    {
        $variant = $orderItem->getVariant();
        $line = sprintf('- %d× %s', $orderItem->getQuantity(), $this->buildBoughtName($orderItem));
        $code = (string) $variant?->getCode();
        $hasCode = $code !== '';
        if ($hasCode) {
            $line .= sprintf(' (%s)', $code);
        }

        $url = $this->findLiveProductUrl($variant, $channel, $locale);
        if ($url === null) {
            return $line;
        }

        return sprintf('%s: %s', $line, $url);
    }

    private function buildBoughtName(OrderItemInterface $orderItem): string
    {
        $productName = (string) $orderItem->getProductName();
        $variantName = (string) $orderItem->getVariantName();
        $hasOwnName = $variantName !== '' && $variantName !== $productName;

        if (!$hasOwnName) {
            return $productName;
        }

        return sprintf('%s — %s', $productName, $variantName);
    }

    private function findLiveProductUrl(?ProductVariantInterface $variant, ?ChannelInterface $channel, string $locale): ?string
    {
        if ($variant === null) {
            return null;
        }
        if ($channel === null) {
            return null;
        }

        $isLive = $this->productIndexability->isIndexable($variant, $channel);
        if (!$isLive) {
            return null;
        }

        return $this->productViewFactory->createItem($variant, $channel, $locale)?->url;
    }

    /**
     * @return list<PrivateValue>
     */
    private function createTrackingValues(OrderInterface $order): array
    {
        $trackingValues = [];
        foreach ($order->getShipments() as $shipment) {
            if (!$shipment instanceof ShipmentInterface) {
                continue;
            }
            $trackingCode = $shipment->getTracking();
            if ($trackingCode !== null && $trackingCode !== '') {
                $trackingValues[] = new PrivateValue($this->createTrackingKey(count($trackingValues)), $trackingCode);
            }
        }

        return $trackingValues;
    }

    private function createTrackingKey(int $precedingCodes): string
    {
        return $precedingCodes === 0
            ? self::TRACKING_CODE_KEY
            : sprintf('%s_%d', self::TRACKING_CODE_KEY, $precedingCodes + 1);
    }

    /**
     * @param list<PrivateValue> $trackingValues
     */
    private function formatTracking(array $trackingValues, string $locale): string
    {
        if ($trackingValues === []) {
            return '';
        }

        $trackingSlots = array_map(
            fn (PrivateValue $trackingValue): string => $this->formatSlot($trackingValue->key),
            $trackingValues,
        );

        return $this->translator->trans(
            'neika.tool.get_order_status.tracking',
            ['%codes%' => implode(', ', $trackingSlots)],
            NeikaSymfonyBundle::TRANSLATION_DOMAIN,
            $locale,
        );
    }

    private function formatSlot(string $key): string
    {
        return sprintf('{{%s}}', $key);
    }
}
