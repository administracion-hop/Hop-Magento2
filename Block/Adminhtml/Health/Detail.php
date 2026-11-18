<?php

namespace Hop\Envios\Block\Adminhtml\Health;

use Hop\Envios\Model\Health\Checks;
use Hop\Envios\Model\Health\Report;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

/**
 * Detalle del verificador: qué falla, en qué vista de tienda, desde cuándo y cómo resolverlo.
 */
class Detail extends Template
{
    /**
     * @var string
     */
    protected $_template = 'Hop_Envios::health/detail.phtml';

    /**
     * @var Report
     */
    private $report;

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
     * @return array
     */
    public function getProblems()
    {
        return $this->report->getProblems();
    }

    /**
     * @param array $problem
     * @return bool
     */
    public function isError(array $problem)
    {
        return $problem['severity'] === Checks::SEVERITY_ERROR;
    }

    /**
     * "Sitio / Tienda / Vista" de cada vista de tienda afectada.
     *
     * @param int[] $storeIds
     * @return string[]
     */
    public function getStoreLabels(array $storeIds)
    {
        $labels = [];
        foreach ($storeIds as $storeId) {
            try {
                $store = $this->_storeManager->getStore($storeId);
                $labels[] = $store->getWebsite()->getName() . ' / ' . $store->getGroup()->getName() . ' / ' . $store->getName();
            } catch (\Exception $e) {
                $labels[] = (string)__('Vista de tienda %1', $storeId);
            }
        }
        return $labels;
    }

    /**
     * @param string|null $since fecha UTC de la base
     * @return string
     */
    public function formatSince($since)
    {
        return $since ? $this->formatDate($since, \IntlDateFormatter::MEDIUM, true) : '';
    }

    /**
     * @return string
     */
    public function getConfigUrl()
    {
        return $this->getUrl('adminhtml/system_config/edit', ['section' => 'shipping']) . '#shipping_hop-link';
    }
}
