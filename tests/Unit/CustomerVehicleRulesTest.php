<?php

namespace Tests\Unit;

use App\Modules\Customers\Domain\CustomerDocument;
use App\Modules\Customers\Domain\CustomerRuleViolation;
use App\Modules\Customers\Domain\CustomerType;
use App\Modules\Vehicles\Domain\CountryCode;
use App\Modules\Vehicles\Domain\Plate;
use App\Modules\Vehicles\Domain\VehicleRuleViolation;
use App\Modules\Vehicles\Domain\VehicleType;
use App\Modules\Vehicles\Infrastructure\Plates\ColombiaPlateRules;
use App\Modules\Vehicles\Infrastructure\Plates\CountryPlateRules;
use PHPUnit\Framework\TestCase;

class CustomerVehicleRulesTest extends TestCase
{
    public function test_plates_are_compared_without_separators_or_case(): void
    {
        foreach (['abc-123', 'ABC123', ' ABC 123 ', 'abc.123', 'Abc·123'] as $input) {
            $this->assertSame('ABC123', Plate::fromInput($input)->value, $input);
        }

        $this->assertSame('ABC12', Plate::searchTerm('abc-12'));
        $this->assertSame('', Plate::searchTerm('---'));
    }

    public function test_invalid_plates_are_rejected(): void
    {
        foreach (['', 'A', 'ÑAB123', 'ABC#123', str_repeat('A', 13)] as $input) {
            try {
                Plate::fromInput($input);
                $this->fail("Plate {$input} should be rejected");
            } catch (VehicleRuleViolation $violation) {
                $this->assertSame('INVALID_PLATE', $violation->errorCode);
            }
        }
    }

    public function test_country_codes_are_iso_alpha_2(): void
    {
        $this->assertSame('CO', CountryCode::normalize(' co '));
        $this->expectException(VehicleRuleViolation::class);
        CountryCode::normalize('COL');
    }

    public function test_colombian_formats_are_recognized_and_suggest_a_type(): void
    {
        $rules = new ColombiaPlateRules;

        $this->assertSame(VehicleType::Car, $rules->recognize(Plate::fromInput('ABC123'))?->suggestedType);
        $this->assertSame('motorcycle', $rules->recognize(Plate::fromInput('abc12d'))?->key);
        $this->assertSame('motorcycle_legacy', $rules->recognize(Plate::fromInput('ABC12'))?->key);
        $this->assertSame('motocarro', $rules->recognize(Plate::fromInput('123ABC'))?->key);
        $this->assertSame('diplomatic', $rules->recognize(Plate::fromInput('CD-1234'))?->key);
        $this->assertNull($rules->recognize(Plate::fromInput('AB1234CD')));
    }

    public function test_countries_without_rules_are_normalized_but_not_described(): void
    {
        $registry = new CountryPlateRules;

        $this->assertTrue($registry->supports('CO'));
        $this->assertFalse($registry->supports('EC'));
        $this->assertNull($registry->recognize('EC', Plate::fromInput('ABC123')));
    }

    public function test_documents_are_normalized_and_must_come_in_pairs(): void
    {
        $document = CustomerDocument::fromInput(' cc ', '1.020.304-5');

        $this->assertNotNull($document);
        $this->assertSame('CC', $document->type);
        $this->assertSame('1.020.304-5', $document->number);
        $this->assertSame('10203045', $document->normalized);
        $this->assertNull(CustomerDocument::fromInput(null, '  '));

        $this->expectException(CustomerRuleViolation::class);
        CustomerDocument::fromInput('NIT', null);
    }

    public function test_only_businesses_and_corporates_are_organizations(): void
    {
        $this->assertFalse(CustomerType::Individual->isOrganization());
        $this->assertTrue(CustomerType::Business->isOrganization());
        $this->assertTrue(CustomerType::Corporate->isOrganization());
    }
}
