<?php

declare(strict_types=1);

namespace App\Modules\ParkingSessions\Application;

use App\Modules\ParkingSessions\Domain\SessionRuleViolation;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Holds a quoted price for a few minutes. The cashier tells the driver a
 * price, takes the money and confirms the exit; without the hold, a minute
 * later the stay could reach one more fraction. The token is encrypted with
 * the application key, so the client can neither read nor forge the
 * instant it prices at. An expired hold is ignored and the exit prices at
 * its own moment.
 */
final class QuoteToken
{
    /**
     * @return array{token: string, expires_at: string}
     */
    public static function issue(string $tenantId, string $sessionId, ?string $discountId, DateTimeImmutable $pricedAt): array
    {
        $expiresAt = CarbonImmutable::instance($pricedAt)->addMinutes(self::ttlMinutes());

        return [
            'token' => Crypt::encryptString((string) json_encode([
                't' => $tenantId,
                's' => $sessionId,
                'd' => $discountId,
                'p' => $pricedAt->format('U.u'),
                'e' => $expiresAt->getTimestamp(),
            ])),
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    /**
     * The instant to price at, or null when the hold expired.
     */
    public static function pricedAt(string $token, string $tenantId, string $sessionId, ?string $discountId, DateTimeImmutable $now): ?DateTimeImmutable
    {
        try {
            $data = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw self::invalid();
        }

        if (! is_array($data) || ($data['t'] ?? null) !== $tenantId || ($data['s'] ?? null) !== $sessionId || ($data['d'] ?? null) !== $discountId) {
            throw self::invalid();
        }

        if ((int) ($data['e'] ?? 0) < $now->getTimestamp()) {
            return null;
        }

        $pricedAt = DateTimeImmutable::createFromFormat('U.u', (string) ($data['p'] ?? ''));

        return $pricedAt === false || $pricedAt > $now ? null : CarbonImmutable::instance($pricedAt);
    }

    public static function ttlMinutes(): int
    {
        return max(1, (int) config('nuvora.payments.quote_hold_minutes', 5));
    }

    private static function invalid(): SessionRuleViolation
    {
        return new SessionRuleViolation('La cotización no corresponde a esta salida. Vuelve a calcular el valor.', 'INVALID_QUOTE');
    }
}
