<?php
declare(strict_types=1);

namespace Hop\Envios\Block\Adminhtml\System\Config;

use Hop\Envios\Model\Ubigeo\PeruUbigeoImporter;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Renders the "Importar Ubigeo" button on Stores > Configuration > Shipping > Hop.
 *
 * Hidden once the data is already imported — nothing to run twice.
 */
class ImportUbigeoButton extends Field
{
    protected $_template = 'Hop_Envios::system/config/import_ubigeo_button.phtml';

    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        private readonly PeruUbigeoImporter $importer,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        return $this->_toHtml();
    }

    public function isImported(): bool
    {
        return $this->importer->isImported();
    }

    public function getAjaxUrl(): string
    {
        return $this->getUrl('hop/system_config/importPeruUbigeo');
    }

    public function getButtonHtml(): string
    {
        return $this->getLayout()->createBlock(\Magento\Backend\Block\Widget\Button::class)
            ->setData([
                'id'    => 'hop_import_peru_ubigeo_button',
                'label' => __('Importar Ubigeo'),
            ])
            ->toHtml();
    }
}
