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
 * Ajax action for the "Revertir" button in Stores > Configuration > Shipping > Hop.
 * Removes the Distrito/Provincia attributes created via CreatePeruAddressAttributes and
 * clears the config pointing at them, only when an admin requests it.
 */
class RevertPeruAddressAttributes extends Action implements HttpPostActionInterface
{
    use ResolvesConfigScopeTrait;

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
            [$scope, $scopeId] = $this->resolveConfigScope();
            $this->attributesManager->revert($scope, $scopeId);

            return $result->setData([
                'error'   => false,
                'message' => (string)__('Campos de Distrito y Provincia eliminados correctamente.'),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Error reverting Peru address attributes', ['exception' => $e->getMessage()]);

            return $result->setData([
                'error'   => true,
                'message' => (string)__('Ocurrió un error al revertir los campos: %1', $e->getMessage()),
            ]);
        }
    }
}
