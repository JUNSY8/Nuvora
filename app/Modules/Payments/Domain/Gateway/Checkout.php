<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Gateway;

/**
 * Where the customer's browser goes to pay: a link (GET with query fields)
 * or a form posted to the provider. Every field is meant to be public; the
 * signatures in them are built with secrets that never leave the server.
 */
final class Checkout
{
    /**
     * @param  array<string, string>  $fields
     */
    public function __construct(
        public readonly string $url,
        public readonly string $method,
        public readonly array $fields,
    ) {}

    /**
     * @return array{url: string, method: string, fields: array<string, string>}
     */
    public function toArray(): array
    {
        return ['url' => $this->url, 'method' => $this->method, 'fields' => $this->fields];
    }
}
