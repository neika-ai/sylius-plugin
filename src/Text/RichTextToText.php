<?php

declare(strict_types=1);

namespace Neika\SyliusPlugin\Text;

use Neika\Sdk\Text\HtmlToText;
use MonsieurBiz\SyliusRichEditorPlugin\Twig\RichEditorExtension;

class RichTextToText
{
    public function __construct(
        private readonly HtmlToText           $htmlToText,
        private readonly ?RichEditorExtension $richEditor = null,
    ) {
    }

    public function convert(string $content): string
    {
        $html = $this->richEditor?->renderField([], $content) ?? $content;

        return $this->htmlToText->convert($html);
    }
}
