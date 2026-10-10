<?php

namespace App\Fiscal;

use Symfony\Component\Process\Process;

/** Shared fixed-host DNS bounds; legacy and verification use identical resolution policy. */
trait ResolvesAnthropicAddress
{
    private function resolve(float $deadline): string
    {
        $script = '$r=dns_get_record($argv[1],DNS_A|DNS_AAAA);if(!is_array($r)){exit(1);}foreach($r as $v){if(isset($v["ip"]))echo $v["ip"]."\\n";if(isset($v["ipv6"]))echo $v["ipv6"]."\\n";}';
        $process = new Process([$this->cliExecutable($deadline), '-n', '-r', $script, AssistantProviderProfile::HOST.'.']);
        $process->setTimeout(max(0.001, $deadline - hrtime(true) / 1e9));
        $output = '';
        try {
            $process->run(function (string $type, string $chunk) use (&$output, $deadline): void {
                abort_if($type !== Process::OUT || strlen($output) + strlen($chunk) > 4096 || hrtime(true) / 1e9 >= $deadline, 503);
                $output .= $chunk;
            });
            abort_unless($process->isSuccessful() && hrtime(true) / 1e9 < $deadline, 503);
        } finally {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
        $addresses = explode("\n", trim($output));
        abort_if(count($addresses) > 16, 503);
        foreach ($addresses as $address) {
            abort_unless(self::publicAddress($address), 503);
        }

        return $addresses[0];
    }

    /** Runtime defaults are constants, never request values or PATH/environment searches. */
    private function cliExecutable(float $deadline, string $sapi = PHP_SAPI, string $binary = PHP_BINARY, string $bindir = PHP_BINDIR): string
    {
        $candidate = config('assistant.provider.dns_php_cli');
        if ($candidate === null) {
            $candidate = $sapi === 'cli' ? $binary : $bindir.'/php';
        }
        abort_unless(is_string($candidate) && str_starts_with($candidate, '/') && ! str_contains($candidate, "\0"), 503);
        $executable = realpath($candidate);
        abort_unless(is_string($executable) && is_file($executable) && is_executable($executable), 503);
        abort_if(hrtime(true) / 1e9 >= $deadline, 503);
        $probe = new Process([$executable, '-n', '-r', 'echo PHP_SAPI, ":", PHP_VERSION_ID;']);
        $probe->setTimeout($deadline - hrtime(true) / 1e9);
        $output = '';
        try {
            $probe->run(function (string $type, string $chunk) use (&$output, $deadline): void {
                abort_if($type !== Process::OUT || strlen($output) + strlen($chunk) > 64 || hrtime(true) / 1e9 >= $deadline, 503);
                $output .= $chunk;
            });
            abort_unless($probe->isSuccessful() && $output === 'cli:'.PHP_VERSION_ID && hrtime(true) / 1e9 < $deadline, 503);
        } finally {
            if ($probe->isRunning()) {
                $probe->stop(0);
            }
        }

        return $executable;
    }

    public static function publicAddress(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
            return false;
        }
        $packed = inet_pton($address);

        return $packed !== false && (strlen($packed) === 4 ? ord($packed[0]) < 224 : (ord($packed[0]) & 0xE0) === 0x20);
    }
}
