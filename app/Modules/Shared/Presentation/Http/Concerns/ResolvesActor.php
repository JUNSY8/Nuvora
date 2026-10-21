<?php

declare(strict_types=1);

namespace App\Modules\Shared\Presentation\Http\Concerns;

use App\Modules\Identity\Infrastructure\Persistence\User;
use Illuminate\Http\Request;

trait ResolvesActor
{
    private function actorId(Request $request): string
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        return (string) $user->id;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function pagination(Request $request, int $default, int $max): array
    {
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min($max, max(1, (int) $request->query('per_page', $default)));

        return [$page, $perPage];
    }
}
