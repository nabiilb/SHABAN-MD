<?php

namespace App\Support;

use App\Domain\Dates;
use App\Exceptions\ApiException;
use Illuminate\Http\Request;

/**
 * Query-string parsing with the Node API's rules: blank = absent, arrays as
 * repeated keys or comma lists, values outside their allowed set are a 422.
 */
final class Query
{
    public const MAX_PER_PAGE = 200;

    /** @var array<string, list<string>> */
    private array $params;

    public function __construct(Request $r)
    {
        $this->params = self::parse((string) $r->server('QUERY_STRING', ''));
    }

    /**
     * URLSearchParams semantics, as the React client sends them: a repeated key
     * (?status=a&status=b) keeps every value. PHP's own parser keeps only the
     * last one and turns key[] into arrays, which the client never sends.
     *
     * @return array<string, list<string>>
     */
    public static function parse(string $qs): array
    {
        $out = [];
        foreach (explode('&', $qs) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            $out[urldecode($k)][] = urldecode($v);
        }

        return $out;
    }

    public function str(string $key): ?string
    {
        $v = $this->params[$key][0] ?? null;
        if ($v === null) {
            return null;
        }
        $t = trim($v);

        return $t === '' ? null : $t;
    }

    /** @return list<string> */
    public function list(string $key): array
    {
        $out = [];
        foreach ($this->params[$key] ?? [] as $v) {
            foreach (explode(',', $v) as $part) {
                if (($part = trim($part)) !== '') {
                    $out[] = $part;
                }
            }
        }

        return $out;
    }

    public function bool(string $key): ?bool
    {
        $v = $this->str($key);

        return $v === null ? null : ($v === 'true' || $v === '1');
    }

    public function enum(string $key, array $allowed): ?string
    {
        $v = $this->str($key);
        if ($v !== null && ! in_array($v, $allowed, true)) {
            throw ApiException::validation([$key => ['Must be one of: '.implode(', ', $allowed).'.']]);
        }

        return $v;
    }

    public function enumList(string $key, array $allowed): array
    {
        $values = $this->list($key);
        $bad = array_values(array_diff($values, $allowed));
        if ($bad) {
            throw ApiException::validation([$key => ['Unknown value(s): '.implode(', ', $bad).'.']]);
        }

        return $values;
    }

    /** YYYY-MM-DD, validated. */
    public function day(string $key): ?string
    {
        $v = $this->str($key);
        if ($v !== null && ! Dates::isDay($v)) {
            throw ApiException::validation([$key => ['Use a date in YYYY-MM-DD format.']]);
        }

        return $v;
    }

    public function dir(string $default = 'desc'): string
    {
        $v = $this->str('dir');
        if ($v === null) {
            return $default;
        }

        return $v === 'asc' ? 'asc' : 'desc';
    }

    /** @return array{page: int, perPage: int} */
    public function page(int $defaultPerPage = 20): array
    {
        $int = function (?string $v, int $fallback) {
            return $v !== null && ctype_digit($v) && (int) $v > 0 ? (int) $v : $fallback;
        };

        return ['page' => $int($this->str('page'), 1), 'perPage' => min($int($this->str('perPage'), $defaultPerPage), self::MAX_PER_PAGE)];
    }

    /** Clamps the page to the last page and returns offset/limit plus the response meta. */
    public static function paginate(array $page, int $total): array
    {
        $lastPage = max(1, (int) ceil($total / $page['perPage']));
        $p = min($page['page'], $lastPage);

        return ['offset' => ($p - 1) * $page['perPage'], 'limit' => $page['perPage'], 'meta' => ['page' => $p, 'perPage' => $page['perPage'], 'total' => $total, 'lastPage' => $lastPage]];
    }

    /** LIKE pattern for "contains", with the user's %, _ and \ escaped. */
    public static function like(string $term): string
    {
        return '%'.addcslashes($term, '%_\\').'%';
    }
}
