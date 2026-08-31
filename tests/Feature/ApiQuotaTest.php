<?php

namespace Tests\Feature;

use App\Services\AI\ApiQuota;
use App\Services\AI\LocallyThrottledException;
use App\Services\AI\QuotaExceededException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ApiQuotaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('gemini:'.ApiQuota::METRIC_EMBED);
    }

    public function test_guard_increments_daily_counter(): void
    {
        $quota = new ApiQuota;

        $quota->guard(ApiQuota::METRIC_EMBED);
        $quota->guard(ApiQuota::METRIC_EMBED);

        $this->assertSame(2, $quota->usedToday(ApiQuota::METRIC_EMBED));
        $this->assertSame(1, DB::table('api_usage')->count());
    }

    public function test_guard_throws_when_daily_limit_reached(): void
    {
        config(['docbrainer.gemini.embed_daily_limit' => 2]);

        $quota = new ApiQuota;
        $quota->guard(ApiQuota::METRIC_EMBED);
        $quota->guard(ApiQuota::METRIC_EMBED);

        $this->expectException(QuotaExceededException::class);
        $quota->guard(ApiQuota::METRIC_EMBED);
    }

    public function test_guard_throws_when_rpm_gate_saturated(): void
    {
        config(['docbrainer.gemini.embed_rpm' => 1]);

        $quota = new ApiQuota;
        $quota->guard(ApiQuota::METRIC_EMBED);

        $this->expectException(LocallyThrottledException::class);
        $quota->guard(ApiQuota::METRIC_EMBED);
    }

    public function test_remaining_today_reflects_usage(): void
    {
        config(['docbrainer.gemini.embed_daily_limit' => 10]);

        $quota = new ApiQuota;
        $quota->guard(ApiQuota::METRIC_EMBED);

        $this->assertSame(9, $quota->remainingToday(ApiQuota::METRIC_EMBED));
    }

    public function test_seconds_until_reset_is_positive_and_bounded(): void
    {
        $seconds = (new ApiQuota)->secondsUntilReset();

        $this->assertGreaterThan(0, $seconds);
        $this->assertLessThanOrEqual(86400, $seconds);
    }
}
