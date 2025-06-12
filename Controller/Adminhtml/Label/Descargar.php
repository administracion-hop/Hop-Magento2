<?php

namespace Hop\Envios\Controller\Adminhtml\Label;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Filesystem;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Message\ManagerInterface;
use Hop\Envios\Model\HopEnviosRepository;

/**
 * Class Descargar
 *
 * @version 1.0.0
 * @author Improntus <http://www.improntus.com> - Ecommerce done right
 * @copyright Copyright (c) 2021 Improntus
 * @package Hop\Envios\Controller\Adminhtml\Label
 */
class Descargar extends Action
{
    /**
     * @var PageFactory
     */
    protected $resultPageFactory;

    /**
     * @var ResultFactory
     */
    protected $resultRedirect;

    /**
     * @var ManagerInterface
     */
    protected $messageManager;

    /**
     * @var Filesystem
     */
    protected $filesystem;

    /**
     * @var HopEnviosRepository
     */
    protected $hopEnviosRepository;

    /**
     * Descargar constructor.
     * @param Context $context
     * @param ResultFactory $resultFactory
     * @param ManagerInterface $manager
     * @param Filesystem $filesystem
     * @param HopEnviosRepository $hopEnviosRepository
     */
    public function __construct
    (
        Context $context,
        ResultFactory $resultFactory,
        ManagerInterface $manager,
        Filesystem $filesystem,
        HopEnviosRepository $hopEnviosRepository
    )
    {
        $this->resultRedirect = $resultFactory;
        $this->messageManager = $manager;
        $this->filesystem = $filesystem;
        $this->hopEnviosRepository = $hopEnviosRepository;

        parent::__construct($context);
    }

    /**
     * @return \Magento\Framework\App\ResponseInterface|\Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        $request = $this->getRequest();
        $orderId = $request->getParam('order_id');

        try {
            $hopEnvios = $this->hopEnviosRepository->getByOrderId($orderId);

            if ($hopEnvios) {
                $infoHop = $hopEnvios->getInfoHop();
                $infoHop = json_decode($infoHop);
                $url = isset($infoHop->label_url) ? $infoHop->label_url : '';
                $filenameArr = explode('/', $url);
                $filename = $filenameArr[count($filenameArr) - 1];
                $filename = trim($filename, '-');
            } else {
                $url = '';
            }

            if (!empty($url)) {

                $mediapath = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA)->getAbsolutePath() . 'Hop/';

                if (!file_exists($mediapath) || !is_dir($mediapath)) {
                    mkdir("{$mediapath}", 0775, true);
                }

                $filesize = -1;

                $curl = curl_init($url);

                curl_setopt($curl, CURLOPT_NOBODY, true);
                curl_setopt($curl, CURLOPT_HEADER, true);
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);

                $data = curl_exec($curl);
                $filesize =  curl_getinfo($curl, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
                curl_close($curl);

                header('Content-Description: File Transfer');
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename=' . $filename);
                header('Content-Transfer-Encoding: binary');
                header('Expires: 0');
                header('Cache-Control: must-revalidate');
                header('Pragma: public');
                header('Content-Length: ' . $filesize);
                ob_clean();
                flush();
                readfile($url);
                return;
            } else {
                $this->messageManager->addErrorMessage(__('Se produjo un error al descargar la etiqueta de Hop. Por favor intentelo nuevamente'));
            }
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        $resultRedirect = $this->resultRedirect->create(ResultFactory::TYPE_REDIRECT);
        $resultRedirect->setUrl($this->_redirect->getRefererUrl());

        return $resultRedirect;
    }
}


