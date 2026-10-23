<?php

declare(strict_types=1);

namespace Hop\Envios\Controller\Adminhtml\Order;

use Hop\Envios\Logger\LoggerInterface;
use Hop\Envios\Model\Label\LabelPdf;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\Controller\ResultFactory;
use Magento\Sales\Model\ResourceModel\Order\Grid\Collection as OrderGridCollection;
use Magento\Sales\Model\ResourceModel\Order\Grid\CollectionFactory as OrderGridCollectionFactory;
use Magento\Ui\Api\BookmarkManagementInterface;
use Magento\Ui\Component\MassAction\Filter;

/**
 * Descarga masiva de etiquetas Hop: un único PDF con las etiquetas de los pedidos
 * seleccionados, en el orden en que los muestra la grilla.
 *
 * @version 1.0.0
 * @author Hop Envíos <https://hopenvios.com.ar>
 * @copyright Copyright (c) 2025 Hop Envíos
 * @package Hop\Envios\Controller\Adminhtml\Order
 */
class MassLabels extends Action implements HttpPostActionInterface
{
    /**
     * Authorization level of a basic admin session
     */
    public const ADMIN_RESOURCE = 'Hop_Envios::label_download';

    /**
     * Tope de pedidos por lote. Cada etiqueta es una descarga secuencial contra Hop, con el
     * timeout de 300s que trae Curl por defecto: sin tope, un "Select All" sobre la grilla
     * entera se queda sin max_execution_time después de haber bajado todo.
     */
    private const MAX_ORDERS = 100;

    /**
     * @var Filter
     */
    private Filter $filter;

    /**
     * @var OrderGridCollectionFactory
     */
    private OrderGridCollectionFactory $gridCollectionFactory;

    /**
     * @var LabelPdf
     */
    private LabelPdf $labelPdf;

    /**
     * @var FileFactory
     */
    private FileFactory $fileFactory;

    /**
     * @var BookmarkManagementInterface
     */
    private BookmarkManagementInterface $bookmarkManagement;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param Context $context
     * @param Filter $filter
     * @param OrderGridCollectionFactory $gridCollectionFactory La grilla, para respetar su orden y sus filtros
     * @param LabelPdf $labelPdf
     * @param FileFactory $fileFactory
     * @param BookmarkManagementInterface $bookmarkManagement
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        Filter $filter,
        OrderGridCollectionFactory $gridCollectionFactory,
        LabelPdf $labelPdf,
        FileFactory $fileFactory,
        BookmarkManagementInterface $bookmarkManagement,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->filter = $filter;
        $this->gridCollectionFactory = $gridCollectionFactory;
        $this->labelPdf = $labelPdf;
        $this->fileFactory = $fileFactory;
        $this->bookmarkManagement = $bookmarkManagement;
        $this->logger = $logger;
    }

    /**
     * @return \Magento\Framework\App\ResponseInterface|\Magento\Framework\Controller\Result\Redirect
     */
    public function execute()
    {
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $resultRedirect->setPath('sales/order/index');

        try {
            $collection = $this->filter->getCollection($this->gridCollectionFactory->create());
            $this->applyGridSorting($collection);
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $resultRedirect;
        }

        if ($collection->getSize() > self::MAX_ORDERS) {
            $this->messageManager->addErrorMessage(
                __(
                    'Seleccionaste %1 pedidos y el máximo por lote es %2. Filtrá la grilla y descargá en tandas.',
                    $collection->getSize(),
                    self::MAX_ORDERS
                )
            );
            return $resultRedirect;
        }

        $pdfs = [];
        $withoutLabel = [];
        $failed = [];

        foreach ($collection as $order) {
            $increment = (string)$order->getIncrementId();
            $urls = $this->labelPdf->getLabelUrls((int)$order->getEntityId());

            if (!$urls) {
                $withoutLabel[] = $increment;
                continue;
            }

            // Todo o nada por pedido: media docena de bultos en el PDF y el pedido listado como
            // omitido es peor que no incluirlo, porque quien arma los paquetes lo imprime aparte
            // y termina con etiquetas repetidas.
            $pending = [];
            foreach ($urls as $url) {
                try {
                    $pending[] = $this->labelPdf->fetchAsPdf($url);
                } catch (\Exception $e) {
                    $this->logger->error('No se pudo sumar la etiqueta Hop al PDF masivo', [
                        'increment_id' => $increment,
                        'url' => $url,
                        'error' => $e->getMessage()
                    ]);
                    $pending = null;
                    $failed[] = $increment;
                    break;
                }
            }

            if ($pending !== null) {
                $pdfs = array_merge($pdfs, $pending);
            }
        }

        if (!$pdfs) {
            $this->messageManager->addErrorMessage(
                __('Ninguno de los pedidos seleccionados tiene etiqueta Hop para descargar.')
            );
            return $resultRedirect;
        }

        if ($withoutLabel) {
            $this->messageManager->addNoticeMessage(
                __(
                    '%1 pedido(s) sin etiqueta Hop, omitidos: %2',
                    count($withoutLabel),
                    implode(', ', $withoutLabel)
                )
            );
        }

        if ($failed) {
            $this->messageManager->addErrorMessage(
                __(
                    '%1 pedido(s) con etiqueta ilegible, quedaron fuera del PDF: %2',
                    count($failed),
                    implode(', ', $failed)
                )
            );
        }

        try {
            return $this->fileFactory->create(
                'etiquetas-hop-' . date('Ymd-His') . '.pdf',
                $this->labelPdf->merge($pdfs),
                DirectoryList::VAR_DIR,
                'application/pdf'
            );
        } catch (\Exception $e) {
            $this->logger->error('Error armando el PDF de etiquetas Hop', ['error' => $e->getMessage()]);
            $this->messageManager->addErrorMessage(
                __('Se produjo un error al armar el PDF de etiquetas: %1', $e->getMessage())
            );
            return $resultRedirect;
        }
    }

    /**
     * El POST de la acción masiva no manda el orden de la grilla: vive en el bookmark de la
     * vista del usuario, que es lo que la grilla está mostrando. Sin esto el PDF saldría en
     * orden de entity_id y no en el que ve quien arma los paquetes.
     *
     * @param OrderGridCollection $collection
     * @return void
     */
    private function applyGridSorting(OrderGridCollection $collection): void
    {
        $namespace = (string)$this->getRequest()->getParam('namespace');
        if ($namespace === '') {
            return;
        }

        try {
            $bookmark = $this->bookmarkManagement->getByIdentifierNamespace('current', $namespace);
            $columns = $bookmark ? ($bookmark->getConfig()['current']['columns'] ?? []) : [];
        } catch (\Exception $e) {
            // Vista sin bookmark guardado: queda el orden por defecto de la colección.
            return;
        }

        foreach ($columns as $field => $config) {
            $direction = $config['sorting'] ?? false;
            if ($direction !== 'asc' && $direction !== 'desc') {
                continue;
            }

            $connection = $collection->getResource()->getConnection();
            if ($connection->tableColumnExists($collection->getMainTable(), (string)$field)) {
                $collection->setOrder((string)$field, strtoupper($direction));
            }

            return;
        }
    }
}
