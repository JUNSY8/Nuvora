<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Domain;

use RuntimeException;

final class TenantNotAccessible extends RuntimeException {}
