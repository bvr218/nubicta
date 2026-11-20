<?php

namespace Tests\Unit;

use App\Services\Documents\TradeRegistrar;
use PHPUnit\Framework\TestCase;

/** El formulario de ventas pide el descuento en porcentaje; la línea lo guarda en pesos. */
class DiscountPercentTest extends TestCase
{
    public function test_a_five_percent_line_comes_back_as_five_percent_and_the_same_cent(): void
    {
        $lines = [
            [15, 13025.210084, 9768.91],
            [15, 21904.761905, 16428.57],
        ];

        foreach ($lines as [$quantity, $price, $discount]) {
            $percent = TradeRegistrar::discountPercent($quantity, $price, $discount);

            $this->assertEqualsWithDelta(5, $percent, 0.000002);
            $this->assertSame($discount, round($quantity * $price * $percent / 100, 2));
        }
    }

    public function test_a_line_without_gross_has_no_percentage(): void
    {
        $this->assertSame(0.0, TradeRegistrar::discountPercent(0, 100, 50));
    }
}
