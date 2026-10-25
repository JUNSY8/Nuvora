<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Gateway;

use RuntimeException;

/**
 * A notification whose signature does not match the account's secret:
 * possibly forged, so it is rejected without touching any payment.
 */
final class InvalidProviderSignature extends RuntimeException {}
