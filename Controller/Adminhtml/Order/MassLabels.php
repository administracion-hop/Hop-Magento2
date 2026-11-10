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
use Magento\Framework\Filesystem;
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
     * @var Filesystem
     */
    private Filesystem $filesystem;

    /**
     * @param Context $context
     * @param Filter $filter
     * @param OrderGridCollectionFactory $gridCollectionFactory La grilla, para respetar su orden y sus filtros
     * @param LabelPdf $labelPdf
     * @param FileFactory $fileFactory
     * @param BookmarkManagementInterface $bookmarkManagement
     * @param LoggerInterface $logger
     * @param Filesystem $filesystem
     */
    public function __construct(
        Context $context,
        Filter $filter,
        OrderGridCollectionFactory $gridCollectionFactory,
        LabelPdf $labelPdf,
        FileFactory $fileFactory,
        BookmarkManagementInterface $bookmarkManagement,
        LoggerInterface $logger,
        Filesystem $filesystem
    ) {
        parent::__construct($context);
        $this->filter = $filter;
        $this->gridCollectionFactory = $gridCollectionFactory;
        $this->labelPdf = $labelPdf;
        $this->fileFactory = $fileFactory;
        $this->bookmarkManagement = $bookmarkManagement;
        $this->logger = $logger;
        $this->filesystem = $filesystem;
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
        $included = [];
        $withoutLabel = [];
        $zpl = [];
        $failed = [];

        foreach ($collection as $order) {
            $increment = (string)$order->getIncrementId();
            $urls = $this->labelPdf->getLabelUrls((int)$order->getEntityId());

            if (!$urls) {
                $withoutLabel[] = $increment;
                continue;
            }

            // ZPL es para impresora de etiquetas y no entra en un PDF. No es un error: se informa
            // aparte para que nadie salga a revisar una etiqueta que está bien.
            if (array_filter($urls, [$this->labelPdf, 'isZpl'])) {
                $zpl[] = $increment;
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
                $included[] = $increment;
            }
        }

        // Sin PDF no hay nada que ofrecer, pero el motivo importa: "ninguno tiene etiqueta" cuando
        // en realidad estaban ilegibles o eran ZPL manda a revisar los pedidos equivocados. Por eso
        // cada grupo que quedó afuera lleva su propio aviso.
        if (!$pdfs) {
            $this->messageManager->addErrorMessage(
                __('No se generó ningún PDF: ninguno de los pedidos seleccionados tiene una etiqueta Hop que se pueda unir.')
            );
            $this->addLeftOutMessages($withoutLabel, $zpl, $failed);
            return $resultRedirect;
        }

        try {
            $pdf = $this->labelPdf->merge($pdfs);

            if (!$withoutLabel && !$zpl && !$failed) {
                return $this->fileFactory->create(
                    'etiquetas-hop-' . date('Ymd-His') . '.pdf',
                    $pdf,
                    DirectoryList::VAR_DIR,
                    'application/pdf'
                );
            }

            // Con pedidos afuera no se descarga directo: la descarga no recarga la grilla y los
            // avisos de qué quedó afuera recién se verían en la próxima navegación, cuando el PDF
            // ya se imprimió como si estuviera completo. Se guarda y se ofrece junto a los avisos.
            // El token identifica este lote: sin él, dos lotes seguidos del mismo usuario comparten
            // archivo y el link del primero termina bajando las etiquetas del segundo.
            $userId = (int)$this->_auth->getUser()->getId();
            $token = bin2hex(random_bytes(8));
            $varDir = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);

            // Queda un solo PDF pendiente por usuario: no se acumulan etiquetas con datos de
            // clientes en var/, y el link del lote anterior avisa que ya no está en vez de
            // entregar otra cosa.
            foreach ($varDir->search(MassLabelsDownload::pendingPattern($userId)) as $stale) {
                $varDir->delete($stale);
            }

            $varDir->writeFile(MassLabelsDownload::pendingPath($userId, $token), $pdf);
        } catch (\Exception $e) {
            $this->logger->error('Error armando el PDF de etiquetas Hop', ['error' => $e->getMessage()]);
            $this->messageManager->addErrorMessage(
                __('Se produjo un error al armar el PDF de etiquetas: %1', $e->getMessage())
            );
            return $resultRedirect;
        }

        $this->messageManager->addComplexSuccessMessage('hopLabelsDownload', [
            'url' => $this->getUrl('hop/order/massLabelsDownload', ['token' => $token]),
            'count' => count($included)
        ]);
        $this->addLeftOutMessages($withoutLabel, $zpl, $failed);

        return $resultRedirect;
    }

    /**
     * Un aviso por cada grupo de pedidos que quedó fuera del PDF, con el motivo.
     *
     * @param string[] $withoutLabel Sin etiqueta en Hop todavía
     * @param string[] $zpl Con etiqueta ZPL, que no se puede unir a un PDF
     * @param string[] $failed Con etiqueta que no se pudo bajar o abrir
     * @return void
     */
    private function addLeftOutMessages(array $withoutLabel, array $zpl, array $failed): void
    {
        if ($withoutLabel) {
            $this->messageManager->addNoticeMessage(
                __(
                    '%1 pedido(s) sin etiqueta Hop, omitidos: %2',
                    count($withoutLabel),
                    implode(', ', $withoutLabel)
                )
            );
        }

        if ($zpl) {
            $this->messageManager->addNoticeMessage(
                __(
                    '%1 pedido(s) con etiqueta ZPL (impresora de etiquetas): se descargan desde el pedido, no entran en el PDF: %2',
                    count($zpl),
                    implode(', ', $zpl)
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
