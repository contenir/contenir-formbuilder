<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\Trait;

use RuntimeException;

use function array_map;
use function fclose;
use function file_get_contents;
use function fsockopen;
use function glob;
use function hrtime;
use function json_decode;
use function parse_url;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function restore_error_handler;
use function set_error_handler;
use function stream_socket_get_name;
use function stream_socket_server;
use function usleep;

use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;
use const PHP_URL_PORT;

/**
 * Runs PHP's built-in web server with a router that records every request it
 * receives into the test's temporary directory. Requires TemporaryDirectoryTrait.
 */
trait WebhookServerTrait
{
    /** @var resource|null */
    private $server;

    private string $serverUrl;

    /**
     * The server runs without php.ini (`-n`): the router needs no extensions,
     * and skipping them (Xdebug above all) keeps start-up fast when many
     * suites run in parallel, as under mutation testing. A server that exits
     * before it accepts connections (another process took the port first) is
     * retried on a fresh port.
     */
    protected function setUpWebhookServer(): void
    {
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            if ($this->startWebhookServer()) {
                return;
            }
        }

        throw new RuntimeException('The webhook server did not start.');
    }

    protected function tearDownWebhookServer(): void
    {
        if (null !== $this->server) {
            proc_terminate($this->server);
            proc_close($this->server);
            $this->server = null;
        }
    }

    /**
     * @return list<array{method: string, uri: string, headers: array<string, string>, body: string}>
     */
    private function receivedRequests(): array
    {
        return array_map(
            static fn(string $file): array => json_decode(
                (string) file_get_contents($file),
                associative: true,
                flags: JSON_THROW_ON_ERROR,
            ),
            $this->requestFiles(),
        );
    }

    /**
     * @return list<string>
     */
    private function requestFiles(): array
    {
        $files = glob("{$this->tmpDir}/request-*.json");

        return false === $files ? [] : $files;
    }

    private function startWebhookServer(): bool
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if (false === $socket) {
            throw new RuntimeException('Could not reserve a port for the webhook server.');
        }

        $address = (string) stream_socket_get_name($socket, remote: false);
        fclose($socket);
        $this->serverUrl = "http://{$address}";

        $process = proc_open(
            [PHP_BINARY, '-n', '-S', $address, __DIR__ . '/../TestAsset/Http/webhook-router.php'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $_pipes,
            env_vars: ['WEBHOOK_LOG_DIR' => $this->tmpDir],
        );
        if (false === $process) {
            throw new RuntimeException('Could not start the webhook server.');
        }

        $this->server = $process;
        $port         = (int) parse_url($this->serverUrl, PHP_URL_PORT);
        $deadline     = hrtime(true) + 10_000_000_000;
        while (hrtime(true) < $deadline && proc_get_status($process)['running']) {
            set_error_handler(static fn(): bool => true);
            try {
                $connection = fsockopen('127.0.0.1', $port);
            } finally {
                restore_error_handler();
            }

            if (false !== $connection) {
                fclose($connection);
                return true;
            }

            usleep(10_000);
        }

        $this->tearDownWebhookServer();

        return false;
    }
}
