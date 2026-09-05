<?php

namespace Tests\Feature;

use App\Kd3\HttpKd3Gateway;
use App\Kd3\Kd3Exception;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\SyntheticKd3;
use Tests\TestCase;

class HttpKd3GatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'kd3.base_url' => 'https://www.keibado.net',
            'kd3.username' => 'synthetic-user',
            'kd3.password' => 'synthetic-password',
        ]);
    }

    public function test_login_session_is_reused_and_one_daily_bundle_serves_multiple_artifacts(): void
    {
        $zip = SyntheticKd3::zip('kd3_hb260905.lzh', 'synthetic');
        Http::fake([
            'https://www.keibado.net/kdata/login.php' => Http::response(
                '<html>member option</html>', 200,
                ['Content-Type' => 'text/html; charset=UTF-8', 'Set-Cookie' => 'PHPSESSID=synthetic; Path=/; Secure; HttpOnly'],
            ),
            'https://www.keibado.net/kdata/select_download_core.php*' => Http::response(
                $zip, 200, ['Content-Type' => 'application/zip; name="kd3_20260905.zip"', 'Content-Disposition' => 'attachment; filename="kd3_20260905.zip"'],
            ),
        ]);
        $gateway = $this->app->make(HttpKd3Gateway::class);
        $date = CarbonImmutable::parse('2026-09-05', 'Asia/Tokyo');

        $this->assertTrue($gateway->fetch($date, 'hb')->available);
        $this->assertTrue($gateway->fetch($date, 'ib')->available);

        Http::assertSentCount(2);
        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://www.keibado.net/kdata/login.php') {
                return false;
            }

            return $request->method() === 'POST'
                && $request['fromform'] === '2'
                && $request['user_id'] === 'synthetic-user'
                && $request['user_pass'] === 'synthetic-password';
        });
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'mmdd=0905')
            && str_contains($request->url(), 'yyyy=2026')
            && str_contains($request->url(), 'kdx=kd3')
            && $request->hasHeader('Cookie'));
    }

    public function test_daily_bundle_cache_keeps_only_the_current_date(): void
    {
        $zip = SyntheticKd3::zip('kd3_hb260905.lzh', 'synthetic');
        Http::fake([
            'https://www.keibado.net/kdata/login.php' => Http::response(
                '<html>member option</html>', 200,
                ['Content-Type' => 'text/html; charset=UTF-8', 'Set-Cookie' => 'PHPSESSID=synthetic; Path=/; Secure; HttpOnly'],
            ),
            'https://www.keibado.net/kdata/select_download_core.php*' => Http::response(
                $zip, 200, ['Content-Type' => 'application/zip', 'Content-Disposition' => 'attachment; filename="kd3.zip"'],
            ),
        ]);
        $gateway = $this->app->make(HttpKd3Gateway::class);
        $first = CarbonImmutable::parse('2026-09-05', 'Asia/Tokyo');
        $second = CarbonImmutable::parse('2026-09-06', 'Asia/Tokyo');

        $this->assertTrue($gateway->fetch($first, 'hb')->available);
        $this->assertTrue($gateway->fetch($first, 'ib')->available);
        $this->assertTrue($gateway->fetch($second, 'hb')->available);
        $this->assertTrue($gateway->fetch($first, 'hb')->available);

        // login + first date + second date + first date again after eviction
        Http::assertSentCount(4);
    }

    public function test_login_page_response_is_classified_as_authentication_failure(): void
    {
        Http::fake([
            'https://www.keibado.net/kdata/login.php' => Http::response(
                '<form><input name="fromform"><input name="user_pass"></form>', 200, ['Content-Type' => 'text/html'],
            ),
        ]);

        try {
            $this->app->make(HttpKd3Gateway::class)->fetch(CarbonImmutable::parse('2026-09-05'), 'hb');
            $this->fail('Expected authentication failure.');
        } catch (Kd3Exception $exception) {
            $this->assertSame('authentication', $exception->category);
        }
    }

    public function test_login_server_error_is_not_classified_as_authentication_failure(): void
    {
        Http::fake([
            'https://www.keibado.net/kdata/login.php' => Http::response('', 503),
        ]);

        try {
            $this->app->make(HttpKd3Gateway::class)->fetch(CarbonImmutable::parse('2026-09-05'), 'hb');
            $this->fail('Expected login server failure.');
        } catch (Kd3Exception $exception) {
            $this->assertSame('server', $exception->category);
        }
    }

    public function test_non_zip_success_response_is_rejected(): void
    {
        Http::fake([
            'https://www.keibado.net/kdata/login.php' => Http::response('<html>member</html>', 200, ['Content-Type' => 'text/html']),
            'https://www.keibado.net/kdata/select_download_core.php*' => Http::response('<html>unavailable</html>', 200, ['Content-Type' => 'text/html']),
        ]);

        try {
            $this->app->make(HttpKd3Gateway::class)->fetch(CarbonImmutable::parse('2026-09-05'), 'hb');
            $this->fail('Expected invalid response.');
        } catch (Kd3Exception $exception) {
            $this->assertSame('invalid_response', $exception->category);
        }
    }

    public function test_not_available_is_returned_for_404(): void
    {
        Http::fake([
            'https://www.keibado.net/kdata/login.php' => Http::response('<html>member</html>', 200, ['Content-Type' => 'text/html']),
            'https://www.keibado.net/kdata/select_download_core.php*' => Http::response('', 404),
        ]);
        $result = $this->app->make(HttpKd3Gateway::class)->fetch(CarbonImmutable::parse('2026-09-05'), 'hb');
        $this->assertFalse($result->available);
        $this->assertSame(404, $result->httpStatus);
    }

    public function test_confirmed_no_data_response_is_not_available(): void
    {
        Http::fake([
            'https://www.keibado.net/kdata/login.php' => Http::response('<html>member</html>', 200, ['Content-Type' => 'text/html']),
            'https://www.keibado.net/kdata/select_download_core.php*' => Http::response('データなし', 200, ['Content-Type' => 'text/html; charset=UTF-8']),
        ]);

        $result = $this->app->make(HttpKd3Gateway::class)->fetch(CarbonImmutable::parse('2026-09-06'), 'hb');

        $this->assertFalse($result->available);
        $this->assertSame(200, $result->httpStatus);
    }

    public function test_server_failure_is_not_treated_as_not_available(): void
    {
        Http::fake([
            'https://www.keibado.net/kdata/login.php' => Http::response('<html>member</html>', 200, ['Content-Type' => 'text/html']),
            'https://www.keibado.net/kdata/select_download_core.php*' => Http::response('', 503),
        ]);
        try {
            $this->app->make(HttpKd3Gateway::class)->fetch(CarbonImmutable::parse('2026-09-05'), 'hb');
            $this->fail('Expected server failure.');
        } catch (Kd3Exception $exception) {
            $this->assertSame('server', $exception->category);
        }
    }

    public function test_download_url_on_another_host_is_rejected_before_request(): void
    {
        config(['kd3.download_path' => 'https://example.invalid/download/{year}/{md}']);
        Http::fake([
            'https://www.keibado.net/kdata/login.php' => Http::response('<html>member</html>', 200, ['Content-Type' => 'text/html']),
        ]);

        try {
            $this->app->make(HttpKd3Gateway::class)->fetch(CarbonImmutable::parse('2026-09-05'), 'hb');
            $this->fail('Expected configuration failure.');
        } catch (Kd3Exception $exception) {
            $this->assertSame('configuration', $exception->category);
        }

        Http::assertSentCount(1);
    }
}
