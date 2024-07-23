<?php

namespace Improntus\Hop\Controller\Adminhtml\Order;

use Magento\Backend\App\Action\Context;
use Magento\Backend\App\Action;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\View\LayoutFactory;
use Magento\Framework\Controller\Result\Raw;

class View extends Action
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
     * @param Context $context,
     * @param        RawFactory $resultRawFactory,
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
        $post = $this->getRequest()->getPostValue();
        if (!empty($post['id'])) {
            $orderId = $post['id'];
            $content = $this->layoutFactory->create()->createBlock(
                \Improntus\Hop\Block\Adminhtml\View::class,
                'hop_selector',
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
