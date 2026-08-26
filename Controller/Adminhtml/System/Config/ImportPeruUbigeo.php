<?php
declare(strict_types=1);

namespace Hop\Envios\Controller\Adminhtml\System\Config;

use Hop\Envios\Logger\LoggerInterface;
use Hop\Envios\Model\Ubigeo\PeruUbigeoImporter;
use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;

/**
 * Ajax action for the "Importar Ubigeo" button in Stores > Configuration > Shipping > Hop.
 *
 * Runs the Peru Ubigeo mapping import on demand, only when an admin explicitly requests it.
 */
class ImportPeruUbigeo extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Magento_Config::config';

    public function __construct(
        Action\Context $context,
        private readonly PeruUbigeoImporter $importer,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        /** @var Json $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);

        try {
            $imported = $this->importer->import();
            $message  = $imported
                ? __('Ubigeo de Perú importado correctamente.')
                : __('El Ubigeo de Perú ya se encontraba cargado. No se realizaron cambios.');

            return $result->setData(['error' => false, 'message' => (string)$message]);
        } catch (\Throwable $e) {
            $this->logger->error('Error importing Peru Ubigeo', ['exception' => $e->getMessage()]);

            return $result->setData([
                'error'   => true,
                'message' => (string)__('Ocurrió un error al importar el Ubigeo de Perú: %1', $e->getMessage()),
            ]);
        }
    }
}
