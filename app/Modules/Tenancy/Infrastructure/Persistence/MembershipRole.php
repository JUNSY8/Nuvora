<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['membership_id', 'role_id'])]
class MembershipRole extends Model
{
    use HasUlids;

    public $timestamps = false;
}
