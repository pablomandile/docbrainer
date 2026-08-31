<?php

namespace App\Services\AI;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Guardián de cuota de la API de Gemini, del LADO CLIENTE.
 *
 * Dos niveles:
 *  - RPM: gate por minuto vía RateLimiter (cache).
 *  - Diario: contador persistido en `api_usage` (sobrevive reinicios).
 *
 * El día de cuota resetea a medianoche de America/Los_Angeles,
 * que es el reloj de cuota de Google.
 */
class ApiQuota
{
    public const METRIC_EMBED = 'embed_requests';

    public const METRIC_GENERATE = 'generate_requests';

    public const METRIC_EMBED_ITEMS = 'embed_items';

    private const QUOTA_TIMEZONE = 'America/Los_Angeles';

    /**
     * Autoriza UNA request del métrico dado y registra el consumo.
     *
     * @throws QuotaExceededException si se alcanzó el tope diario propio
     * @throws LocallyThrottledException si el gate de RPM está saturado
     */
    public function guard(string $metric): void
    {
        $limits = $this->limitsFor($metric);

        if ($this->usedToday($metric) >= $limits['daily']) {
            throw new QuotaExceededException(
                "Tope diario propio alcanzado para {$metric} ({$limits['daily']}). "
                .'Se reintentará después del reset de cuota (medianoche hora del Pacífico).'
            );
        }

        $allowed = RateLimiter::attempt(
            key: 'gemini:'.$metric,
            maxAttempts: $limits['rpm'],
            callback: fn () => true,
            decaySeconds: 60,
        );

        if (! $allowed) {
            throw new LocallyThrottledException(
                "Gate de RPM saturado para {$metric} ({$limits['rpm']}/min)."
            );
        }

        $this->increment($metric);
    }

    /**
     * Registra items adicionales (ej. chunks embebidos) sin gate propio.
     */
    public function recordItems(string $metric, int $count): void
    {
        $this->increment($metric, $count);
    }

    public function usedToday(string $metric): int
    {
        return (int) DB::table('api_usage')
            ->where('date', $this->quotaDate())
            ->where('metric', $metric)
            ->value('count');
    }

    public function remainingToday(string $metric): int
    {
        return max(0, $this->limitsFor($metric)['daily'] - $this->usedToday($metric));
    }

    /**
     * Segundos hasta el próximo reset de cuota (medianoche del Pacífico).
     */
    public function secondsUntilReset(): int
    {
        $now = Carbon::now(self::QUOTA_TIMEZONE);

        return (int) $now->diffInSeconds($now->copy()->addDay()->startOfDay());
    }

    private function increment(string $metric, int $count = 1): void
    {
        DB::table('api_usage')->upsert(
            [['date' => $this->quotaDate(), 'metric' => $metric, 'count' => $count]],
            ['date', 'metric'],
            ['count' => DB::raw("count + {$count}")],
        );
    }

    private function quotaDate(): string
    {
        return Carbon::now(self::QUOTA_TIMEZONE)->toDateString();
    }

    /**
     * @return array{rpm: int, daily: int}
     */
    private function limitsFor(string $metric): array
    {
        return match ($metric) {
            self::METRIC_EMBED => [
                'rpm' => (int) config('docbrainer.gemini.embed_rpm'),
                'daily' => (int) config('docbrainer.gemini.embed_daily_limit'),
            ],
            self::METRIC_GENERATE => [
                'rpm' => (int) config('docbrainer.gemini.generate_rpm'),
                'daily' => (int) config('docbrainer.gemini.generate_daily_limit'),
            ],
            default => throw new \InvalidArgumentException("Métrica desconocida: {$metric}"),
        };
    }
}
