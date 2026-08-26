<?php

namespace Hop\Envios\Block\Adminhtml\Order;

use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Template;
use Magento\Framework\UrlInterface;
use Hop\Envios\Model\HopEnviosRepository;
use Hop\Envios\Model\HopEnviosShipmentRepository;
use Magento\Sales\Model\ResourceModel\Order\Shipment\CollectionFactory as ShipmentCollectionFactory;

class HopLabelsView extends Template
{
    public $_template = 'Hop_Envios::order/labels-view.phtml';

    /**
     * @var UrlInterface
     */
    protected $backendUrl;

    /**
     * @var HopEnviosRepository
     */
    protected $hopEnviosRepository;

    /**
     * @var HopEnviosShipmentRepository
     */
    protected $hopEnviosShipmentRepository;

    /**
     * @var ShipmentCollectionFactory
     */
    protected $shipmentCollectionFactory;

    /**
     * @param UrlInterface $backendUrl
     * @param HopEnviosRepository $hopEnviosRepository
     * @param HopEnviosShipmentRepository $hopEnviosShipmentRepository
     * @param ShipmentCollectionFactory $shipmentCollectionFactory
     * @param Context $context
     * @param array $data
     */
    public function __construct(
        UrlInterface $backendUrl,
        HopEnviosRepository $hopEnviosRepository,
        HopEnviosShipmentRepository $hopEnviosShipmentRepository,
        ShipmentCollectionFactory $shipmentCollectionFactory,
        Context $context,
        array $data = []
    ) {
        $this->backendUrl = $backendUrl;
        $this->hopEnviosRepository = $hopEnviosRepository;
        $this->hopEnviosShipmentRepository = $hopEnviosShipmentRepository;
        $this->shipmentCollectionFactory = $shipmentCollectionFactory;
        parent::__construct($context, $data);
    }

    /**
     * One row per Hop shipment: increment id, tracking number, download URL, tracking URL.
     *
     * Falls back to the order-level info_hop when the envio has no per-shipment records at
     * all (legacy orders dispatched before hop_envios_shipment existed).
     *
     * @return array<int, array{increment_id: string, tracking_nro: string, download_url: string, tracking_url: string}>
     */
    public function getShipmentsData(): array
    {
        $orderId = (int)$this->getData('order_id');
        if (!$orderId) {
            return [];
        }

        $hopEnvio = $this->hopEnviosRepository->getByOrderId($orderId);
        if (!$hopEnvio) {
            return [];
        }

        $records = $this->hopEnviosShipmentRepository->getByHopEnvioId((int)$hopEnvio->getEntityId());

        if (empty($records)) {
            return $this->getLegacyOrderLevelRow($orderId, $hopEnvio);
        }

        $incrementIds = $this->getShipmentIncrementIds($orderId);

        $rows = [];
        foreach ($records as $record) {
            if (empty($record->getLabelUrl())) {
                continue;
            }
            $shipmentId = (int)$record->getShipmentId();
            $rows[] = [
                'increment_id' => $incrementIds[$shipmentId] ?? ('#' . $shipmentId),
                'tracking_nro' => (string)($record->getTrackingNro() ?? ''),
                'download_url' => $this->getDownloadUrl($record->getLabelUrl(), ['shipment_id' => $shipmentId]),
                'tracking_url' => $this->getTrackingUrl($record->getTrackingNro()),
            ];
        }
        return $rows;
    }

    /**
     * @param int $orderId
     * @param \Hop\Envios\Model\HopEnvios $hopEnvio
     * @return array<int, array{increment_id: string, tracking_nro: string, download_url: string, tracking_url: string}>
     */
    private function getLegacyOrderLevelRow(int $orderId, $hopEnvio): array
    {
        $infoHop = json_decode($hopEnvio->getInfoHop() ?? '', true);
        if (!is_array($infoHop) || empty($infoHop['label_url'])) {
            return [];
        }

        return [[
            'increment_id' => (string)$hopEnvio->getIncrementId(),
            'tracking_nro' => (string)($infoHop['tracking_nro'] ?? ''),
            'download_url' => $this->getDownloadUrl($infoHop['label_url'] ?? '', ['order_id' => $orderId]),
            'tracking_url' => $this->getTrackingUrl($infoHop['tracking_nro'] ?? ''),
        ]];
    }

    /**
     * @param string|null $trackingNro
     * @return string
     */
    private function getTrackingUrl($trackingNro): string
    {
        if (empty($trackingNro)) {
            return '';
        }
        return 'https://hopenvios.com.ar/segui-tu-envio?c=' . rawurlencode($trackingNro);
    }

    /**
     * ZPL labels are printer-format files meant to be opened directly, not proxied through
     * the PDF-converting download controller — same distinction the old single-button flow made.
     *
     * @param string|null $labelUrl
     * @param array $downloadParams
     * @return string
     */
    private function getDownloadUrl($labelUrl, array $downloadParams): string
    {
        if (empty($labelUrl)) {
            return '';
        }
        if (substr_compare($labelUrl, '.zpl', -4) === 0) {
            return str_ireplace('http://', 'https://', $labelUrl);
        }
        return $this->backendUrl->getUrl('hop/label/download', $downloadParams);
    }

    /**
     * @param int $orderId
     * @return array<int, string> shipment_id => increment_id
     */
    private function getShipmentIncrementIds(int $orderId): array
    {
        $shipments = $this->shipmentCollectionFactory->create()
            ->addFieldToFilter('order_id', $orderId)
            ->getItems();

        $map = [];
        foreach ($shipments as $shipment) {
            $map[(int)$shipment->getEntityId()] = (string)$shipment->getIncrementId();
        }
        return $map;
    }
}
