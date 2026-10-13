<?php

declare(strict_types=1);

namespace Forja\Tests\Support;

/**
 * PHP's built-in web server as a child process, on a free local port.
 */
final class Server
{
    /** @var resource */
    private $process;
    public readonly string $url;

    /**
     * @param list<string> $ini  "name=value" settings
     * @param array<string, string> $env
     */
    public function __construct(string $script, array $ini = [], array $env = [])
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $this->url = 'http://' . $address;

        $command = [PHP_BINARY];
        foreach ($ini as $setting) {
            array_push($command, '-d', $setting);
        }
        array_push($command, '-S', $address, $script);
        $this->process = proc_open($command, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env + getenv());

        for ($i = 0; $i < 200; $i++) {
            if ($connection = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1)) {
                fclose($connection);

                return;
            }
            usleep(25000);
        }
        throw new \RuntimeException('built-in server did not start');
    }

    public function post(string $body): string
    {
        $context = stream_context_create(['http' => ['method' => 'POST', 'content' => $body, 'header' => 'content-type: application/json', 'timeout' => 20]]);

        return (string) file_get_contents($this->url . '/', false, $context);
    }

    public function __destruct()
    {
        proc_terminate($this->process);
        proc_close($this->process);
    }
}
