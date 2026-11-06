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
use Neika\SyliusPlugin\Tool\DTO\OrderStatusArguments;
use Sylius\Component\Core\Model\OrderInterface;
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

        return new ToolResult(
            [new ContentItem($this->translator->trans(
                'neika.tool.get_order_status.summary',
                [
                    '%number%' => $this->formatSlot(self::ORDER_NUMBER_KEY),
                    '%state%' => (string) $order->getState(),
                    '%paymentState%' => (string) $order->getPaymentState(),
                    '%shippingState%' => (string) $order->getShippingState(),
                    '%items%' => $order->getTotalQuantity(),
                    '%total%' => $this->formatTotal($order),
                    '%tracking%' => $this->formatTracking($trackingValues, $context->locale),
                ],
                NeikaSymfonyBundle::TRANSLATION_DOMAIN,
                $context->locale,
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

    private function formatTotal(OrderInterface $order): string
    {
        return sprintf(
            '%s %s',
            number_format($order->getTotal() / 100, 2, '.', ' '),
            (string) $order->getCurrencyCode(),
        );
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
