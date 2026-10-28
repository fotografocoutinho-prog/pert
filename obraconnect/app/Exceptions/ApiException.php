<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Erro de negócio com código estável para o frontend (ex.: PLAN_LIMIT_REACHED).
 */
class ApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status = 400,
        public readonly array $meta = [],
    ) {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(array_filter([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
            'meta' => $this->meta ?: null,
        ]), $this->status);
    }

    public static function forbidden(string $message, string $code = 'FORBIDDEN'): self
    {
        return new self($message, $code, 403);
    }

    public static function conflict(string $message, string $code): self
    {
        return new self($message, $code, 409);
    }

    public static function unprocessable(string $message, string $code): self
    {
        return new self($message, $code, 422);
    }
}
