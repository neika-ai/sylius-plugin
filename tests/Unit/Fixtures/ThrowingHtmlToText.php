<?php

declare(strict_types=1);

namespace Neika\SyliusPlugin\Tests\Unit\Fixtures;

use Neika\Sdk\Text\HtmlToText;

class ThrowingHtmlToText extends HtmlToText
{
    public function convert(string $html): string
    {
        throw new \RuntimeException('Conversion failed.');
    }
}
