<?php

namespace App\Domain\AI\DTO;

/**
 * One finding about an extraction, persisted in AiProcessing.result.issues.
 * `value` is only part of the array when it was given, even if it is null.
 */
final readonly class ExtractionIssue
{
    public const ERROR = 'error';

    public const REVIEW = 'review';

    private function __construct(
        public string $path,
        public string $code,
        public string $severity,
        public string $message,
        public bool $hasValue = false,
        public mixed $value = null,
    ) {}

    public static function error(string $path, string $code, string $message): self
    {
        return new self($path, $code, self::ERROR, $message);
    }

    public static function review(string $path, string $code, string $message): self
    {
        return new self($path, $code, self::REVIEW, $message);
    }

    public function withValue(mixed $value): self
    {
        return new self($this->path, $this->code, $this->severity, $this->message, true, $value);
    }

    /** @return array{path: string, code: string, severity: string, message: string, value?: mixed} */
    public function toArray(): array
    {
        $issue = [
            'path' => $this->path,
            'code' => $this->code,
            'severity' => $this->severity,
            'message' => $this->message,
        ];
        if ($this->hasValue) {
            $issue['value'] = $this->value;
        }

        return $issue;
    }
}
