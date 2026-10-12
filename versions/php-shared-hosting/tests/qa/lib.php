<?php

declare(strict_types=1);

/**
 * Minimal test toolkit for the end-to-end QA run: a cookie-keeping HTTP
 * client (one per simulated browser), HTML helpers and a result recorder.
 * No dependencies beyond the curl and pdo_mysql extensions.
 */

final class Browser
{
    private CurlHandle $ch;
    public array $last = [];

    public function __construct(private string $base)
    {
        $this->ch = curl_init();
        curl_setopt_array($this->ch, [
            CURLOPT_COOKIEFILE => '',          // in-memory cookie engine, private to this browser
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_USERAGENT => 'LP-QA/1.0',
        ]);
    }

    /** App-relative path ('/orders') → full URL under the install's base. */
    public function url(string $path): string
    {
        return preg_match('#^https?://#', $path) ? $path : rtrim($this->base, '/') . '/' . ltrim($path, '/');
    }

    /** A Location header value (already includes any subfolder) → full URL on the same origin. */
    public function resolve(string $location): string
    {
        if (preg_match('#^https?://#', $location)) {
            return $location;
        }
        $p = parse_url($this->base);
        return $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') . '/' . ltrim($location, '/');
    }

    /** @return array{status:int, headers:array<string,string[]>, body:string, location:?string, url:string} */
    public function request(string $method, string $path, ?array $fields = null, bool $multipart = false): array
    {
        $url = $this->url($path);
        curl_setopt($this->ch, CURLOPT_URL, $url);
        curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($method === 'POST') {
            curl_setopt($this->ch, CURLOPT_POST, true);
            curl_setopt($this->ch, CURLOPT_POSTFIELDS, $multipart ? $fields : http_build_query($fields ?? []));
        } else {
            curl_setopt($this->ch, CURLOPT_HTTPGET, true);
        }
        $raw = curl_exec($this->ch);
        if ($raw === false) {
            throw new RuntimeException('HTTP error: ' . curl_error($this->ch));
        }
        $headerSize = curl_getinfo($this->ch, CURLINFO_HEADER_SIZE);
        $head = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);
        $headers = [];
        foreach (preg_split('/\r?\n/', $head) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($k))][] = trim($v);
            }
        }
        $this->last = [
            'status' => (int) curl_getinfo($this->ch, CURLINFO_RESPONSE_CODE),
            'headers' => $headers,
            'body' => $body,
            'location' => $headers['location'][0] ?? null,
            'url' => $url,
        ];
        return $this->last;
    }

    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    public function post(string $path, array $fields, bool $multipart = false): array
    {
        return $this->request('POST', $path, $fields, $multipart);
    }

    /** Follow redirects (GET) until a non-redirect response. */
    public function follow(array $r, int $max = 5): array
    {
        while ($max-- > 0 && in_array($r['status'], [301, 302, 303], true) && $r['location']) {
            $r = $this->get($this->resolve($r['location']));
        }
        return $r;
    }

    public function cookies(): array
    {
        return curl_getinfo($this->ch, CURLINFO_COOKIELIST) ?: [];
    }

    public function cookieValue(string $name): ?string
    {
        foreach ($this->cookies() as $line) {
            $parts = explode("\t", $line);
            if (($parts[5] ?? '') === $name) {
                return $parts[6] ?? null;
            }
        }
        return null;
    }
}

/** Value of the first hidden/text input with this name in the HTML. */
function input_value(string $html, string $name): ?string
{
    $q = preg_quote($name, '#');
    if (preg_match('#<input[^>]*name="' . $q . '"[^>]*value="([^"]*)"#', $html, $m)) {
        return html_entity_decode($m[1], ENT_QUOTES);
    }
    if (preg_match('#<input[^>]*value="([^"]*)"[^>]*name="' . $q . '"#', $html, $m)) {
        return html_entity_decode($m[1], ENT_QUOTES);
    }
    return null;
}

function csrf(string $html): string
{
    return input_value($html, '_csrf') ?? '';
}

/** Flash/alert text (all alerts concatenated, tags stripped). */
function alerts(string $html): string
{
    preg_match_all('#<div class="alert[^"]*"[^>]*>(.*?)</div>#s', $html, $m);
    return trim(preg_replace('/\s+/', ' ', strip_tags(implode(' | ', $m[1]))));
}

final class Results
{
    public array $rows = [];
    private string $section = '';

    public function __construct(public string $layout)
    {
    }

    public function section(string $name): void
    {
        $this->section = $name;
        fwrite(STDOUT, "\n== [{$this->layout}] {$name}\n");
    }

    public function check(string $id, string $name, bool $ok, string $detail = ''): bool
    {
        $this->rows[] = ['layout' => $this->layout, 'section' => $this->section, 'id' => $id, 'name' => $name, 'status' => $ok ? 'PASS' : 'FAIL', 'detail' => $detail];
        fwrite(STDOUT, sprintf("  %s %-8s %s%s\n", $ok ? 'PASS' : 'FAIL', $id, $name, $ok || $detail === '' ? '' : "  -- {$detail}"));
        return $ok;
    }

    public function skip(string $id, string $name, string $why, string $status = 'NOT RUN'): void
    {
        $this->rows[] = ['layout' => $this->layout, 'section' => $this->section, 'id' => $id, 'name' => $name, 'status' => $status, 'detail' => $why];
        fwrite(STDOUT, sprintf("  %-4s %-8s %s  -- %s\n", $status === 'BLOCKED' ? 'BLKD' : 'SKIP', $id, $name, $why));
    }

    public function counts(): array
    {
        $c = ['PASS' => 0, 'FAIL' => 0, 'BLOCKED' => 0, 'NOT RUN' => 0];
        foreach ($this->rows as $r) {
            $c[$r['status']]++;
        }
        return $c;
    }
}

/** A small valid PNG (1x1) for proof-of-payment uploads. */
function tiny_png(string $path): string
{
    file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    return $path;
}

function rand_password(): string
{
    return 'Qa' . bin2hex(random_bytes(8)) . '7';
}
