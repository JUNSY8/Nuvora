<?php

declare(strict_types=1);

namespace App\Modules\Tariffs\Application;

use App\Modules\Tariffs\Domain\PriceLine;
use App\Modules\Tariffs\Domain\PriceResult;
use App\Modules\Tariffs\Domain\TariffDefinition;

/**
 * Answer of the pricing engine. A stay with no applicable tariff is not an
 * error: it is reported as unpriced so the exit can still be registered,
 * and the operator sees why.
 */
final class Quote
{
    /**
     * @param  array{id: string, version_id: string, version: int, code: string, name: string}|null  $tariff
     * @param  array{id: string, code: string, name: string}|null  $discountRule
     * @param  list<array<string, mixed>>  $lines
     */
    private function __construct(
        public readonly bool $priced,
        public readonly string $currency,
        public readonly ?array $tariff,
        public readonly ?array $discountRule,
        public readonly int $stayMinutes,
        public readonly ?int $chargedMinutes,
        public readonly ?int $graceMinutes,
        public readonly bool $graceApplied,
        public readonly ?int $subtotal,
        public readonly ?int $discount,
        public readonly ?int $total,
        public readonly array $lines,
        public readonly string $entryLocal,
        public readonly string $exitLocal,
        public readonly ?string $zoneId,
        public readonly ?string $reason,
    ) {}

    public static function priced(TariffDefinition $tariff, PriceResult $result, string $currency, string $entryLocal, string $exitLocal, ?string $zoneId): self
    {
        $rule = $result->discountRule;

        return new self(
            priced: true,
            currency: $currency,
            tariff: ['id' => $tariff->tariffId, 'version_id' => $tariff->versionId, 'version' => $tariff->version, 'code' => $tariff->code, 'name' => $tariff->name],
            discountRule: $rule === null ? null : ['id' => $rule->id, 'code' => $rule->code, 'name' => $rule->name],
            stayMinutes: $result->stayMinutes,
            chargedMinutes: $result->chargedMinutes,
            graceMinutes: $result->graceMinutes,
            graceApplied: $result->graceApplied,
            subtotal: $result->subtotal,
            discount: $result->discount,
            total: $result->total,
            lines: array_map(fn (PriceLine $line): array => $line->toArray(), $result->lines),
            entryLocal: $entryLocal,
            exitLocal: $exitLocal,
            zoneId: $zoneId,
            reason: null,
        );
    }

    public static function unpriced(int $stayMinutes, string $currency, string $entryLocal, string $exitLocal, ?string $zoneId): self
    {
        return new self(
            priced: false,
            currency: $currency,
            tariff: null,
            discountRule: null,
            stayMinutes: $stayMinutes,
            chargedMinutes: null,
            graceMinutes: null,
            graceApplied: false,
            subtotal: null,
            discount: null,
            total: null,
            lines: [],
            entryLocal: $entryLocal,
            exitLocal: $exitLocal,
            zoneId: $zoneId,
            reason: 'Ninguna tarifa activa aplica a este vehículo, zona y hora de entrada.',
        );
    }

    /**
     * Shape returned by the API and stored with a closed session.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->priced ? 'priced' : 'unpriced',
            'currency' => $this->currency,
            'tariff' => $this->tariff,
            'discount_rule' => $this->discountRule,
            'stay_minutes' => $this->stayMinutes,
            'charged_minutes' => $this->chargedMinutes,
            'grace_minutes' => $this->graceMinutes,
            'grace_applied' => $this->graceApplied,
            'subtotal' => $this->subtotal,
            'discount' => $this->discount,
            'total' => $this->total,
            'lines' => $this->lines,
            'entry_local' => $this->entryLocal,
            'exit_local' => $this->exitLocal,
            'zone_id' => $this->zoneId,
            'reason' => $this->reason,
        ];
    }
}
