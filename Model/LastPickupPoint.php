<?php

namespace Hop\Envios\Model;

use Hop\Envios\Helper\Data as HopHelper;
use Hop\Envios\Model\ResourceModel\Point\CollectionFactory as PointCollectionFactory;
use Magento\Quote\Model\Quote;

/**
 * Pickup point of the customer's last Hop order, used to preselect it in checkout.
 * Shared by the selected-point endpoint and the carrier fallback.
 */
class LastPickupPoint
{
    /**
     * @var OrderPickupPointRepository
     */
    private $orderPickupPointRepository;

    /**
     * @var PointCollectionFactory
     */
    private $pointCollectionFactory;

    /**
     * @var HopHelper
     */
    private $helper;

    public function __construct(
        OrderPickupPointRepository $orderPickupPointRepository,
        PointCollectionFactory $pointCollectionFactory,
        HopHelper $helper
    ) {
        $this->orderPickupPointRepository = $orderPickupPointRepository;
        $this->pointCollectionFactory = $pointCollectionFactory;
        $this->helper = $helper;
    }

    /**
     * Returns hop_data for the quote's customer last Hop pickup point, or null for guests,
     * customers without Hop history, or points no longer listed in the local points cache.
     *
     * @param Quote $quote
     * @return array|null
     */
    public function getForQuote(Quote $quote): ?array
    {
        $customerId = (int)$quote->getCustomerId();
        if (!$customerId || $quote->getCustomerIsGuest()) {
            return null;
        }

        $lastPoint = $this->orderPickupPointRepository->getLastByCustomerId($customerId);
        if (!$lastPoint) {
            return null;
        }

        $pickupPointId = (string)$lastPoint->getPickupPointId();
        // Changing the point from the admin only updates pickup_point_id; the original_* columns
        // still describe the old point, so the text shown would not match the shipment's point.
        if ($pickupPointId !== (string)$lastPoint->getOriginalPickupPointId()) {
            return null;
        }
        $zipCode = (string)$lastPoint->getOriginalZipCode();
        if (!$this->isPointListed($pickupPointId, $zipCode, (int)$quote->getStoreId())) {
            return null;
        }

        return [
            'hopPointId' => $pickupPointId,
            'hopPointPostcode' => $zipCode,
            'hopPointDescription' => (string)$lastPoint->getOriginalShippingDescription(),
        ];
    }

    /**
     * Checks the point is still offered, against the cache refreshed by Cron/LoadPoints.
     * The cache can lag up to an hour behind Hop; good enough for a preselection.
     */
    private function isPointListed(string $pickupPointId, string $zipCode, int $storeId): bool
    {
        $collection = $this->pointCollectionFactory->create()
            ->addFieldToFilter('zip_code', $zipCode)
            ->addFieldToFilter('client_id', (string)$this->helper->getClientId($storeId));

        foreach ($collection as $row) {
            $pointData = json_decode((string)$row->getPointData());
            foreach ($pointData->data ?? [] as $point) {
                if (isset($point->id) && (string)$point->id === $pickupPointId) {
                    return true;
                }
            }
        }

        return false;
    }
}
