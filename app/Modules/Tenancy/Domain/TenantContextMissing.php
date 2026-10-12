<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Domain;

use RuntimeException;

final class TenantContextMissing extends RuntimeException {}
