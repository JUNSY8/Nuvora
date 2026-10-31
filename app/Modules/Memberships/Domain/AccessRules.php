<?php

declare(strict_types=1);

namespace App\Modules\Memberships\Domain;

use App\Modules\Tariffs\Domain\TimeWindow;
use App\Modules\Vehicles\Domain\VehicleType;
use DateTimeImmutable;

/**
 * Where, with what and when a plan lets its members park. Every list that
 * is empty means "no restriction". Hours are weekly windows in the lot's
 * local time, the same shape tariffs use; the arrival time decides.
 */
final class AccessRules
{
    /**
     * @param  list<string>  $lotIds
     * @param  list<string>  $zoneIds
     * @param  list<VehicleType>  $vehicleTypes
     * @param  list<TimeWindow>  $windows
     */
    public function __construct(
        public readonly array $lotIds,
        public readonly array $zoneIds,
        public readonly array $vehicleTypes,
        public readonly array $windows,
    ) {}

    /**
     * Decides whether a member's arrival is covered.
     *
     * @param  DateTimeImmutable  $arrivalLocal  the arrival in the lot's timezone
     * @param  DateTimeImmutable  $coveredUntil  last local day the membership covers, grace included
     */
    public function evaluate(
        MembershipStatus $status,
        DateTimeImmutable $periodStart,
        DateTimeImmutable $coveredUntil,
        string $lotId,
        VehicleType $vehicleType,
        DateTimeImmutable $arrivalLocal,
    ): AccessDecision {
        $day = $arrivalLocal->format('Y-m-d');

        return match (true) {
            $status === MembershipStatus::Suspended => AccessDecision::denied('MEMBERSHIP_SUSPENDED', 'La mensualidad está suspendida.'),
            $status !== MembershipStatus::Active => AccessDecision::denied('MEMBERSHIP_INACTIVE', 'La mensualidad no está vigente.'),
            $day < $periodStart->format('Y-m-d') => AccessDecision::denied('MEMBERSHIP_NOT_STARTED', 'La mensualidad empieza el '.$periodStart->format('Y-m-d').'.'),
            $day > $coveredUntil->format('Y-m-d') => AccessDecision::denied('MEMBERSHIP_EXPIRED', 'La mensualidad venció el '.$coveredUntil->format('Y-m-d').'.'),
            $this->lotIds !== [] && ! in_array($lotId, $this->lotIds, true) => AccessDecision::denied('LOT_NOT_INCLUDED', 'La mensualidad no incluye este parqueadero.'),
            $this->vehicleTypes !== [] && ! in_array($vehicleType, $this->vehicleTypes, true) => AccessDecision::denied('VEHICLE_TYPE_NOT_INCLUDED', 'La mensualidad no incluye este tipo de vehículo.'),
            ! $this->withinHours($arrivalLocal) => AccessDecision::denied('OUTSIDE_ACCESS_HOURS', 'La mensualidad no cubre este horario.'),
            default => AccessDecision::covered(),
        };
    }

    /**
     * A covered arrival still needs its period paid once the grace days,
     * counted from the period's first day, are over. The grace days are the
     * margin to collect; after them an unpaid member parks as a visitor.
     */
    public static function requirePayment(AccessDecision $decision, ?string $periodStatus, DateTimeImmutable $periodStart, DateTimeImmutable $periodEnd, int $graceDays, DateTimeImmutable $arrivalLocal): AccessDecision
    {
        if (! $decision->covered || $periodStatus !== 'due') {
            return $decision;
        }

        $deadline = $periodStart->modify("+{$graceDays} days")->format('Y-m-d');

        if ($arrivalLocal->format('Y-m-d') <= $deadline) {
            return $decision;
        }

        return AccessDecision::denied('MEMBERSHIP_UNPAID', "El periodo del {$periodStart->format('Y-m-d')} al {$periodEnd->format('Y-m-d')} no está pagado y venció el plazo de pago ({$deadline}).");
    }

    /**
     * A zone restriction is checked once the space is known.
     */
    public function allowsZone(?string $zoneId): bool
    {
        return $this->zoneIds === [] || ($zoneId !== null && in_array($zoneId, $this->zoneIds, true));
    }

    private function withinHours(DateTimeImmutable $arrivalLocal): bool
    {
        if ($this->windows === []) {
            return true;
        }

        foreach ($this->windows as $window) {
            if ($window->contains($arrivalLocal)) {
                return true;
            }
        }

        return false;
    }
}
