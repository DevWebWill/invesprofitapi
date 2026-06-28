<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class CassandraAiRequestException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly ?int $statusCode = null,
        private readonly ?string $responseBody = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function statusCode(): ?int
    {
        return $this->statusCode;
    }

    public function responseBody(): ?string
    {
        return $this->responseBody;
    }
}
