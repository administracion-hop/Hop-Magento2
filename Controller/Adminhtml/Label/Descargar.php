<?php

namespace Improntus\Hop\Controller\Adminhtml\Label;

use Magento\Backend\App\Action;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Controller\ResultFactory;

/**
 * Class Descargar
 *
 * @version 1.0.0
 * @author Improntus <http://www.improntus.com> - Ecommerce done right
 * @copyright Copyright (c) 2021 Improntus
 * @package Improntus\Hop\Controller\Adminhtml\Label
 */
class Descargar extends \Magento\Backend\App\Action
{
    /**
     * @var PageFactory
     */
    protected $_resultPageFactory;

    /**
     * @var \Magento\Framework\Controller\ResultFactory
     */
    protected $_resultRedirect;

    /**
     * @var \Magento\Framework\Message\ManagerInterface
     */
    protected $messageManager;

    /**
     * @var \Magento\Framework\Filesystem
     */
    protected $_filesystem;

    /**
     * @var
     */
    protected $_improntusHopFactory;

    /**
     * Descargar constructor.
     * @param Action\Context $context
     * @param ResultFactory $resultFactory
     * @param \Magento\Framework\Message\ManagerInterface $manager
     * @param \Magento\Framework\Filesystem $filesystem
     */
    public function __construct
    (
        Action\Context $context,
        \Magento\Framework\Controller\ResultFactory $resultFactory,
        \Magento\Framework\Message\ManagerInterface $manager,
        \Magento\Framework\Filesystem $filesystem,
        \Improntus\Hop\Model\ImprontusHopFactory $improntusHopFactory
    )
    {
        $this->_resultRedirect = $resultFactory;
        $this->messageManager = $manager;
        $this->_filesystem = $filesystem;
        $this->_improntusHopFactory = $improntusHopFactory;

        parent::__construct($context);
    }

    /**
     * @return \Magento\Framework\App\ResponseInterface|\Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        $request = $this->getRequest();
        $orderId = $request->getParam('order_id');

        try
        {
            $improntusHop = $this->_improntusHopFactory->create();
            $improntusHop = $improntusHop->getCollection()
                ->addFieldToFilter('order_id', ['eq' => $orderId])
                ->getFirstItem();

            if (count($improntusHop->getData()) > 0)
            {
                $infoHop = $improntusHop->getInfoHop();
                $infoHop = json_decode($infoHop);
                $url = isset($infoHop->label_url) ? $infoHop->label_url : '';
                $filenameArr = explode('/', $url);
                $filename = $filenameArr[count($filenameArr) - 1];
                $filename = trim($filename, '-');
            }else
            {
                $logger->info("MENZE B");
                $url = '';
            }

            if(!empty($url)){

                $mediapath = $this->_filesystem->getDirectoryRead(DirectoryList::MEDIA)->getAbsolutePath() . 'Hop/';

                if (!file_exists($mediapath) || !is_dir($mediapath))
                {
                    mkdir("{$mediapath}", 0775,true);
                }

                $filesize = -1;

                $curl = curl_init($url);

                curl_setopt( $curl, CURLOPT_NOBODY, true );
                curl_setopt( $curl, CURLOPT_HEADER, true );
                curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true );
                curl_setopt( $curl, CURLOPT_FOLLOWLOCATION, true );

                $data = curl_exec($curl);
                $filesize =  curl_getinfo($curl, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
                curl_close($curl);

                header('Content-Description: File Transfer');
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename='.$filename);
                header('Content-Transfer-Encoding: binary');
                header('Expires: 0');
                header('Cache-Control: must-revalidate');
                header('Pragma: public');
                header('Content-Length: ' . $filesize);
                ob_clean();
                flush();
                readfile($url);
                return;
            }
            else{
                $this->messageManager->addErrorMessage(__('Se produjo un error al descargar la etiqueta de Hop. Por favor intentelo nuevamente'));
            }
        }
        catch (\Exception $e)
        {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        $resultRedirect = $this->_resultRedirect->create(ResultFactory::TYPE_REDIRECT);
        $resultRedirect->setUrl($this->_redirect->getRefererUrl());

        return $resultRedirect;
    }
}


