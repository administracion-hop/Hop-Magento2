<?php

namespace Hop\Envios\Test\Unit\Model\Health;

use Hop\Envios\Helper\Data as HelperHop;
use Hop\Envios\Model\Health\Checks;
use Hop\Envios\Model\Health\ConfigChecks;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\TestCase;

/**
 * Revisiones 2, 3, 4 y 8: cada una con su caso de problema y su caso correcto.
 */
class ConfigChecksTest extends TestCase
{
    /** @var array */
    private $config = [];

    /** @var bool */
    private $columnExists = true;

    /** @var ConfigChecks */
    private $checks;

    protected function setUp(): void
    {
        $this->config = [
            'seller_code' => 'MENZE', 'storage_code' => 'DEPOSITO', 'shipping_type' => 'E', 'days_offset' => '1',
            'taxvat' => false, 'vat_visible' => true, 'document_attribute' => 'customer_dni',
            'productivo' => true, 'design/head/demonotice' => false, 'general/locale/weight_unit' => 'kgs',
        ];
        $helper = $this->createMock(HelperHop::class);
        $helper->method('getSellerCode')->willReturnCallback(fn () => $this->config['seller_code']);
        $helper->method('getStorageCode')->willReturnCallback(fn () => $this->config['storage_code']);
        $helper->method('getShippingType')->willReturnCallback(fn () => $this->config['shipping_type']);
        $helper->method('getDaysOffset')->willReturnCallback(fn () => $this->config['days_offset']);
        $helper->method('useCustomerTaxvat')->willReturnCallback(fn () => $this->config['taxvat']);
        $helper->method('IsVAtShowToFrontend')->willReturnCallback(fn () => $this->config['vat_visible']);
        $helper->method('getCustomerDocumentAttribute')->willReturnCallback(fn () => $this->config['document_attribute']);
        $helper->method('getProductivo')->willReturnCallback(fn () => $this->config['productivo']);

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturnCallback(fn ($path) => (bool)$this->config[$path]);
        $scopeConfig->method('getValue')->willReturnCallback(fn ($path) => $this->config[$path]);

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('tableColumnExists')->willReturnCallback(fn () => $this->columnExists);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $this->checks = new ConfigChecks($helper, $scopeConfig, $resource);
    }

    public function testCorrectConfigurationHasNoProblems(): void
    {
        $this->assertSame([], $this->checks->run(1));
    }

    /**
     * @return array [campo, valor]
     */
    public static function missingDispatchData(): array
    {
        return [
            'sin código de vendedor' => ['seller_code', ''],
            'sin código de almacenamiento' => ['storage_code', null],
            'sin tipo de envío' => ['shipping_type', ' '],
            'sin días de preparación' => ['days_offset', ''],
        ];
    }

    /**
     * @dataProvider missingDispatchData
     */
    public function testDispatchDataMissing($field, $value): void
    {
        $this->config[$field] = $value;
        $result = $this->checks->checkDispatchData(1);
        $this->assertSame(Checks::SEVERITY_ERROR, $result[0]);
    }

    public function testZeroDaysOffsetIsValid(): void
    {
        $this->config['days_offset'] = '0';
        $this->assertNull($this->checks->checkDispatchData(1));
    }

    public function testTaxvatHiddenInStore(): void
    {
        $this->config['taxvat'] = true;
        $this->config['vat_visible'] = false;
        $this->assertSame(Checks::SEVERITY_ERROR, $this->checks->checkDocument(1)[0]);

        $this->config['vat_visible'] = true;
        $this->assertNull($this->checks->checkDocument(1));
    }

    public function testDocumentAttributeEmpty(): void
    {
        $this->config['document_attribute'] = '';
        $this->assertSame(Checks::SEVERITY_ERROR, $this->checks->checkDocument(1)[0]);
    }

    public function testDocumentAttributeWithoutColumn(): void
    {
        $this->columnExists = false;
        $this->assertSame(Checks::SEVERITY_ERROR, $this->checks->checkDocument(1)[0]);
    }

    /**
     * @return array [tienda demo, Hop productivo, advertencia esperada]
     */
    public static function demoModeCases(): array
    {
        return [
            'tienda real con Hop en prueba' => [false, false, true],
            'tienda demo con Hop productivo' => [true, true, true],
            'tienda real con Hop productivo' => [false, true, false],
            'tienda demo con Hop en prueba' => [true, false, false],
        ];
    }

    /**
     * @dataProvider demoModeCases
     */
    public function testDemoMode($isDemo, $isProductive, $expectWarning): void
    {
        $this->config['design/head/demonotice'] = $isDemo;
        $this->config['productivo'] = $isProductive;
        $result = $this->checks->checkDemoMode(1);
        if ($expectWarning) {
            $this->assertSame(Checks::SEVERITY_WARNING, $result[0], 'es advertencia, no error');
        } else {
            $this->assertNull($result);
        }
    }

    public function testWeightUnit(): void
    {
        $this->config['general/locale/weight_unit'] = 'lbs';
        $this->assertSame(Checks::SEVERITY_ERROR, $this->checks->checkWeightUnit(1)[0]);
        $this->config['general/locale/weight_unit'] = 'kgs';
        $this->assertNull($this->checks->checkWeightUnit(1));
    }
}
