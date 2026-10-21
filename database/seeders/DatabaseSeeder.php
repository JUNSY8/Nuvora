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
use App\Modules\Parking\Domain\SpaceStatus;
use App\Modules\Parking\Domain\SpaceType;
use App\Modules\Parking\Infrastructure\Persistence\ParkingLot;
use App\Modules\Parking\Infrastructure\Persistence\ParkingSpace;
use App\Modules\Tenancy\Application\CreateTenant;
use App\Modules\Tenancy\Infrastructure\Persistence\TenantMembership;
use App\Modules\Vehicles\Application\ChangeVehicleStatus;
use App\Modules\Vehicles\Application\LinkCustomerToVehicle;
use App\Modules\Vehicles\Application\RegisterVehicle;
use App\Modules\Vehicles\Application\VehicleData;
use App\Modules\Vehicles\Domain\VehicleRelationship;
use App\Modules\Vehicles\Domain\VehicleStatus;
use App\Modules\Vehicles\Domain\VehicleType;
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

        // Sessions arrive in 0.5; a few occupied spaces make the demo map realistic.
        ParkingSpace::query()->where('parking_lot_id', $lotId)->whereIn('code', ['A-03', 'A-04', 'A-07', 'A-12', 'A-15', 'M-02', 'M-05', 'E-01'])
            ->update(['status' => SpaceStatus::Occupied->value]);
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
