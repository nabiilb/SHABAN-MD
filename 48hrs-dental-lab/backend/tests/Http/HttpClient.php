<?php

namespace Tests\Http;

/** A browser over real HTTP: cookie jar, the XSRF-TOKEN header on writes, and parallel sends. */
final class HttpClient
{
    /** @var array<string, string> */
    public array $cookies = [];

    public bool $sendXsrf = true;

    public function __construct(public readonly string $base) {}

    /** @return array{status: int, json: mixed, headers: array<string, string>, body: string} */
    public function request(string $method, string $path, mixed $body = null, array $headers = []): array
    {
        return self::parallel([[$this, $method, $path, $body, $headers]])[0];
    }

    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    public function post(string $path, mixed $body = []): array
    {
        return $this->request('POST', $path, $body);
    }

    public function login(string $email, string $password = HttpTestCase::PASSWORD): array
    {
        $this->get('/api/auth/csrf');

        return $this->post('/api/auth/login', ['email' => $email, 'password' => $password]);
    }

    /** @return resource|\CurlHandle */
    private function handle(string $method, string $path, mixed $body, array $headers, array &$respHeaders)
    {
        $h = ['Accept: application/json'];
        if ($body !== null) {
            $h[] = 'Content-Type: application/json';
        }
        if ($this->sendXsrf && isset($this->cookies['XSRF-TOKEN']) && $method !== 'GET') {
            $h[] = 'X-XSRF-TOKEN: '.urldecode($this->cookies['XSRF-TOKEN']);
        }
        foreach ($headers as $k => $v) {
            $h[] = "{$k}: {$v}";
        }
        if ($this->cookies) {
            $h[] = 'Cookie: '.implode('; ', array_map(fn ($k, $v) => "{$k}={$v}", array_keys($this->cookies), $this->cookies));
        }
        $ch = curl_init($this->base.$path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $h,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$respHeaders) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $name = strtolower(trim($parts[0]));
                    $value = trim($parts[1]);
                    if ($name === 'set-cookie') {
                        [$kv] = explode(';', $value, 2);
                        [$k, $v] = array_pad(explode('=', $kv, 2), 2, '');
                        if ($v === '' || stripos($value, 'max-age=0') !== false || stripos($value, 'expires=thu, 01 jan 1970') !== false) {
                            unset($this->cookies[$k]);
                        } else {
                            $this->cookies[$k] = $v;
                        }
                    }
                    $respHeaders[$name] = $value;
                }

                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
        }

        return $ch;
    }

    /**
     * Starts a request without waiting for it. Returns a poll function: it returns
     * null while the request is still running, then the response.
     */
    public function start(string $method, string $path, mixed $body = null): \Closure
    {
        $mh = curl_multi_init();
        $headers = [];
        $ch = $this->handle($method, $path, $body, [], $headers);
        curl_multi_add_handle($mh, $ch);
        curl_multi_exec($mh, $active);

        return function (float $waitSeconds = 0.0) use ($mh, $ch, &$headers) {
            $until = microtime(true) + $waitSeconds;
            do {
                curl_multi_exec($mh, $active);
                if (! $active) {
                    $body = (string) curl_multi_getcontent($ch);

                    return ['status' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'json' => json_decode($body, true), 'headers' => $headers, 'body' => $body];
                }
                curl_multi_select($mh, 0.1);
            } while (microtime(true) < $until);

            return null;
        };
    }

    /**
     * Sends requests at the same time. Each item: [client, method, path, body?, headers?].
     *
     * @return list<array{status: int, json: mixed, headers: array<string, string>, body: string}>
     */
    public static function parallel(array $requests): array
    {
        $mh = curl_multi_init();
        $handles = [];
        $headers = [];
        foreach ($requests as $i => $r) {
            [$client, $method, $path] = $r;
            $headers[$i] = [];
            $handles[$i] = $client->handle($method, $path, $r[3] ?? null, $r[4] ?? [], $headers[$i]);
            curl_multi_add_handle($mh, $handles[$i]);
        }
        do {
            $status = curl_multi_exec($mh, $active);
            if ($active) {
                curl_multi_select($mh, 1.0);
            }
        } while ($active && $status === CURLM_OK);
        $out = [];
        foreach ($handles as $i => $ch) {
            $body = (string) curl_multi_getcontent($ch);
            $out[$i] = ['status' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'json' => json_decode($body, true), 'headers' => $headers[$i], 'body' => $body];
            curl_multi_remove_handle($mh, $ch);
        }
        curl_multi_close($mh);

        return $out;
    }
}
