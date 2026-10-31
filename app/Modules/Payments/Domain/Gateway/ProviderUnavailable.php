<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Gateway;

use RuntimeException;

/**
 * The provider did not answer or answered with an error. The operation can
 * be retried later; nothing was changed on Nuvora's side.
 */
final class ProviderUnavailable extends RuntimeException {}
