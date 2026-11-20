<?php

namespace Tests\Feature;

use App\Support\PurchaseRequisitionFormatter;
use Tests\TestCase;

class PurchaseRequisitionFormattingTest extends TestCase
{
    public function test_quantity_and_rupiah_use_indonesian_separators_and_expected_precision(): void
    {
        $this->assertSame('10,000000', PurchaseRequisitionFormatter::quantity('10.000000'));
        $this->assertSame('10.000,123456', PurchaseRequisitionFormatter::quantity('10000.123456'));
        $this->assertSame('Rp 90.000,00', PurchaseRequisitionFormatter::rupiah('90000.00000000'));
        $this->assertSame('Rp 16,00', PurchaseRequisitionFormatter::rupiah('16.00000000'));
        $this->assertSame('Rp 2.500,13', PurchaseRequisitionFormatter::rupiah('2500.12500000'));
    }
}
