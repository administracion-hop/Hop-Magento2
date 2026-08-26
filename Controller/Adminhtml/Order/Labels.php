<?php

namespace Hop\Envios\Controller\Adminhtml\Order;

use Magento\Backend\App\Action\Context;
use Magento\Backend\App\Action;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\View\LayoutFactory;
use Magento\Framework\Controller\Result\Raw;

class Labels extends Action
{
    /**
     * @var RawFactory
     */
    protected $resultRawFactory;

    /**
     * @var LayoutFactory
     */
    protected $layoutFactory;

    /**
     * @param Context $context
     * @param RawFactory $resultRawFactory
     * @param LayoutFactory $layoutFactory
     */
    public function __construct(
        Context $context,
        RawFactory $resultRawFactory,
        LayoutFactory $layoutFactory
    ) {
        $this->resultRawFactory = $resultRawFactory;
        $this->layoutFactory = $layoutFactory;
        parent::__construct($context);
    }

    /**
     * @return Raw|void
     */
    public function execute()
    {
        $orderId = $this->getRequest()->getParam('id');
        if (!empty($orderId)) {
            $content = $this->layoutFactory->create()->createBlock(
                \Hop\Envios\Block\Adminhtml\Order\HopLabelsView::class,
                'hop_labels',
                [
                    'data' => [
                        'order_id' => $orderId
                    ]
                ]
            );
            /** @var Raw $resultRaw */
            $resultRaw = $this->resultRawFactory->create();
            return $resultRaw->setContents($content->toHtml());
        }
    }
}
