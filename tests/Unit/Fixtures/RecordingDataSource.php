<?php

declare(strict_types=1);

namespace Neika\SyliusPlugin\Tests\Unit\Fixtures;

use Neika\Sdk\Contract\ChatbotDataSourceInterface;
use Neika\Sdk\DTO\DocumentPage;
use Neika\Sdk\DTO\SourceDefinition;
use Neika\Sdk\DTO\SourceDocument;
use Neika\Sdk\DTO\SourceQuery;

class RecordingDataSource implements ChatbotDataSourceInterface
{
    /** @var list<string> */
    public array $queriedLocales = [];

    /**
     * @param ?list<string>        $locales
     * @param list<SourceDocument> $documents
     */
    public function __construct(
        private readonly string $name,
        private readonly ?array $locales = null,
        private readonly array  $documents = [],
    ) {
    }

    public function getDefinition(): SourceDefinition
    {
        return new SourceDefinition($this->name, 'description', $this->locales);
    }

    public function getDocuments(SourceQuery $query): DocumentPage
    {
        $this->queriedLocales[] = $query->locale;

        return new DocumentPage($this->documents);
    }
}
