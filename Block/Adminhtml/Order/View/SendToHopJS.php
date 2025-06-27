<?php
namespace Hop\Envios\Block\Adminhtml\Order\View;

use Magento\Framework\UrlInterface;
use Magento\Backend\Block\Template\Context;
class SendToHopJS extends \Magento\Backend\Block\Template
{

    /**
     * @var UrlInterface
     */
    protected $backendUrl;

    /**
     * @param UrlInterface $backendUrl
     * @param Context $context
     */
    public function __construct(
        UrlInterface $backendUrl,
        Context $context
    ) {
        $this->backendUrl = $backendUrl;
        parent::__construct($context);
    }
    /**
     * @return string
     */
    public function getFormAction()
    {
        return $this->backendUrl->getUrl('hop/order/send');
    }
}