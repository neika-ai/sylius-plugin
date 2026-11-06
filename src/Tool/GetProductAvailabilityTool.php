<?php

declare(strict_types=1);

namespace Neika\SyliusPlugin\Tool;

use Neika\SyliusPlugin\Channel\ChannelResolver;
use Neika\Sdk\Contract\ChatbotToolInterface;
use Neika\Sdk\DTO\ContentItem;
use Neika\SyliusPlugin\DTO\ProductsBlock;
use Neika\Sdk\DTO\ToolCallContext;
use Neika\Sdk\DTO\ToolDefinition;
use Neika\Sdk\DTO\ToolResult;
use Neika\SymfonyBundle\NeikaSymfonyBundle;
use Neika\SymfonyBundle\Translation\ChannelFallbackTranslator;
use Neika\SyliusPlugin\Product\ProductViewFactory;
use Neika\SyliusPlugin\Tool\DTO\ProductAvailabilityArguments;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Repository\ProductVariantRepositoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

class GetProductAvailabilityTool implements ChatbotToolInterface
{
    public function __construct(
        private readonly ChannelResolver     $channelResolver,
        private readonly ProductViewFactory  $productViewFactory,

        #[Autowire(service: ChannelFallbackTranslator::class)]
        private readonly TranslatorInterface $translator,

        #[Autowire(service: 'sylius.repository.product_variant')]
        private readonly ProductVariantRepositoryInterface $variantRepository,
    ) {
    }

    public function getDefinition(): ToolDefinition
    {
        return new ToolDefinition(
            'get_product_availability',
            'neika.tool.get_product_availability.description',
            translationDomain: NeikaSymfonyBundle::TRANSLATION_DOMAIN,
        );
    }

    public function getArgumentsClass(): string
    {
        return ProductAvailabilityArguments::class;
    }

    public function execute(object $arguments, ToolCallContext $context): ToolResult
    {
        $channel = $this->channelResolver->resolve($context->channelCode);
        $contentLines = [];
        $items = [];

        foreach ($arguments->codes as $code) {
            $variant = $this->variantRepository->findOneBy(['code' => $code]);
            $item = null;
            if ($variant instanceof ProductVariantInterface) {
                $item = $this->productViewFactory->createItem($variant, $channel, $context->locale);
            }
            if ($item === null) {
                $contentLines[] = $this->translator->trans(
                    'neika.tool.get_product_availability.unknown',
                    ['%code%' => $code],
                    NeikaSymfonyBundle::TRANSLATION_DOMAIN,
                    $context->locale,
                );

                continue;
            }

            $items[] = $item;
            $lineKey = $item->inStock
                ? 'neika.tool.get_product_availability.line_in_stock'
                : 'neika.tool.get_product_availability.line_out_of_stock';
            $contentLines[] = $this->translator->trans(
                $lineKey,
                [
                    '%code%' => $item->code,
                    '%name%' => $item->name,
                    '%price%' => number_format($item->priceMinor / 100, 2, '.', ' '),
                    '%currency%' => $item->currency,
                ],
                NeikaSymfonyBundle::TRANSLATION_DOMAIN,
                $context->locale,
            );
        }

        $blocks = [];
        if ($items !== []) {
            $blocks[] = new ProductsBlock($items);
        }

        return new ToolResult(
            [new ContentItem(implode("\n", $contentLines))],
            $blocks,
        );
    }
}
