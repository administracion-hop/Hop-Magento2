<?php

namespace Hop\Envios\Test\Unit\Model\Shipping;

use Hop\Envios\Helper\Data as HopHelper;
use Hop\Envios\Model\Shipping\OrderPackageBuilder;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address;
use Magento\Sales\Model\Order\Item;
use Magento\Tax\Model\Config as TaxConfig;
use PHPUnit\Framework\TestCase;

class OrderPackageBuilderTest extends TestCase
{
    /**
     * Product id => [hop_alto, hop_largo, hop_ancho]
     */
    private const MEASURES = [
        1 => [3, 30, 20],
        2 => [12, 33, 22],
        5 => [10, 40, 15],
    ];

    /**
     * @var HopHelper|\PHPUnit\Framework\MockObject\MockObject
     */
    private $helper;

    /**
     * @var OrderPackageBuilder
     */
    private $builder;

    /**
     * @var bool "Catalog Prices: Including Tax" for the order's store
     */
    private $catalogIncludesTax = false;

    protected function setUp(): void
    {
        $this->helper = $this->createMock(HopHelper::class);
        $this->helper->method('getMeasureCode')->willReturnCallback(fn ($code) => 'hop_' . $code);

        $productResource = $this->createMock(ProductResource::class);
        $productResource->method('getAttributeRawValue')->willReturnCallback(
            function ($productId, $attribute) {
                $index = ['hop_alto' => 0, 'hop_largo' => 1, 'hop_ancho' => 2][$attribute];
                return (string)self::MEASURES[$productId][$index];
            }
        );

        $taxConfig = $this->createMock(TaxConfig::class);
        $taxConfig->method('priceIncludesTax')->with(1)->willReturnCallback(fn () => $this->catalogIncludesTax);

        $this->builder = new OrderPackageBuilder($this->helper, $productResource, $taxConfig);
    }

    /**
     * @param array $data
     * @param Item|null $parent
     * @return Item
     */
    private function item(array $data, ?Item $parent = null): Item
    {
        $item = $this->getMockBuilder(Item::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getProductType', 'getParentItem', 'getQtyOrdered', 'getProductId', 'getBasePrice', 'getBasePriceInclTax'])
            ->getMock();
        $item->method('getProductType')->willReturn($data['type'] ?? 'simple');
        $item->method('getParentItem')->willReturn($parent);
        $item->method('getQtyOrdered')->willReturn($data['qty'] ?? 1);
        $item->method('getProductId')->willReturn($data['product_id'] ?? null);
        $item->method('getBasePrice')->willReturn($data['price'] ?? 0);
        $item->method('getBasePriceInclTax')->willReturn($data['price_incl_tax'] ?? $data['price'] ?? 0);
        return $item;
    }

    /**
     * @param Item[] $items
     * @param float $weight
     * @param Address|null $address
     * @return Order
     */
    private function order(array $items, float $weight = 1.5, ?Address $address = null): Order
    {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAllItems', 'getWeight', 'getStoreId', 'getShippingAddress'])
            ->getMock();
        $order->method('getAllItems')->willReturn($items);
        $order->method('getWeight')->willReturn($weight);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getShippingAddress')->willReturn($address);
        return $order;
    }

    public function testSimpleProducts(): void
    {
        $order = $this->order([
            $this->item(['product_id' => 1, 'qty' => 2, 'price' => 15000.75]),
            $this->item(['product_id' => 2, 'qty' => 1, 'price' => 60000]),
        ], 1.8);

        $this->assertSame(
            ['width' => 40, 'length' => 60, 'height' => 18, 'weight' => 1800.0, 'value' => 90001],
            $this->builder->build($order)
        );
    }

    public function testConfigurableUsesChildMeasuresAndParentQtyAndPrice(): void
    {
        $parent = $this->item(['type' => 'configurable', 'product_id' => 9, 'qty' => 3, 'price' => 20000]);
        $child = $this->item(['product_id' => 5, 'qty' => 1, 'price' => 0], $parent);

        $this->assertSame(
            ['width' => 45, 'length' => 120, 'height' => 30, 'weight' => 1500.0, 'value' => 60000],
            $this->builder->build($this->order([$parent, $child]))
        );
    }

    /**
     * Catalog prices include tax (21 %): the product costs 12100 in the catalog and the checkout
     * declared that to Hop, while Magento stored the item at 10000 without tax.
     */
    public function testCatalogPricesIncludingTaxDeclareThePriceWithTax(): void
    {
        $this->catalogIncludesTax = true;
        $order = $this->order([
            $this->item(['product_id' => 1, 'qty' => 2, 'price' => 10000, 'price_incl_tax' => 12100]),
        ]);

        $this->assertSame(24200, $this->builder->build($order)['value']);
    }

    public function testCatalogPricesWithoutTaxDeclareThePriceWithoutTax(): void
    {
        $order = $this->order([
            $this->item(['product_id' => 1, 'qty' => 2, 'price' => 10000, 'price_incl_tax' => 12100]),
        ]);

        $this->assertSame(20000, $this->builder->build($order)['value']);
    }

    /**
     * Configurable with catalog prices including tax: the child item's price is 0, the price
     * with tax of the chosen simple product is on the parent.
     */
    public function testConfigurableWithCatalogPricesIncludingTaxUsesParentPriceWithTax(): void
    {
        $this->catalogIncludesTax = true;
        $parent = $this->item(['type' => 'configurable', 'product_id' => 9, 'qty' => 3, 'price' => 20000, 'price_incl_tax' => 24200]);
        $child = $this->item(['product_id' => 5, 'qty' => 1, 'price' => 0, 'price_incl_tax' => 0], $parent);

        $this->assertSame(72600, $this->builder->build($this->order([$parent, $child]))['value']);
    }

    /**
     * @param string $postcode
     * @return Address
     */
    private function address(string $postcode, string $country = 'AR'): Address
    {
        $address = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getPostcode', 'getCountryId'])
            ->getMock();
        $address->method('getPostcode')->willReturn($postcode);
        $address->method('getCountryId')->willReturn($country);
        return $address;
    }

    public function testDestZipIsTheNumericPostcode(): void
    {
        $this->assertSame('1414', $this->builder->getDestZipCode($this->order([], 0, $this->address('1414'))));
    }

    public function testNonNumericPostcodeHasNoDestZip(): void
    {
        $this->assertNull($this->builder->getDestZipCode($this->order([], 0, $this->address('C1414ABC'))));
    }

    public function testUbigeoWinsOverPostcodeWhenConfigured(): void
    {
        $address = $this->address('15074', 'PE');
        $this->helper->method('isUbigeoConfigured')->willReturn(true);
        $this->helper->method('getUbigeoFromAddress')->with($address, 1)->willReturn('150122');

        $this->assertSame('150122', $this->builder->getDestZipCode($this->order([], 0, $address)));
    }

    public function testCountryComesFromShippingAddress(): void
    {
        $this->assertSame('PE', $this->builder->getCountryCode($this->order([], 0, $this->address('150122', 'PE'))));
    }
}
