<?php
declare(strict_types=1);

namespace Hop\Envios\Controller\Adminhtml\System\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Resolves the config scope/scopeId the admin was viewing when clicking the button, matching
 * the "website"/"store" request params Magento\Config\Controller\Adminhtml\System\Config\Save
 * reads for the same purpose — so the value is written where the admin was looking, not always
 * at the default scope.
 */
trait ResolvesConfigScopeTrait
{
    /**
     * @return array{0: string, 1: int} [$scope, $scopeId]
     */
    private function resolveConfigScope(): array
    {
        $websiteId = (int)$this->getRequest()->getParam('website');
        if ($websiteId) {
            return [ScopeInterface::SCOPE_WEBSITES, $websiteId];
        }

        $storeId = (int)$this->getRequest()->getParam('store');
        if ($storeId) {
            return [ScopeInterface::SCOPE_STORES, $storeId];
        }

        return [ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0];
    }
}
