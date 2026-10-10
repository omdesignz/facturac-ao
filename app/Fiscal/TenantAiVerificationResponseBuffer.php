<?php

namespace App\Fiscal;

use App\Exceptions\TenantAiStorageUnavailable;

/** Bounded raw-wire parser. Untrusted metadata never leaves as arbitrary text. */
final class TenantAiVerificationResponseBuffer
{
    private int $headerBytes = 0;

    private int $bodyBytes = 0;

    private int $status = 0;

    private bool $closed = false;

    private bool $invalid = false;

    private string $rejection = 'malformed_response';

    /** @var array<string, string> */
    private array $headers = [];

    private string $body = '';

    public function __construct(private readonly float $deadline) {}

    public function rejectionOutcome(): ?string
    {
        return $this->invalid ? $this->rejection : null;
    }

    public function header(#[\SensitiveParameter] string $line): int
    {
        $length = strlen($line);
        if (! $this->invalid && ($length > 4096 || $this->headerBytes + $length > 16384 || count($this->headers) >= 64 && $line !== "\r\n")) {
            $this->rejection = 'transport_policy_rejected';
        }
        if ($this->invalid || $this->closed || hrtime(true) / 1e9 >= $this->deadline || $length > 4096 || $this->headerBytes + $length > 16384) {
            $this->invalid = true;

            return 0;
        }
        $this->headerBytes += $length;
        if ($this->status === 0) {
            if (preg_match('/\AHTTP\/1\.[01] ([1-5][0-9]{2}) [\x20-\x7e]*\r\n\z/', $line, $match) !== 1) {
                $this->invalid = true;

                return 0;
            }
            $this->status = (int) $match[1];
        } elseif ($line === "\r\n") {
            $this->closed = true;
        } else {
            if (count($this->headers) >= 64 || preg_match('/\A([!#$%&\x27*+.^_`|~0-9A-Za-z-]+):[ ]*([\x20-\x7e]*)\r\n\z/', $line, $match) !== 1) {
                $this->invalid = true;

                return 0;
            }
            $name = strtolower($match[1]);
            if (isset($this->headers[$name])) {
                $this->invalid = true;

                return 0;
            }
            $this->headers[$name] = trim($match[2], ' ');
        }

        return $length;
    }

    public function chunk(#[\SensitiveParameter] string $chunk): int
    {
        $length = strlen($chunk);
        if (! $this->invalid && $this->bodyBytes + $length > 65536) {
            $this->rejection = 'transport_policy_rejected';
        }
        if ($this->invalid || ! $this->closed || hrtime(true) / 1e9 >= $this->deadline || $this->bodyBytes + $length > 65536) {
            $this->invalid = true;

            return 0;
        }
        $this->bodyBytes += $length;
        if ($this->status === 200) {
            $this->body .= $chunk;
        }

        return $length;
    }

    public function finish(string $model, string $organization, string $workspace): CredentialVerificationEvidence
    {
        try {
            if (hrtime(true) / 1e9 >= $this->deadline) {
                return new CredentialVerificationEvidence('timeout');
            }
            if ($this->invalid) {
                return new CredentialVerificationEvidence($this->rejection);
            }
            if (($this->headers['content-encoding'] ?? 'identity') !== 'identity'
                || isset($this->headers['content-length']) && preg_match('/\A[0-9]+\z/', $this->headers['content-length']) === 1
                    && (strlen($this->headers['content-length']) > 5 || (int) $this->headers['content-length'] > 65536)) {
                return new CredentialVerificationEvidence('transport_policy_rejected');
            }
            if (! $this->closed
                || isset($this->headers['transfer-encoding'], $this->headers['content-length'])
                || (isset($this->headers['content-length']) && (preg_match('/\A(?:0|[1-9][0-9]{0,4})\z/', $this->headers['content-length']) !== 1
                    || (int) $this->headers['content-length'] !== $this->bodyBytes))) {
                return new CredentialVerificationEvidence('malformed_response');
            }
            if ($this->status !== 200) {
                return new CredentialVerificationEvidence(match ($this->status) {
                    401 => 'credential_rejected', 403 => 'provider_denied', 404 => 'model_unavailable',
                    429 => 'provider_unavailable',
                    default => $this->status >= 500 ? 'provider_unavailable'
                        : ($this->status >= 300 && $this->status < 400 ? 'transport_policy_rejected' : 'malformed_response'),
                });
            }
            if (preg_match('/\Aapplication\/json(?:\s*;\s*charset=utf-8)?\z/i', $this->headers['content-type'] ?? '') !== 1) {
                return new CredentialVerificationEvidence('malformed_response');
            }
            $data = self::object($this->body);
            if (($data['type'] ?? null) !== 'model' || ! is_string($data['id'] ?? null)) {
                return new CredentialVerificationEvidence('malformed_response');
            }
            if ($data['id'] !== $model) {
                return new CredentialVerificationEvidence('model_unavailable');
            }
            if (($this->headers['anthropic-organization-id'] ?? null) !== $organization || ($this->headers['anthropic-workspace-id'] ?? null) !== $workspace
                || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $organization) !== 1
                || preg_match('/\Awrkspc_[A-Za-z0-9]{1,120}\z/', $workspace) !== 1) {
                return new CredentialVerificationEvidence('account_context_unproven');
            }

            return new CredentialVerificationEvidence('provider_authenticated_model_visible', $model, $organization, $workspace);
        } catch (\Throwable) {
            return new CredentialVerificationEvidence('malformed_response');
        } finally {
            $this->body = '';
            $this->headers = [];
        }
    }

    /** Depth-eight object parsing with duplicate member detection before projection.
     * @return array<string, mixed>
     */
    private static function object(#[\SensitiveParameter] string $json): array
    {
        $object = json_decode($json, false, 8, JSON_THROW_ON_ERROR);
        if (! $object instanceof \stdClass) {
            throw new TenantAiStorageUnavailable;
        }
        preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|[{}\[\],:]/s', $json, $tokens);
        $stack = [];
        foreach ($tokens[0] as $index => $token) {
            if ($token === '{' || $token === '[') {
                $stack[] = [];
            } elseif ($token === '}' || $token === ']') {
                array_pop($stack);
            } elseif (str_starts_with($token, '"') && ($tokens[0][$index + 1] ?? '') === ':' && $stack !== []) {
                $level = count($stack) - 1;
                $key = json_decode($token, true, 8, JSON_THROW_ON_ERROR);
                if (isset($stack[$level][$key])) {
                    throw new TenantAiStorageUnavailable;
                }
                $stack[$level][$key] = true;
            }
        }

        return get_object_vars($object);
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['response' => 'redacted'];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new TenantAiStorageUnavailable;
    }
}
