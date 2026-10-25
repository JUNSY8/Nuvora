<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Payments\Domain\PayableType;
use App\Modules\Payments\Domain\PaymentRuleViolation;

/**
 * The PayableSource of each payable type. The modules that own them are
 * tagged in the service provider.
 */
final class PayableSources
{
    public const TAG = 'payments.payable-sources';

    /** @var array<string, PayableSource> */
    private array $sources = [];

    /**
     * @param  iterable<PayableSource>  $sources
     */
    public function __construct(iterable $sources)
    {
        foreach ($sources as $source) {
            $this->sources[$source->type()->value] = $source;
        }
    }

    public function for(PayableType $type): PayableSource
    {
        return $this->sources[$type->value]
            ?? throw new PaymentRuleViolation('Este tipo de cobro no está disponible.', 'PAYABLE_UNAVAILABLE');
    }
}
