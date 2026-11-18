<?php

namespace Hop\Envios\Block\Adminhtml\Health;

use Hop\Envios\Model\Health\Report;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

/**
 * Franja del verificador arriba de todas las pantallas del administrador. Es un bloque propio y
 * no un mensaje de sistema de Magento (MessageInterface): esos solo muestran texto con severidad
 * crítica, escapan el link al detalle y abren una ventana emergente.
 *
 * Solo la ve quien puede corregir la configuración de Hop.
 */
class Notice extends Template
{
    const ACL_RESOURCE = 'Magento_Shipping::config_shipping';

    /**
     * @var string
     */
    protected $_template = 'Hop_Envios::health/notice.phtml';

    /**
     * @var Report
     */
    private $report;

    /**
     * @var array|null
     */
    private $counts = null;

    /**
     * @param Context $context
     * @param Report $report
     * @param array $data
     */
    public function __construct(Context $context, Report $report, array $data = [])
    {
        $this->report = $report;
        parent::__construct($context, $data);
    }

    /**
     * @return array{errors: int, warnings: int}
     */
    public function getCounts()
    {
        return $this->counts ?? ['errors' => 0, 'warnings' => 0];
    }

    /**
     * @return string
     */
    public function getDetailUrl()
    {
        return $this->getUrl('hop/health/index');
    }

    /**
     * @return string
     */
    protected function _toHtml()
    {
        if (!$this->_authorization->isAllowed(self::ACL_RESOURCE)) {
            return '';
        }
        try {
            $this->counts = $this->report->getCounts();
        } catch (\Throwable $e) {
            // La franja nunca puede romper una pantalla del administrador.
            $this->_logger->error('Hop: no se pudo calcular el verificador: ' . $e->getMessage());
            return '';
        }
        if (!$this->counts['errors'] && !$this->counts['warnings']) {
            return '';
        }
        return parent::_toHtml();
    }
}
