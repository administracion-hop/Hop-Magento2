<?php

namespace Hop\Envios\Model\Shipping;

use Hop\Envios\Helper\Data as HopHelper;
use Magento\Framework\DataObject;
use Magento\Shipping\Model\CarrierFactory;
use Magento\Shipping\Model\Shipping\LabelGenerator;

/**
 * Populates the native sales_shipment.shipping_label PDF for a Hop shipment.
 *
 * Deliberately does NOT go through \Magento\Shipping\Model\Shipping\LabelGenerator::create()
 * (the "Create Shipping Label..." admin button's own entry point) — that method's
 * Labels::requestToShipment() hard-requires a logged-in backend admin session
 * ($authSession->getUser()->getFirstName()) purely to fill in generic "shipper contact"
 * fields real carrier APIs want. Hop's own _doShipmentRequest() ignores all of that. Calling
 * create() from cron/CLI context (no admin session) fatals with an uncaught Error — verified
 * directly. So this replicates only the two things actually needed: the carrier call
 * (AbstractCarrierOnline::requestToShipment(), which needs no admin session) and PDF assembly
 * (LabelGenerator::combineLabelsPdf(), a public method — safe to call standalone, and still
 * goes through LabelGeneratorPlugin exactly as before since plugins intercept by method, not
 * by caller).
 *
 * Hop's label_url is returned optimistically in the dispatch response before the file is
 * actually available on S3/CloudFront (observed ~1 minute upload delay) — a call right after
 * dispatch routinely 403s. Callers should treat generate() as a "try now" that can legitimately
 * fail and needs retrying later (see Cron\GenerateShippingLabels), not a one-shot guarantee.
 *
 * $shipment->save() here re-fires sales_order_shipment_save_after synchronously if called from
 * that observer. That's safe only because the per-shipment idempotency guard in
 * SalesOrderShipmentSaveAfter treats a shipment with an existing hop_envios_shipment record as
 * already processed — the re-entrant call is a no-op. Don't call generate() from a context
 * without that guard without checking for reentrancy some other way.
 */
class NativeLabelGenerator
{
    /**
     * @var HopHelper
     */
    private $helper;

    /**
     * @var CarrierFactory
     */
    private $carrierFactory;

    /**
     * @var LabelGenerator
     */
    private $labelGenerator;

    /**
     * @param HopHelper $helper
     * @param CarrierFactory $carrierFactory
     * @param LabelGenerator $labelGenerator
     */
    public function __construct(HopHelper $helper, CarrierFactory $carrierFactory, LabelGenerator $labelGenerator)
    {
        $this->helper = $helper;
        $this->carrierFactory = $carrierFactory;
        $this->labelGenerator = $labelGenerator;
    }

    /**
     * @param \Magento\Sales\Model\Order\Shipment $shipment
     * @return bool true if the shipment now has a shipping_label (already had one, or this call set it)
     */
    public function generate($shipment): bool
    {
        if ($shipment->getShippingLabel()) {
            return true;
        }
        try {
            $order = $shipment->getOrder();
            $carrier = $this->carrierFactory->create($order->getShippingMethod(true)->getCarrierCode());
            if (!$carrier || !$carrier->isShippingLabelsAvailable()) {
                $this->helper->log('NativeLabelGenerator: carrier unavailable for shipment ' . $shipment->getId(), true);
                return false;
            }

            $pkgData = $this->helper->getPackageDataForShipment($shipment, $shipment->getStoreId());
            $request = new DataObject([
                'order_shipment' => $shipment,
                'store_id' => $shipment->getStoreId(),
                'packages' => [
                    '1' => [
                        'params' => [
                            'container' => 'Package',
                            'weight' => $pkgData['weight'] ?: 1,
                            'customs_value' => $pkgData['value'] ?: 0,
                            'length' => $pkgData['length'] ?: '',
                            'width' => $pkgData['width'] ?: '',
                            'height' => $pkgData['height'] ?: '',
                            'weight_units' => 'KILOGRAM',
                            'dimension_units' => 'CENTIMETER',
                            'content_type' => '',
                            'content_type_other' => '',
                        ],
                        'items' => [],
                    ],
                ],
            ]);

            $response = $carrier->requestToShipment($request);
            if ($response->hasErrors()) {
                $this->helper->log('NativeLabelGenerator: ' . $response->getErrors(), true);
                return false;
            }

            $labelsContent = [];
            foreach ((array)$response->getInfo() as $info) {
                if (!empty($info['label_content'])) {
                    $labelsContent[] = $info['label_content'];
                }
            }
            if (!$labelsContent) {
                return false;
            }

            $outputPdf = $this->labelGenerator->combineLabelsPdf($labelsContent);
            $shipment->setShippingLabel($outputPdf->render());
            $shipment->save();
            return (bool)$shipment->getShippingLabel();
        } catch (\Exception $e) {
            $this->helper->log('NativeLabelGenerator: ' . $e->getMessage(), true);
            return false;
        }
    }
}
