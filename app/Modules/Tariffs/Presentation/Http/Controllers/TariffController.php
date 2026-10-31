<?php

declare(strict_types=1);

namespace App\Modules\Tariffs\Presentation\Http\Controllers;

use App\Modules\Parking\Application\ParkingInfrastructureDirectory;
use App\Modules\Shared\Presentation\Http\Concerns\ResolvesActor;
use App\Modules\Shared\Presentation\Http\Responses\ApiResponse;
use App\Modules\Tariffs\Application\ChangeTariffStatus;
use App\Modules\Tariffs\Application\CreateTariff;
use App\Modules\Tariffs\Application\ListTariffs;
use App\Modules\Tariffs\Application\SimulatePricing;
use App\Modules\Tariffs\Application\TariffLookup;
use App\Modules\Tariffs\Application\UpdateTariff;
use App\Modules\Tariffs\Domain\TariffStatus;
use App\Modules\Tariffs\Infrastructure\Persistence\Tariff;
use App\Modules\Tariffs\Infrastructure\Persistence\TariffVersion;
use App\Modules\Tariffs\Presentation\Http\Requests\TariffRequest;
use App\Modules\Tariffs\Presentation\Http\TariffPayload;
use App\Modules\Tenancy\Infrastructure\TenantContext;
use App\Modules\Vehicles\Domain\VehicleType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class TariffController
{
    use ResolvesActor;

    public function __construct(private readonly ParkingInfrastructureDirectory $parking) {}

    public function index(Request $request, ListTariffs $listTariffs, TenantContext $context): JsonResponse
    {
        $validated = $request->validate(['lot_id' => ['required', 'ulid']]);
        $tenantId = (string) $context->id();

        $tariffs = $listTariffs->handle($this->actorId($request), $tenantId, strtolower((string) $validated['lot_id']));
        $zones = $this->zones($tenantId, $tariffs->pluck('currentVersion')->filter()->all());

        return ApiResponse::success([
            'items' => $tariffs
                ->filter(fn (Tariff $tariff): bool => $tariff->currentVersion !== null)
                ->map(fn (Tariff $tariff): array => TariffPayload::tariff($tariff, $tariff->currentVersion, $zones))
                ->values()
                ->all(),
        ], 200, $request);
    }

    public function store(TariffRequest $request, CreateTariff $createTariff, TenantContext $context): JsonResponse
    {
        $tariff = $createTariff->handle($this->actorId($request), (string) $context->id(), $request->lotId(), $request->toData());

        return $this->respond($request, $tariff, 201, $context);
    }

    public function update(string $tariff, TariffRequest $request, UpdateTariff $updateTariff, TenantContext $context): JsonResponse
    {
        $updated = $updateTariff->handle($this->actorId($request), (string) $context->id(), $tariff, $request->toData());

        return $this->respond($request, $updated, 200, $context);
    }

    public function status(string $tariff, Request $request, ChangeTariffStatus $changeStatus, TenantContext $context): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(TariffStatus::class)],
            'change_note' => ['nullable', 'string', 'max:255'],
        ]);

        $updated = $changeStatus->handle(
            $this->actorId($request),
            (string) $context->id(),
            $tariff,
            TariffStatus::from((string) $validated['status']),
            isset($validated['change_note']) ? trim((string) $validated['change_note']) : null,
        );

        return $this->respond($request, $updated, 200, $context);
    }

    public function versions(Request $request, string $tariff, ListTariffs $listTariffs, TariffLookup $lookup, TenantContext $context): JsonResponse
    {
        $tenantId = (string) $context->id();
        $versions = $listTariffs->versions($this->actorId($request), $tenantId, $lookup->tariff($tenantId, $tariff));
        $zones = $this->zones($tenantId, $versions->all());

        return ApiResponse::success([
            'items' => $versions->map(fn (TariffVersion $version): array => TariffPayload::version($version, $zones))->values()->all(),
        ], 200, $request);
    }

    public function simulate(Request $request, SimulatePricing $simulate, TenantContext $context): JsonResponse
    {
        $validated = $request->validate([
            'lot_id' => ['required', 'ulid'],
            'vehicle_type' => ['required', Rule::enum(VehicleType::class)],
            'zone_id' => ['nullable', 'ulid'],
            'entry_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'exit_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'discount_id' => ['nullable', 'ulid'],
        ]);

        $quote = $simulate->handle(
            $this->actorId($request),
            (string) $context->id(),
            strtolower((string) $validated['lot_id']),
            VehicleType::from((string) $validated['vehicle_type']),
            isset($validated['zone_id']) ? strtolower((string) $validated['zone_id']) : null,
            (string) $validated['entry_at'],
            (string) $validated['exit_at'],
            isset($validated['discount_id']) ? strtolower((string) $validated['discount_id']) : null,
        );

        return ApiResponse::success(['quote' => $quote->toArray()], 200, $request);
    }

    private function respond(Request $request, Tariff $tariff, int $status, TenantContext $context): JsonResponse
    {
        $current = UpdateTariff::current($tariff);

        return ApiResponse::success([
            'tariff' => TariffPayload::tariff($tariff, $current, $this->zones((string) $context->id(), [$current])),
        ], $status, $request);
    }

    /**
     * @param  array<int, TariffVersion>  $versions
     * @return array<string, array{id: string, name: string, code: string}>
     */
    private function zones(string $tenantId, array $versions): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            fn (TariffVersion $version): ?string => $version->zone_id === null ? null : (string) $version->zone_id,
            $versions,
        ))));

        return $this->parking->zoneSummaries($tenantId, $ids);
    }
}
