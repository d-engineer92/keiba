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

    /** @var array<string, Kd3FetchResult> */
    private array $bundles = [];

    public function __construct(private readonly Kd3ArtifactCatalog $catalog)
    {
        $this->cookies = new CookieJar;
    }

    public function fetch(CarbonImmutable $raceDate, string $artifactType): Kd3FetchResult
    {
        $this->authenticate();
        $this->catalog->get($artifactType);
        $date = $raceDate->toDateString();
        if (isset($this->bundles[$date])) {
            return $this->bundles[$date];
        }
        $url = strtr((string) config('kd3.download_path'), [
            '{md}' => $raceDate->format('md'),
            '{year}' => $raceDate->format('Y'),
        ]);
        $url = $this->trustedUrl($url);

        try {
            $response = $this->request()->get($url);
        } catch (ConnectionException $exception) {
            throw new Kd3Exception('network', null, 'KD3 artifact request failed to connect.', $exception);
        }

        if (in_array($response->status(), [404, 410], true)) {
            return $this->bundles[$date] = Kd3FetchResult::notAvailable($url, $response->status());
        }
        if ($response->successful() && trim($response->body()) === 'データなし') {
            return $this->bundles[$date] = Kd3FetchResult::notAvailable($url, $response->status());
        }
        if (in_array($response->status(), [401, 403], true) || $this->looksLikeLogin($response)) {
            throw new Kd3Exception('authentication', $response->status(), 'KD3 session is not authenticated.');
        }
        if ($response->serverError()) {
            throw new Kd3Exception('server', $response->status(), 'KD3 server returned an error.');
        }
        if ($response->clientError()) {
            throw new Kd3Exception('client', $response->status(), 'KD3 server rejected the artifact request.');
        }

        $contentType = strtolower(trim(explode(';', $response->header('Content-Type'))[0]));
        if (! in_array($contentType, ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'], true)
            || (! str_starts_with($response->body(), "PK\x03\x04") && ! str_starts_with($response->body(), "PK\x05\x06"))) {
            throw new Kd3Exception('invalid_response', $response->status(), 'KD3 response is not a ZIP download.');
        }

        return $this->bundles[$date] = Kd3FetchResult::available(
            $response->body(),
            $this->responseFilename($response),
            $url,
            $response->status(),
        );
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

    private function looksLikeLogin(Response $response): bool
    {
        $contentType = strtolower($response->header('Content-Type'));
        if (! str_contains($contentType, 'text/html')) {
            return false;
        }

        $body = $response->body();

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
