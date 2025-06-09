<?php

namespace Hop\Envios\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Hop\Envios\Helper\Data as HelperData;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order as OrderResourceModel;
use Magento\Sales\Model\Order\Status\HistoryFactory;
use Hop\Envios\Model\EcomStatuses;

/**
 * Class Data
 *
 * @package Hop\Envios\Helper
 */
class StatusManager extends AbstractHelper
{
    /**
     * @var ScopeConfigInterface
     */
    protected $_scopeConfig;

    /**
     * @var StoreManagerInterface
     */
    protected $_storeManagerInterface;

    /**
     * @var HelperData
     */
    protected $_helperData;

    /**
     * @var CollectionFactory
     */
    protected $_orderCollectionFactory;

    /**
     * @var OrderResourceModel
     */
    protected $_orderResourceModel;

    /**
     * @var HistoryFactory
     */
    protected $_orderStatusHistoryFactory;

    /**
     * StatusManager constructor.
     * @param ScopeConfigInterface $scopeConfig
     * @param StoreManagerInterface $storeManagerInterface
     * @param HelperData $helperData
     * @param CollectionFactory $orderCollectionFactory
     * @param OrderResourceModel $orderResourceModel
     * @param HistoryFactory $orderStatusHistoryFactory
     * @param \Magento\Framework\App\Helper\Context $context
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManagerInterface,
        HelperData $helperData,
        CollectionFactory $orderCollectionFactory,
        OrderResourceModel $orderResourceModel,
        HistoryFactory $orderStatusHistoryFactory,
        \Magento\Framework\App\Helper\Context $context
    ) {
        $this->_scopeConfig = $scopeConfig;
        $this->_storeManagerInterface = $storeManagerInterface;
        $this->_helperData = $helperData;
        $this->_orderCollectionFactory = $orderCollectionFactory;
        $this->_orderResourceModel = $orderResourceModel;
        $this->_orderStatusHistoryFactory = $orderStatusHistoryFactory;
        parent::__construct($context);
    }


    /**
     * @param string $state
     * @param string $hopStatus
     * @return string
     */
    public function getSelectedStatus($state, $hopStatus)
    {
        return $this->_scopeConfig->getValue("carriers/hop/statuses/$state/$hopStatus", ScopeInterface::SCOPE_STORE);
    }

    public function processOrder($referenceId, $code, $comment)
    {
        $order = $this->getOrderByReferenceId($referenceId);
        if ($order) {
            $state = $order->getState();
            $newStatus = isset(EcomStatuses::STATUSES[$code]) ? $this->getSelectedStatus($state, $code) : $order->getStatus();
            if (!$this->isStatusAllowed($order, $newStatus)) {
                throw new \Magento\Framework\Exception\LocalizedException(
                    new \Magento\Framework\Phrase(__('Status not allowed'))
                );
            }

            $history = $this->_orderStatusHistoryFactory->create();
            $history->setParentId($order->getId())
                ->setComment($comment)
                ->setEntityName(Order::ENTITY)
                ->setStatus($newStatus)
                ->setIsCustomerNotified(0);

            $order->setStatus($newStatus);
            $order->addStatusHistory($history);
            $this->_orderResourceModel->save($order);
        } else {
            throw new \Magento\Framework\Exception\LocalizedException(
                new \Magento\Framework\Phrase(__('Order not found'))
            );
        }
    }

    /**
     * Get order
     *
     * @param string $referenceId
     * @return Order|null
     */
    protected function getOrderByReferenceId($referenceId)
    {
        $sellerCode = $this->_helperData->getSellerCode();
        $incrementId = str_replace($sellerCode . "-", "", $referenceId);
        return $this->getOrderByIncrementId($incrementId);
    }

    /**
     * Get order
     *
     * @param string $referenceId
     * @return Order|null
     */
    protected function getOrderByIncrementId($incrementId)
    {
        $collection = $this->_orderCollectionFactory->create();
        $order = $collection->addFieldToFilter('increment_id', ['eq' => $incrementId])->getFirstItem();
        return $order->getId() ? $order : null;
    }

    /**
     * @param Order $order
     * @param string $status
     * @return bool
     */
    private function isStatusAllowed($order, $status)
    {
        $allowedStatuses = $order->getConfig()->getStateStatuses($order->getState());
        return isset($allowedStatuses[$status]);
    }
}
