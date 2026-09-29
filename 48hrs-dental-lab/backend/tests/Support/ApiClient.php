<?php

namespace Tests\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * One browser: keeps the cookies the API sets (session, XSRF-TOKEN) and sends
 * them back, so several clients in one test hold independent sessions.
 * (Laravel skips the CSRF check inside PHPUnit; tests/Http covers it on a real server.)
 */
final class ApiClient
{
    /** @var array<string, string> */
    public array $cookies = [];

    public function __construct(private readonly TestCase $t) {}

    public function get(string $path, array $query = []): TestResponse
    {
        return $this->request('GET', $path.($query ? '?'.http_build_query($query) : ''));
    }

    public function post(string $path, mixed $body = null): TestResponse
    {
        return $this->request('POST', $path, $body);
    }

    public function put(string $path, mixed $body = null): TestResponse
    {
        return $this->request('PUT', $path, $body);
    }

    public function patch(string $path, mixed $body = null): TestResponse
    {
        return $this->request('PATCH', $path, $body);
    }

    public function delete(string $path): TestResponse
    {
        return $this->request('DELETE', $path);
    }

    /** multipart/form-data upload */
    public function upload(string $path, ?UploadedFile $file, array $fields = []): TestResponse
    {
        return $this->request('POST', $path, null, $fields, $file ? ['file' => $file] : []);
    }

    public function request(string $method, string $path, mixed $body = null, array $fields = [], array $files = [], array $headers = []): TestResponse
    {
        // Each request resolves its user from the session cookie, like a fresh PHP process.
        app('auth')->forgetGuards();
        app()->forgetInstance('auth.driver');
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        $headers = ['Accept' => 'application/json', ...$headers];
        $content = null;
        if ($body !== null && ! $files) {
            $headers['Content-Type'] = 'application/json';
            $content = is_string($body) ? $body : json_encode($body);
        }
        $res = $this->t->send($method, '/api'.$path, $fields, $this->cookies, $files, $headers, $content);
        foreach ($res->headers->getCookies() as $cookie) {
            // Expiry against the (fixed) test clock, not the machine's.
            $expired = $cookie->getExpiresTime() !== 0 && $cookie->getExpiresTime() < now()->getTimestamp();
            if ($expired || $cookie->getValue() === null || $cookie->getValue() === '') {
                unset($this->cookies[$cookie->getName()]);
            } else {
                $this->cookies[$cookie->getName()] = $cookie->getValue();
            }
        }

        return $res;
    }
}
