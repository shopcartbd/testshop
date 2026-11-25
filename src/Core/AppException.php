<?php
declare(strict_types=1);
namespace AllStore\Core;

/** Safe, user-facing application error. Never contains SQL, paths or secrets. */
class AppException extends \RuntimeException
{
    public function __construct(string $message, private int $httpStatus = 400, private array $errors = [])
    {
        parent::__construct($message);
    }
    public function httpStatus(): int { return $this->httpStatus; }
    public function errors(): array { return $this->errors; }
}
