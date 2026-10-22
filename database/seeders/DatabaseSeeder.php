<?php

namespace Database\Seeders;

use App\Modules\Customers\Application\CreateCustomer;
use App\Modules\Customers\Application\CustomerData;
use App\Modules\Customers\Domain\CustomerType;
use App\Modules\Customers\Infrastructure\Persistence\Customer;
use App\Modules\Devices\Application\DeviceData;
use App\Modules\Devices\Application\RegisterDevice;
use App\Modules\Devices\Domain\DeviceType;
use App\Modules\Identity\Infrastructure\Persistence\User;
use App\Modules\Parking\Application\CreateParkingLot;
use App\Modules\Parking\Application\CreateParkingSpaceRange;
use App\Modules\Parking\Application\CreateParkingZone;
use App\Modules\Parking\Application\ParkingLotData;
use App\Modules\Parking\Application\ParkingSpaceData;
use App\Modules\Parking\Application\SaveParkingGate;
use App\Modules\Parking\Domain\GateDirection;
use App\Modules\Parking\Domain\GateStatus;
use App\Modules\Parking\Domain\ParkingLotStatus;
use App\Modules\Parking\Domain\ParkingRuleViolation;
use App\Modules\Parking\Domain\SpaceStatus;
use App\Modules\Parking\Domain\SpaceType;
use App\Modules\Parking\Infrastructure\Persistence\ParkingGate;
use App\Modules\Parking\Infrastructure\Persistence\ParkingLot;
use App\Modules\Parking\Infrastructure\Persistence\ParkingSpace;
use App\Modules\Parking\Infrastructure\Persistence\ParkingZone;
use App\Modules\ParkingSessions\Application\EntryData;
use App\Modules\ParkingSessions\Application\RegisterEntry;
use App\Modules\ParkingSessions\Application\RegisterExit;
use App\Modules\ParkingSessions\Domain\SessionRuleViolation;
use App\Modules\ParkingSessions\Infrastructure\Persistence\ParkingSession;
use App\Modules\Reservations\Application\CreateReservation;
use App\Modules\Reservations\Application\ReservationData;
use App\Modules\Reservations\Infrastructure\Persistence\Reservation;
use App\Modules\Tariffs\Application\CreateTariff;
use App\Modules\Tariffs\Application\DiscountData;
use App\Modules\Tariffs\Application\SaveDiscount;
use App\Modules\Tariffs\Application\TariffData;
use App\Modules\Tariffs\Domain\DiscountApplication;
use App\Modules\Tariffs\Domain\DiscountType;
use App\Modules\Tariffs\Infrastructure\Persistence\Tariff;
use App\Modules\Tenancy\Application\CreateTenant;
use App\Modules\Tenancy\Infrastructure\Persistence\TenantMembership;
use App\Modules\Vehicles\Application\ChangeVehicleStatus;
use App\Modules\Vehicles\Application\LinkCustomerToVehicle;
use App\Modules\Vehicles\Application\RegisterVehicle;
use App\Modules\Vehicles\Application\VehicleData;
use App\Modules\Vehicles\Domain\VehicleRelationship;
use App\Modules\Vehicles\Domain\VehicleStatus;
use App\Modules\Vehicles\Domain\VehicleType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed local development data.
     *
     * The password comes from the user factory and is only created when APP_ENV=local.
     */
    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        $user = User::query()->where('email', 'admin@nuvora.test')->first();

        if ($user === null) {
            $user = User::factory()->create([
                'name' => 'Platform Admin',
                'email' => 'admin@nuvora.test',
            ]);
        }

        if (! TenantMembership::query()->where('user_id', $user->id)->exists()) {
            app(CreateTenant::class)->handle($user, 'Nuvora Demo', 'America/Bogota');
        }

        $membership = TenantMembership::query()->where('user_id', $user->id)->firstOrFail();
        $this->seedParking((string) $user->id, (string) $membership->tenant_id);
        $this->seedCustomers((string) $user->id, (string) $membership->tenant_id);
        $this->seedTariffs((string) $user->id, (string) $membership->tenant_id);
        $this->seedSessions((string) $user->id, (string) $membership->tenant_id);
        $this->seedReservations((string) $user->id, (string) $membership->tenant_id);
    }

    /**
     * Upcoming reservations relative to now, in the lot's local time: a
     * space for this afternoon, a zone for tomorrow and an unconfirmed hold.
     */
    private function seedReservations(string $actorId, string $tenantId): void
    {
        $lot = ParkingLot::query()->where('tenant_id', $tenantId)->where('code', 'CENTRO')->first();

        if ($lot === null || Reservation::query()->where('tenant_id', $tenantId)->exists()) {
            return;
        }

        $lotId = (string) $lot->id;
        $start = CarbonImmutable::now($lot->timezone)->addHours(2)->startOfHour();
        $space = (string) ParkingSpace::query()->where('parking_lot_id', $lotId)->where('code', 'A-30')->value('id');
        $zone = (string) ParkingZone::query()->where('parking_lot_id', $lotId)->where('code', 'GEN')->value('id');

        $book = fn (string $plate, ?VehicleType $type, ?string $zoneId, ?string $spaceId, CarbonImmutable $from, int $hours, bool $confirm) => app(CreateReservation::class)
            ->handle($actorId, $tenantId, new ReservationData(
                lotId: $lotId,
                plate: $plate,
                country: 'CO',
                vehicleType: $type,
                customerId: null,
                zoneId: $zoneId,
                spaceId: $spaceId,
                startsAt: $from->utc(),
                endsAt: $from->addHours($hours)->utc(),
                confirm: $confirm,
                notes: null,
                idempotencyKey: null,
            ));

        $book('SAB910', null, null, $space === '' ? null : $space, $start, 3, true);
        $book('RES204', VehicleType::Car, $zone === '' ? null : $zone, null, $start->addDay()->setTime(9, 0), 4, true);
        $book('RES318', VehicleType::Car, null, null, $start->addHours(1), 2, false);
    }

    /**
     * Tariffs of the demo lot, in COP minor units (100 = $1). The night
     * tariff only covers cars and vans, so motorcycles keep their own price.
     */
    private function seedTariffs(string $actorId, string $tenantId): void
    {
        $lot = ParkingLot::query()->where('tenant_id', $tenantId)->where('code', 'CENTRO')->first();

        if ($lot === null || Tariff::query()->where('tenant_id', $tenantId)->exists()) {
            return;
        }

        $tariff = fn (string $code, string $name, array $vehicles, array $windows, array $brackets, int $stepMinutes, int $stepPesos, ?int $dailyMaxPesos) => app(CreateTariff::class)
            ->handle($actorId, $tenantId, (string) $lot->id, new TariffData(
                code: $code,
                name: $name,
                priority: 0,
                vehicleTypes: $vehicles,
                zoneId: null,
                windows: $windows,
                graceMinutes: null,
                brackets: array_map(fn (array $bracket): array => ['up_to_minutes' => $bracket[0], 'amount' => $bracket[1] * 100], $brackets),
                stepMinutes: $stepMinutes,
                stepAmount: $stepPesos * 100,
                dailyMaxAmount: $dailyMaxPesos === null ? null : $dailyMaxPesos * 100,
                changeNote: 'Tarifa inicial de demostración.',
            ));

        $tariff('GENERAL', 'Carros por fracción', [], [], [[30, 3000], [60, 5000]], 15, 1200, 38000);
        $tariff('MOTOS', 'Motos y bicicletas', [VehicleType::Motorcycle, VehicleType::Bicycle], [], [], 30, 800, 12000);
        $tariff('PESADOS', 'Camiones y buses', [VehicleType::Truck, VehicleType::Bus], [], [], 60, 7000, 60000);
        $tariff('NOCHE', 'Noche plana', [VehicleType::Car, VehicleType::Van], [['days' => [1, 2, 3, 4, 5, 6, 7], 'start' => '19:00', 'end' => '06:00']], [[720, 12000]], 60, 4000, 38000);

        $discount = fn (string $code, string $name, DiscountType $type, int $value, DiscountApplication $application, array $vehicles = [], array $days = []) => app(SaveDiscount::class)
            ->handle($actorId, $tenantId, null, new DiscountData(
                lotId: null,
                code: $code,
                name: $name,
                type: $type,
                value: $value,
                currency: null,
                application: $application,
                vehicleTypes: $vehicles,
                days: $days,
                validFrom: null,
                validUntil: null,
            ));

        $discount('CINE', 'Convenio cine (2 h gratis)', DiscountType::FreeMinutes, 120, DiscountApplication::Manual);
        $discount('CORTESIA', 'Cortesía de gerencia', DiscountType::Percentage, 10000, DiscountApplication::Manual);
        $discount('LUNES-MOTOS', 'Lunes de motos', DiscountType::Percentage, 2000, DiscountApplication::Automatic, [VehicleType::Motorcycle], [1]);
    }

    /**
     * Occupied spaces come from real sessions, so they can be released with
     * an exit. Entry times are moved back to make the demo durations varied.
     */
    private function seedSessions(string $actorId, string $tenantId): void
    {
        $lot = ParkingLot::query()->where('tenant_id', $tenantId)->where('code', 'CENTRO')->first();

        if ($lot === null || ParkingSession::query()->where('tenant_id', $tenantId)->exists()) {
            return;
        }

        $lotId = (string) $lot->id;
        $spaces = ParkingSpace::query()->where('parking_lot_id', $lotId)->pluck('id', 'code');
        $entryGate = (string) ParkingGate::query()->where('parking_lot_id', $lotId)->where('code', 'IN-01')->value('id');
        $exitGate = (string) ParkingGate::query()->where('parking_lot_id', $lotId)->where('code', 'OUT-01')->value('id');

        // A space the developer took out of service is skipped instead of aborting the seed.
        $enter = function (string $plate, ?VehicleType $type, ?string $spaceCode, int $minutesAgo) use ($actorId, $tenantId, $lotId, $spaces, $entryGate): ?string {
            if ($spaceCode !== null && ! isset($spaces[$spaceCode])) {
                return null;
            }

            try {
                $session = app(RegisterEntry::class)->handle($actorId, $tenantId, new EntryData(
                    lotId: $lotId,
                    plate: $plate,
                    country: 'CO',
                    vehicleType: $type,
                    spaceId: $spaceCode === null ? null : (string) $spaces[$spaceCode],
                    autoAssign: false,
                    gateId: $entryGate,
                    notes: null,
                    idempotencyKey: null,
                ))->session;
            } catch (ParkingRuleViolation|SessionRuleViolation) {
                return null;
            }

            $session->forceFill(['entry_at' => now()->subMinutes($minutesAgo)])->save();

            return (string) $session->id;
        };

        $enter('KXY482', null, 'A-03', 185);
        $enter('TAN301', null, 'A-04', 47);
        $enter('JKL321', VehicleType::Car, 'A-07', 12);
        $enter('GHT555', null, 'A-12', 320);
        $enter('XYZ789', VehicleType::Car, 'A-15', 1560);
        $enter('LMN27F', null, 'M-02', 95);
        $enter('BCD12E', VehicleType::Motorcycle, 'M-05', 33);
        $enter('SAB910', null, 'E-01', 64);
        $enter('VIS404', VehicleType::Car, null, 8);

        $visit = $enter('RTA118', VehicleType::Car, 'A-01', 140);

        if ($visit !== null) {
            app(RegisterExit::class)->handle($actorId, $tenantId, $visit, $exitGate, null, null);
        }
    }

    private function seedCustomers(string $actorId, string $tenantId): void
    {
        if (Customer::query()->where('tenant_id', $tenantId)->exists()) {
            return;
        }

        $customer = fn (CustomerType $type, string $name, ?string $documentType, ?string $document, ?string $email, ?string $phone, ?string $businessId = null): string => (string) app(CreateCustomer::class)
            ->handle($actorId, $tenantId, new CustomerData($type, $name, $documentType, $document, $email, $phone, $businessId, null))
            ->id;

        $andinos = $customer(CustomerType::Business, 'Transportes Andinos S.A.S.', 'NIT', '900.123.456-7', 'flota@andinos.example', '601 555 0101');
        $grupo = $customer(CustomerType::Corporate, 'Grupo Empresarial Sabana', 'NIT', '860.555.120-1', 'facilities@sabana.example', '601 555 0199');
        $laura = $customer(CustomerType::Individual, 'Laura Gómez', 'CC', '1.020.304.050', 'laura.gomez@example.com', '300 555 0123');
        $carlos = $customer(CustomerType::Individual, 'Carlos Ruiz', 'CC', '79.456.123', 'carlos.ruiz@example.com', '310 555 0456', $andinos);
        $ana = $customer(CustomerType::Individual, 'Ana María Torres', 'CC', '52.789.001', null, '315 555 0789', $grupo);
        $customer(CustomerType::Individual, 'Jorge Peña', 'CE', '456789', 'jorge.pena@example.com', null);

        $vehicle = fn (string $plate, VehicleType $type, string $brand, string $model, string $color, int $year, bool $electric = false, ?string $customerId = null): string => (string) app(RegisterVehicle::class)
            ->handle($actorId, $tenantId, new VehicleData($plate, 'CO', $type, $brand, $model, $color, $year, $electric, null), $customerId)
            ->id;

        $vehicle('KXY482', VehicleType::Car, 'Mazda', 'CX-30', 'Rojo', 2022, customerId: $laura);
        $vehicle('LMN27F', VehicleType::Motorcycle, 'Yamaha', 'NMAX', 'Negro', 2023, customerId: $laura);
        $van = $vehicle('TAN301', VehicleType::Van, 'Renault', 'Kangoo', 'Blanco', 2021, customerId: $andinos);
        $vehicle('TAN302', VehicleType::Truck, 'Chevrolet', 'NHR', 'Blanco', 2020, customerId: $andinos);
        $vehicle('SAB910', VehicleType::Car, 'BYD', 'Dolphin', 'Gris', 2024, electric: true, customerId: $grupo);
        $vehicle('GHT555', VehicleType::Car, 'Renault', 'Logan', 'Plata', 2015, customerId: $ana);
        $blocked = $vehicle('QWE908', VehicleType::Car, 'Kia', 'Picanto', 'Azul', 2018);

        app(LinkCustomerToVehicle::class)->handle($actorId, $tenantId, $van, $carlos, VehicleRelationship::Driver);
        app(ChangeVehicleStatus::class)->handle($actorId, $tenantId, $blocked, VehicleStatus::Blocked, 'Reporte de daños en la barrera sin resolver.');
    }

    private function seedParking(string $actorId, string $tenantId): void
    {
        if (ParkingLot::query()->where('tenant_id', $tenantId)->exists()) {
            return;
        }

        $lot = app(CreateParkingLot::class)->handle($actorId, $tenantId, new ParkingLotData(
            name: 'Sede Centro',
            code: 'CENTRO',
            description: 'Parqueadero demo con zonas para carros, motos y visitantes.',
            address: 'Carrera 7 # 32-16',
            city: 'Bogotá',
            latitude: '4.6195000',
            longitude: '-74.0690000',
            timezone: 'America/Bogota',
            currency: 'COP',
            gracePeriodMinutes: 15,
            status: ParkingLotStatus::Active,
        ));
        $lotId = (string) $lot->id;

        $zones = [
            ['General', 'GEN', 'A-', 1, 40, SpaceType::Car],
            ['Motos', 'MOT', 'M-', 1, 24, SpaceType::Motorcycle],
            ['Movilidad reducida', 'PMR', 'D-', 1, 4, SpaceType::Disabled],
            ['Carga eléctrica', 'EV', 'E-', 1, 6, SpaceType::Ev],
        ];

        foreach ($zones as $index => [$name, $code, $prefix, $from, $to, $type]) {
            $zone = app(CreateParkingZone::class)->handle($actorId, $tenantId, $lotId, $name, $code, null, true, $index);

            app(CreateParkingSpaceRange::class)->handle(
                $actorId,
                $tenantId,
                $lotId,
                $prefix,
                $from,
                $to,
                2,
                new ParkingSpaceData((string) $zone->id, $type, 'Piso 1', $type === SpaceType::Car, false),
            );
        }

        ParkingSpace::query()->where('parking_lot_id', $lotId)->whereIn('code', ['A-20'])
            ->update(['status' => SpaceStatus::Maintenance->value]);

        $entry = app(SaveParkingGate::class)->handle($actorId, $tenantId, $lotId, null, 'Entrada principal', 'IN-01', GateDirection::Entry, GateStatus::Active);
        $exit = app(SaveParkingGate::class)->handle($actorId, $tenantId, $lotId, null, 'Salida principal', 'OUT-01', GateDirection::Exit, GateStatus::Active);

        $devices = [
            [(string) $entry->id, 'Cámara LPR entrada', 'CAM-IN-01', DeviceType::Camera, '10.0.0.21'],
            [(string) $entry->id, 'Barrera entrada', 'BAR-IN-01', DeviceType::Barrier, '10.0.0.31'],
            [(string) $exit->id, 'Cámara LPR salida', 'CAM-OUT-01', DeviceType::Camera, '10.0.0.22'],
            [(string) $exit->id, 'Barrera salida', 'BAR-OUT-01', DeviceType::Barrier, '10.0.0.32'],
            [null, 'Kiosco de pago', 'KIOSK-01', DeviceType::Kiosk, '10.0.0.41'],
        ];

        foreach ($devices as [$gateId, $name, $code, $type, $ip]) {
            app(RegisterDevice::class)->handle($actorId, $tenantId, new DeviceData(
                parkingLotId: $lotId,
                gateId: $gateId,
                name: $name,
                code: $code,
                type: $type,
                vendor: null,
                model: null,
                serialNumber: null,
                ipAddress: $ip,
                firmwareVersion: null,
            ));
        }
    }
}
