<?php

namespace Hop\Envios\Test\Unit\Model\Shipping;

use Hop\Envios\Helper\Data as HopHelper;
use Hop\Envios\Model\Shipping\HopPriceCalculator;
use Hop\Envios\Model\Webservice;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

class HopPriceCalculatorTest extends TestCase
{
    /**
     * @var ScopeConfigInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $scopeConfig;

    /**
     * @var Webservice|\PHPUnit\Framework\MockObject\MockObject
     */
    private $webservice;

    /**
     * @var HopHelper|\PHPUnit\Framework\MockObject\MockObject
     */
    private $helper;

    /**
     * @var HopPriceCalculator
     */
    private $calculator;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->webservice = $this->createMock(Webservice::class);
        $this->helper = $this->createMock(HopHelper::class);
        $this->calculator = new HopPriceCalculator($this->scopeConfig, $this->webservice, $this->helper);
    }

    /**
     * @param mixed $percentageRate
     * @param mixed $fixedValue
     */
    private function configure($percentageRate, $fixedValue): void
    {
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['carriers/hop/percentage_rate', 'store', 1, $percentageRate],
            ['carriers/hop/fixed_value', 'store', 1, $fixedValue],
        ]);
    }

    /**
     * The adjustment block of Model\Carrier\Hop::collectRates() before it was moved into
     * HopPriceCalculator, kept verbatim as the reference the refactor must match.
     *
     * @param float $costoEnvio
     * @param mixed $percentageRate
     * @param mixed $fixedValue
     * @return float
     */
    private static function legacyCarrierAdjustment($costoEnvio, $percentageRate, $fixedValue)
    {
        if (!empty($percentageRate)) {

            $adjustedShippingCost = ($percentageRate == 1) ? $costoEnvio : $costoEnvio * $percentageRate;

            if (!empty($fixedValue)) {
                if ($fixedValue >= 0) {
                    $adjustedShippingCost += $fixedValue;
                } else {
                    $adjustedShippingCost -= abs($fixedValue);
                }
            }
        } else {
            $adjustedShippingCost = $costoEnvio;

            if (!empty($fixedValue)) {
                if ($fixedValue >= 0) {
                    $adjustedShippingCost += $fixedValue;
                } else {
                    $adjustedShippingCost -= abs($fixedValue);
                }
            }
        }

        return max(0, $adjustedShippingCost);
    }

    /**
     * @dataProvider adjustmentCases
     */
    public function testAdjustMatchesLegacyCarrier(float $cost, $percentageRate, $fixedValue): void
    {
        $this->configure($percentageRate, $fixedValue);

        $this->assertEqualsWithDelta(
            self::legacyCarrierAdjustment($cost, $percentageRate, $fixedValue),
            $this->calculator->adjust($cost, 1),
            0.0000001
        );
    }

    public static function adjustmentCases(): array
    {
        return [
            'config por defecto'         => [5321.45, '1', '0'],
            'sin configurar'             => [5321.45, null, null],
            'porcentaje vacío'           => [5321.45, '', ''],
            'porcentaje cero'            => [5321.45, '0', '0'],
            'recargo 15%'                => [5321.45, '1.15', '0'],
            'descuento 20%'              => [5321.45, '0.8', null],
            'fijo positivo'              => [5321.45, '1', '500'],
            'fijo negativo'              => [5321.45, '1', '-500'],
            'porcentaje y fijo'          => [4999.99, '1.1', '250.50'],
            'fijo sin porcentaje'        => [4999.99, '', '-100'],
            'negativo mayor que el costo' => [300, '1', '-1000'],
            'costo cero'                 => [0, '1.2', '0'],
        ];
    }

    public function testQuoteSendsPackageAndAdjustsPrice(): void
    {
        $this->configure('1.1', '100');
        $this->helper->method('getOriginZipcode')->with(1)->willReturn('1414');
        $this->helper->method('getSellerCode')->with(1)->willReturn('MENZE2');
        $package = ['width' => 20, 'length' => 30, 'height' => 3, 'weight' => 300, 'value' => 15000];

        $this->webservice->expects($this->once())->method('setStoreId')->with(1);
        $this->webservice->expects($this->once())
            ->method('estimatePrice')
            ->with('1414', '1405', 'MENZE2', '24', 'E', $package)
            ->willReturn(1000.0);

        $this->assertEqualsWithDelta(1200.0, $this->calculator->quote($package, '1405', '24', 1), 0.0001);
    }

    public function testQuoteReturnsNullWhenHopFails(): void
    {
        $this->configure('1', '0');
        $this->webservice->method('estimatePrice')->willReturn(false);

        $this->assertNull($this->calculator->quote([], '1405', '24', 1));
    }
}
