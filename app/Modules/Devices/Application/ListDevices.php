<?php

declare(strict_types=1);

namespace App\Modules\Devices\Application;

use App\Modules\Devices\Domain\DeviceStatus;
use App\Modules\Devices\Domain\DeviceType;
use App\Modules\Devices\Infrastructure\Persistence\Device;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListDevices
{
    public function __construct(private readonly TenantAuthorizer $authorizer) {}

    /**
     * @return array{devices: LengthAwarePaginator<int, Device>, status_counts: array<string, int>}
     */
    public function handle(
        string $actorUserId,
        string $tenantId,
        int $page,
        int $perPage,
        ?string $parkingLotId = null,
        ?DeviceStatus $status = null,
        ?DeviceType $type = null,
        ?string $search = null,
    ): array {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::DEVICES_VIEW);

        $query = Device::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('code')
            ->orderBy('id');

        if ($parkingLotId !== null) {
            $query->where('parking_lot_id', $parkingLotId);
        }

        if ($status !== null) {
            $query->where('status', $status->value);
        }

        if ($type !== null) {
            $query->where('type', $type->value);
        }

        $search = $search === null ? '' : trim($search);

        if ($search !== '') {
            $needle = '%'.mb_strtolower($search).'%';
            $query->where(function ($inner) use ($needle): void {
                $inner->whereRaw('lower(name) like ?', [$needle])
                    ->orWhereRaw('lower(code) like ?', [$needle])
                    ->orWhereRaw('lower(coalesce(serial_number, \'\')) like ?', [$needle]);
            });
        }

        $counts = array_fill_keys(array_map(fn (DeviceStatus $case): string => $case->value, DeviceStatus::cases()), 0);

        $rows = Device::query()
            ->where('tenant_id', $tenantId)
            ->when($parkingLotId !== null, fn ($inner) => $inner->where('parking_lot_id', $parkingLotId))
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->toBase()
            ->get();

        foreach ($rows as $row) {
            $counts[(string) $row->status] = (int) $row->aggregate;
        }

        return [
            'devices' => $query->paginate(perPage: $perPage, page: $page),
            'status_counts' => $counts,
        ];
    }
}
