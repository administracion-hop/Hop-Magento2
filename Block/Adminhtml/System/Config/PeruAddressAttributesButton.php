<?php
declare(strict_types=1);

namespace Hop\Envios\Block\Adminhtml\System\Config;

use Hop\Envios\Model\Ubigeo\PeruAddressAttributesManager;
use Hop\Envios\Model\Ubigeo\PeruUbigeoImporter;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget\Button;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Renders the "Crear campos de Distrito y Provincia" / "Revertir" buttons on
 * Stores > Configuration > Shipping > Hop.
 *
 * Only offered once the Ubigeo table itself has been imported — there is nothing to map
 * the new attributes to otherwise.
 */
class PeruAddressAttributesButton extends Field
{
    protected $_template = 'Hop_Envios::system/config/peru_address_attributes_button.phtml';

    public function __construct(
        Context $context,
        private readonly PeruUbigeoImporter $importer,
        private readonly PeruAddressAttributesManager $attributesManager,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        return $this->_toHtml();
    }

    public function isUbigeoImported(): bool
    {
        return $this->importer->isImported();
    }

    public function isCreated(): bool
    {
        return $this->attributesManager->isCreated();
    }

    public function getCreateAjaxUrl(): string
    {
        return $this->getUrl('hop/system_config/createPeruAddressAttributes', $this->getScopeUrlParams());
    }

    public function getRevertAjaxUrl(): string
    {
        return $this->getUrl('hop/system_config/revertPeruAddressAttributes', $this->getScopeUrlParams());
    }

    /**
     * Carries the website/store scope the admin is currently viewing, so the controller writes
     * the config at that same scope instead of always at default.
     */
    private function getScopeUrlParams(): array
    {
        $params = [];

        $websiteId = (int)$this->getRequest()->getParam('website');
        if ($websiteId) {
            $params['website'] = $websiteId;
        }

        $storeId = (int)$this->getRequest()->getParam('store');
        if ($storeId) {
            $params['store'] = $storeId;
        }

        return $params;
    }

    public function getCreateButtonHtml(): string
    {
        return $this->getLayout()->createBlock(Button::class)
            ->setData([
                'id'    => 'hop_create_peru_address_attributes_button',
                'label' => __('Crear campos de Distrito y Provincia'),
            ])
            ->toHtml();
    }

    public function getRevertButtonHtml(): string
    {
        return $this->getLayout()->createBlock(Button::class)
            ->setData([
                'id'    => 'hop_revert_peru_address_attributes_button',
                'label' => __('Revertir'),
            ])
            ->toHtml();
    }
}
