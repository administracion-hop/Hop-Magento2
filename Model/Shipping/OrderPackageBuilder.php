<?php

namespace Hop\Envios\Model\Shipping;

use Hop\Envios\Helper\Data as HopHelper;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Sales\Model\Order;

/**
 * Rebuilds, from a placed order, the package and destination Model\Carrier\Hop::collectRates()
 * sent to Hop when the order was quoted in the checkout, so re-quoting a pickup point for that
 * order prices it the same way.
 */
class OrderPackageBuilder
{
    /**
     * @var HopHelper
     */
    protected $helper;

    /**
     * @var ProductResource
     */
    protected $productResource;

    /**
     * @param HopHelper $helper
     * @param ProductResource $productResource
     */
    public function __construct(
        HopHelper $helper,
        ProductResource $productResource
    ) {
        $this->helper = $helper;
        $this->productResource = $productResource;
    }

    /**
     * Same rules as collectRates(): configurable parents are skipped and their children take
     * the parent's qty (and, here, the parent's price, which is what the buyer paid); height
     * is the sum of every item's height, length and width the largest of each.
     *
     * Dimensions are read from the current product, as they aren't stored with the order.
     *
     * @param Order $order
     * @return array width, length, height, weight (g), value
     */
    public function build(Order $order): array
    {
        $storeId = $order->getStoreId();
        $height = 0;
        $lengths = [0];
        $widths = [0];
        $totalPrice = 0;

        foreach ($order->getAllItems() as $item) {
            if ($item->getProductType() === 'configurable') {
                continue;
            }

            $parentItem = $item->getParentItem();
            $priceItem = $parentItem && $parentItem->getProductType() === 'configurable' ? $parentItem : $item;
            $qty = (float)($parentItem ? $parentItem->getQtyOrdered() : $item->getQtyOrdered());
            $productId = (int)$item->getProductId();

            $height += $this->getMeasure($productId, 'alto', $qty, $storeId);
            $lengths[] = $this->getMeasure($productId, 'largo', $qty, $storeId);
            $widths[] = $this->getMeasure($productId, 'ancho', $qty, $storeId);

            $totalPrice += (float)$priceItem->getBasePrice() * $qty;
        }

        return [
            'width' => max($widths),
            'length' => max($lengths),
            'height' => $height,
            'weight' => (float)$order->getWeight() * 1000,
            'value' => (int)$totalPrice,
        ];
    }

    /**
     * The order's destination for Hop: the Peru ubigeo when one is configured and resolvable,
     * else the shipping postcode if it is numeric (Hop only takes numeric zip codes).
     *
     * @param Order $order
     * @return string|null
     */
    public function getDestZipCode(Order $order): ?string
    {
        $address = $order->getShippingAddress();
        if (!$address) {
            return null;
        }

        $storeId = $order->getStoreId();
        if ($this->helper->isUbigeoConfigured($storeId)) {
            $ubigeo = $this->helper->getUbigeoFromAddress($address, $storeId);
            if ($ubigeo) {
                return (string)$ubigeo;
            }
        }

        $postcode = (string)$address->getPostcode();
        return ctype_digit($postcode) ? $postcode : null;
    }

    /**
     * @param Order $order
     * @return string
     */
    public function getCountryCode(Order $order): string
    {
        $address = $order->getShippingAddress();
        $countryId = $address ? $address->getCountryId() : null;
        return $countryId ?: ($this->helper->getStoreCountry($order->getStoreId()) ?: 'AR');
    }

    /**
     * @param int $productId
     * @param string $measureCode alto|largo|ancho
     * @param float $qty
     * @param int|null $storeId
     * @return int
     */
    protected function getMeasure(int $productId, string $measureCode, float $qty, $storeId): int
    {
        $value = $this->productResource->getAttributeRawValue(
            $productId,
            $this->helper->getMeasureCode($measureCode, $storeId),
            $storeId
        );
        return (int)((int)(is_array($value) ? reset($value) : $value) * $qty);
    }
}
