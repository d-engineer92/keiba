<?php

namespace App\Kd3;

use Carbon\CarbonImmutable;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

final class HttpKd3Gateway implements Kd3Gateway
{
    private CookieJar $cookies;

    private bool $authenticated = false;

    private ?string $cachedDate = null;

    private ?Kd3FetchResult $cachedBundle = null;

    public function __construct(private readonly Kd3ArtifactCatalog $catalog)
    {
        $this->cookies = new CookieJar;
    }

    public function __destruct()
    {
        $this->clearCache();
    }

    public function fetch(CarbonImmutable $raceDate, string $artifactType): Kd3FetchResult
    {
        $this->authenticate();
        $this->catalog->get($artifactType);
        $date = $raceDate->toDateString();
        if ($this->cachedDate === $date && $this->cachedBundle !== null) {
            return $this->cachedBundle;
        }

        // One daily ZIP serves all artifact types for a date. Evict the previous temp file
        // before downloading the next date so long backfills retain only one bundle on disk.
        $this->clearCache();

        $url = strtr((string) config('kd3.download_path'), [
            '{md}' => $raceDate->format('md'),
            '{year}' => $raceDate->format('Y'),
        ]);
        $url = $this->trustedUrl($url);
        $temporary = tempnam(sys_get_temp_dir(), 'keiba-kd3-http-');
        if ($temporary === false) {
            throw new Kd3Exception('storage', null, 'Unable to create a temporary KD3 download file.');
        }

        try {
            try {
                // Stream the HTTP response directly to disk. Calling Response::body() for a
                // large daily ZIP forces Guzzle to materialize the whole response in PHP memory.
                $response = $this->request()->withOptions(['sink' => $temporary])->get($url);
            } catch (ConnectionException $exception) {
                throw new Kd3Exception('network', null, 'KD3 artifact request failed to connect.', $exception);
            }

            // Laravel's HTTP fake does not necessarily honor Guzzle's sink option. Keep tests
            // deterministic without changing production behavior: a real non-empty response
            // written via sink never enters this fallback.
            $size = filesize($temporary);
            if ($size === 0) {
                $fallbackBody = $response->body();
                if ($fallbackBody !== '' && file_put_contents($temporary, $fallbackBody) === false) {
                    throw new Kd3Exception('storage', $response->status(), 'Unable to persist the temporary KD3 response.');
                }
            }

            if (in_array($response->status(), [404, 410], true)) {
                @unlink($temporary);

                return $this->cache($date, Kd3FetchResult::notAvailable($url, $response->status()));
            }
            if (in_array($response->status(), [401, 403], true) || $this->looksLikeLogin($response, $temporary)) {
                throw new Kd3Exception('authentication', $response->status(), 'KD3 session is not authenticated.');
            }
            if ($response->serverError()) {
                throw new Kd3Exception('server', $response->status(), 'KD3 server returned an error.');
            }
            if ($response->clientError()) {
                throw new Kd3Exception('client', $response->status(), 'KD3 server rejected the artifact request.');
            }

            $size = filesize($temporary);
            if ($size === false) {
                throw new Kd3Exception('storage', $response->status(), 'Unable to inspect the temporary KD3 download file.');
            }
            if ($response->successful() && $size <= 64) {
                $smallBody = file_get_contents($temporary);
                if ($smallBody !== false && trim($smallBody) === 'データなし') {
                    @unlink($temporary);

                    return $this->cache($date, Kd3FetchResult::notAvailable($url, $response->status()));
                }
            }

            $contentType = strtolower(trim(explode(';', $response->header('Content-Type'))[0]));
            $signature = file_get_contents($temporary, false, null, 0, 4);
            if (! in_array($contentType, ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'], true)
                || ($signature !== "PK\x03\x04" && $signature !== "PK\x05\x06")) {
                throw new Kd3Exception('invalid_response', $response->status(), 'KD3 response is not a ZIP download.');
            }

            // Ownership of the temp file moves to the date cache and is released on date change
            // or when this gateway is destroyed.
            return $this->cache($date, Kd3FetchResult::availableFile(
                $temporary,
                $this->responseFilename($response),
                $url,
                $response->status(),
            ));
        } catch (\Throwable $exception) {
            if ($this->cachedBundle?->filePath !== $temporary) {
                @unlink($temporary);
            }
            throw $exception;
        }
    }

    private function cache(string $date, Kd3FetchResult $result): Kd3FetchResult
    {
        $this->cachedDate = $date;
        $this->cachedBundle = $result;

        return $result;
    }

    private function clearCache(): void
    {
        $path = $this->cachedBundle?->filePath;
        $this->cachedDate = null;
        $this->cachedBundle = null;
        if ($path !== null) {
            @unlink($path);
        }
    }

    private function authenticate(): void
    {
        if ($this->authenticated) {
            return;
        }
        $username = config('kd3.username');
        $password = config('kd3.password');
        if (! is_string($username) || $username === '' || ! is_string($password) || $password === '') {
            throw new Kd3Exception('configuration', null, 'KD3 credentials are not configured.');
        }

        try {
            $member = $this->request()->asForm()->post($this->trustedUrl((string) config('kd3.login_path')), [
                'fromform' => '2', 'user_id' => $username, 'user_pass' => $password, 'btn_submit' => 'ログイン',
            ]);
            if ($member->serverError()) {
                throw new Kd3Exception('server', $member->status(), 'KD3 server returned an error during login.');
            }
            if ($member->clientError() || $this->looksLikeLogin($member)) {
                throw new Kd3Exception('authentication', $member->status(), 'KD3 member login did not establish a session.');
            }
            if (! $member->successful()) {
                throw new Kd3Exception('invalid_response', $member->status(), 'KD3 login returned an unexpected response.');
            }
        } catch (ConnectionException $exception) {
            throw new Kd3Exception('network', null, 'KD3 login failed to connect.', $exception);
        }

        $this->authenticated = true;
    }

    private function request(): PendingRequest
    {
        $base = parse_url((string) config('kd3.base_url'));

        return Http::withOptions([
            'cookies' => $this->cookies,
            'allow_redirects' => [
                'max' => 5, 'strict' => true, 'referer' => true, 'track_redirects' => true,
                'on_redirect' => function (RequestInterface $request, ResponseInterface $response, UriInterface $uri) use ($base): void {
                    if ($uri->getScheme() !== 'https'
                        || strtolower($uri->getHost()) !== strtolower((string) ($base['host'] ?? ''))
                        || $uri->getPort() !== ($base['port'] ?? null)) {
                        throw new Kd3Exception('authentication', $response->getStatusCode(), 'KD3 redirect left the configured HTTPS host.');
                    }
                },
            ],
        ])->timeout((int) config('kd3.timeout'))->accept('*/*')
            ->withUserAgent('keiba-kd3-downloader/1.0');
    }

    private function trustedUrl(string $path): string
    {
        $base = rtrim((string) config('kd3.base_url'), '/');
        $url = str_starts_with($path, 'http://') || str_starts_with($path, 'https://') ? $path : $base.'/'.ltrim($path, '/');
        $baseParts = parse_url($base);
        $parts = parse_url($url);
        if (($baseParts['scheme'] ?? null) !== 'https' || ($parts['scheme'] ?? null) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== strtolower((string) ($baseParts['host'] ?? ''))
            || ($parts['port'] ?? null) !== ($baseParts['port'] ?? null)
            || isset($parts['user']) || isset($parts['pass'])) {
            throw new Kd3Exception('configuration', null, 'KD3 request URL is outside the configured HTTPS host.');
        }

        return $url;
    }

    private function looksLikeLogin(Response $response, ?string $bodyPath = null): bool
    {
        $contentType = strtolower($response->header('Content-Type'));
        if (! str_contains($contentType, 'text/html')) {
            return false;
        }

        if ($bodyPath === null) {
            $body = $response->body();
        } else {
            $body = file_get_contents($bodyPath, false, null, 0, 65536);
            if ($body === false) {
                return false;
            }
        }

        return str_contains($body, 'name="fromform"');
    }

    private function responseFilename(Response $response): string
    {
        $header = $response->header('Content-Disposition');
        if (preg_match('/filename\*?=(?:UTF-8\'\')?["\']?([^"\';]+)/i', $header, $match) === 1) {
            return basename(rawurldecode(trim($match[1])));
        }

        return 'download.zip';
    }
}
