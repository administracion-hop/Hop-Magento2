<?php

declare(strict_types=1);

namespace Hop\Envios\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Filesystem;

/**
 * Descarga el PDF de etiquetas que MassLabels dejó guardado porque hubo pedidos afuera.
 *
 * Hay un archivo por usuario admin: cada lote pisa el anterior (no se acumulan etiquetas con
 * datos de clientes en var/) y nadie puede bajar el PDF que armó otro. Se borra al descargarlo.
 *
 * @version 1.0.0
 * @author Hop Envíos <https://hopenvios.com.ar>
 * @copyright Copyright (c) 2025 Hop Envíos
 * @package Hop\Envios\Controller\Adminhtml\Order
 */
class MassLabelsDownload extends Action implements HttpGetActionInterface
{
    /**
     * Authorization level of a basic admin session
     */
    public const ADMIN_RESOURCE = 'Hop_Envios::label_download';

    /**
     * @var FileFactory
     */
    private FileFactory $fileFactory;

    /**
     * @var Filesystem
     */
    private Filesystem $filesystem;

    /**
     * @param Context $context
     * @param FileFactory $fileFactory
     * @param Filesystem $filesystem
     */
    public function __construct(
        Context $context,
        FileFactory $fileFactory,
        Filesystem $filesystem
    ) {
        parent::__construct($context);
        $this->fileFactory = $fileFactory;
        $this->filesystem = $filesystem;
    }

    /**
     * Ruta del PDF pendiente de un usuario, relativa a var/.
     *
     * @param int $userId
     * @return string
     */
    public static function pendingPath(int $userId): string
    {
        return sprintf('hop_labels/etiquetas-%d.pdf', $userId);
    }

    /**
     * @return \Magento\Framework\App\ResponseInterface|\Magento\Framework\Controller\Result\Redirect
     */
    public function execute()
    {
        $path = self::pendingPath((int)$this->_auth->getUser()->getId());
        $varDir = $this->filesystem->getDirectoryRead(DirectoryList::VAR_DIR);

        if (!$varDir->isFile($path)) {
            $this->messageManager->addErrorMessage(
                __('El PDF de etiquetas ya no está disponible. Volvé a generarlo desde la grilla de pedidos.')
            );
            $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
            return $resultRedirect->setPath('sales/order/index');
        }

        return $this->fileFactory->create(
            'etiquetas-hop-' . date('Ymd-His', (int)$varDir->stat($path)['mtime']) . '.pdf',
            ['type' => 'filename', 'value' => $path, 'rm' => true],
            DirectoryList::VAR_DIR,
            'application/pdf'
        );
    }
}
