<?php
declare(strict_types=1);

namespace Hop\Envios\Controller\Adminhtml\System\Config;

use Hop\Envios\Logger\LoggerInterface;
use Hop\Envios\Model\Ubigeo\PeruAddressAttributesManager;
use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;

/**
 * Ajax action for the "Crear campos de Distrito y Provincia" button in Stores > Configuration >
 * Shipping > Hop. Creates the two customer_address select attributes and points
 * ubigeo_provincia_attribute/ubigeo_distrito_attribute at them, only when an admin requests it.
 */
class CreatePeruAddressAttributes extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Magento_Config::config';

    public function __construct(
        Action\Context $context,
        private readonly PeruAddressAttributesManager $attributesManager,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        /** @var Json $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);

        try {
            $this->attributesManager->create();

            return $result->setData([
                'error'   => false,
                'message' => (string)__('Campos de Distrito y Provincia creados y seleccionados correctamente.'),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Error creating Peru address attributes', ['exception' => $e->getMessage()]);

            return $result->setData([
                'error'   => true,
                'message' => (string)__('Ocurrió un error al crear los campos: %1', $e->getMessage()),
            ]);
        }
    }
}
