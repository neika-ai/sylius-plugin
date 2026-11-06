<?php

declare(strict_types=1);

namespace Neika\SyliusPlugin\Exception;

use Neika\Sdk\Enum\ApiErrorCode;
use Neika\Sdk\Exception\ChatbotApiException;
use Symfony\Component\HttpFoundation\Response;

class InvalidChannelException extends ChatbotApiException
{
    public function __construct(?string $channelCode = null, ?\Throwable $previous = null)
    {
        $message = 'No channel could be resolved for the request.';
        if ($channelCode !== null && $channelCode !== '') {
            $message = sprintf('Channel "%s" does not exist.', $channelCode);
        }

        parent::__construct($message, 0, $previous);
    }

    public function getErrorCode(): ApiErrorCode
    {
        return ApiErrorCode::InvalidChannel;
    }

    public function getStatusCode(): int
    {
        return Response::HTTP_BAD_REQUEST;
    }
}
