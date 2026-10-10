<?php

namespace App\Fiscal;

/** Curl callbacks and synthetic chunk fixtures share these pre-buffer bounds. */
final class AssistantProviderResponseBuffer
{
    private int $headerBytes = 0;

    private int $status = 0;

    private bool $json = false;

    private string $body = '';

    public function __construct(private readonly float $deadline) {}

    public function header(#[\SensitiveParameter] string $line): int
    {
        $length = strlen($line);
        if (hrtime(true) / 1e9 >= $this->deadline || $this->headerBytes + $length > 16384) {
            return 0;
        }
        $this->headerBytes += $length;
        if (preg_match('/^HTTP\/[0-9.]+ ([0-9]{3})\b/', $line, $matches) === 1) {
            $this->status = (int) $matches[1];
            $this->json = false;
            if (! in_array($this->status, [100, 200], true)) {
                return 0;
            }
        } elseif (str_starts_with(strtolower($line), 'content-type:')) {
            $this->json = preg_match('/^content-type:\s*application\/json(?:\s*;\s*charset=utf-8)?\s*$/iD', trim($line)) === 1;
            if (! $this->json) {
                return 0;
            }
        } elseif (str_starts_with(strtolower($line), 'content-encoding:') && strtolower(trim(substr($line, 17))) !== 'identity') {
            return 0;
        } elseif (str_starts_with(strtolower($line), 'content-length:')) {
            $value = trim(substr($line, 15));
            if (! ctype_digit($value) || strlen($value) > 5 || (int) $value > 65536) {
                return 0;
            }
        }

        return $length;
    }

    public function chunk(#[\SensitiveParameter] string $chunk): int
    {
        $length = strlen($chunk);
        if (hrtime(true) / 1e9 >= $this->deadline || ! $this->json || $this->status !== 200 || strlen($this->body) + $length > 65536) {
            return 0;
        }
        $this->body .= $chunk;

        return $length;
    }

    public function finish(): string
    {
        abort_unless(hrtime(true) / 1e9 < $this->deadline && $this->status === 200 && $this->json && $this->body !== '', 503);

        return $this->body;
    }
}
