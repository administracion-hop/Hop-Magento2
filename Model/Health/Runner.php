<?php

namespace Hop\Envios\Model\Health;

use Hop\Envios\Helper\Data as HelperHop;
use Hop\Envios\Logger\LoggerInterface;
use Hop\Envios\Model\Webservice;
use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;
use Magento\Catalog\Model\Product\Type as ProductType;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;

/**
 * Revisiones contra Hop o el catálogo (1, 5 y 9). Las corre la tarea programada cada 6 horas y
 * el guardado de la configuración de Hop; el resultado queda en hop_envios_health_check y la
 * franja solo lo lee. Nunca se llaman desde la franja.
 *
 * Las credenciales (1) se revisan una vez por cuenta; la cotización de prueba (5) y las medidas
 * (9), por vista de tienda, porque dependen de su configuración.
 */
class Runner
{
    /** Que un Hop que no responde no deje colgado el guardado de la configuración. */
    const REQUEST_TIMEOUT_SECONDS = 10;

    /** Paquete de la cotización de prueba: 1 kg y valor 1000. */
    const TEST_PACKAGE_WEIGHT_GRAMS = 1000;
    const TEST_PACKAGE_VALUE = 1000;
    /** Tamaño de categoría más chico, si no hay uno configurado. */
    const SMALLEST_SIZE_CATEGORY = '1';

    /**
     * @var Webservice
     */
    private $webservice;

    /**
     * @var AccountResolver
     */
    private $accountResolver;

    /**
     * @var ResultStorage
     */
    private $storage;

    /**
     * @var HelperHop
     */
    private $helper;

    /**
     * @var ProductCollectionFactory
     */
    private $productCollectionFactory;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var CredentialState
     */
    private $credentialState;

    /**
     * @param Webservice $webservice
     * @param AccountResolver $accountResolver
     * @param ResultStorage $storage
     * @param HelperHop $helper
     * @param ProductCollectionFactory $productCollectionFactory
     * @param LoggerInterface $logger
     * @param CredentialState $credentialState
     */
    public function __construct(
        Webservice $webservice,
        AccountResolver $accountResolver,
        ResultStorage $storage,
        HelperHop $helper,
        ProductCollectionFactory $productCollectionFactory,
        LoggerInterface $logger,
        CredentialState $credentialState
    ) {
        $this->webservice = $webservice;
        $this->accountResolver = $accountResolver;
        $this->storage = $storage;
        $this->helper = $helper;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->logger = $logger;
        $this->credentialState = $credentialState;
    }

    /**
     * @param int[]|null $storeIds null = todas las vistas de tienda con Hop habilitado
     * @return void
     */
    public function run(?array $storeIds = null)
    {
        $accounts = $this->accountResolver->getAccounts($storeIds);
        $this->webservice->setRequestTimeout(self::REQUEST_TIMEOUT_SECONDS);
        try {
            foreach ($accounts as $accountKey => $accountStoreIds) {
                try {
                    $this->runAccount($accountKey, $accountStoreIds);
                } catch (\Exception $e) {
                    $this->logger->error('Hop: error en la revisión de la cuenta: ' . $e->getMessage());
                }
            }
            // Las medidas no dependen de Hop: se revisan también en las vistas de tienda que
            // todavía no tienen una cuenta (sin credenciales cargadas).
            $withAccount = $accounts ? array_merge(...array_values($accounts)) : [];
            foreach ($this->accountResolver->getActiveStoreIds() as $storeId) {
                if (($storeIds === null || in_array($storeId, $storeIds, true)) && !in_array($storeId, $withAccount, true)) {
                    $this->checkMeasures($storeId);
                }
            }
            if ($storeIds === null) {
                $this->cleanUp(array_keys($accounts));
            }
        } finally {
            $this->webservice->setRequestTimeout(null);
        }
    }

    /**
     * @param string $accountKey
     * @param int[] $storeIds
     * @return void
     */
    private function runAccount($accountKey, array $storeIds)
    {
        $this->webservice->setStoreId(reset($storeIds));
        $login = $this->webservice->checkCredentials();

        if ($login === Webservice::LOGIN_UNREACHABLE) {
            $this->storage->save($accountKey, Checks::CONNECTION, Checks::SEVERITY_WARNING, (string)__('No se pudo verificar la conexión con Hop.'), $storeIds);
            // Sin respuesta no se sabe nada del vendedor ni se puede cotizar: se dejan los
            // resultados anteriores de esas revisiones.
            foreach ($storeIds as $storeId) {
                $this->checkMeasures($storeId);
            }
            return;
        }
        $this->storage->save($accountKey, Checks::CONNECTION, Checks::STATUS_OK, null, $storeIds);
        // 1a: la fila de credenciales la escribe solo CredentialState, desde Webservice::login().

        if ($login === Webservice::LOGIN_REJECTED) {
            // Con credenciales rechazadas no se puede saber nada más de Hop.
            $this->storage->delete($accountKey, Checks::SELLER);
            foreach ($storeIds as $storeId) {
                $this->storage->delete(Checks::storeScope($storeId), Checks::TEST_QUOTE);
                $this->checkMeasures($storeId);
            }
            return;
        }

        // 1b
        $sellerStatus = $this->webservice->getSellerStatus();
        $sellerCode = (string)$this->helper->getSellerCode(reset($storeIds));
        if ($sellerStatus === 'active') {
            $this->storage->save($accountKey, Checks::SELLER, Checks::STATUS_OK, null, $storeIds);
        } elseif ($sellerStatus === 'not_found') {
            $this->storage->save($accountKey, Checks::SELLER, Checks::SEVERITY_ERROR, (string)__('Hop no encuentra el vendedor "%1": Hop no aparece en el checkout.', $sellerCode), $storeIds);
        } elseif ($sellerStatus !== null) {
            $this->storage->save($accountKey, Checks::SELLER, Checks::SEVERITY_ERROR, (string)__('Hop informa que el vendedor "%1" no está activo (estado: %2): Hop no aparece en el checkout.', $sellerCode, $sellerStatus), $storeIds);
        }

        foreach ($storeIds as $storeId) {
            $this->checkTestQuote($storeId);
            $this->checkMeasures($storeId);
        }
    }

    /**
     * 5. Cotización de prueba desde el código postal de origen hasta el primer punto cercano.
     *
     * @param int $storeId
     * @return void
     */
    public function checkTestQuote($storeId)
    {
        $scope = Checks::storeScope($storeId);
        // Perú (ubigeo) queda para la segunda etapa.
        if ($this->helper->getStoreCountry($storeId) === 'PE') {
            $this->storage->delete($scope, Checks::TEST_QUOTE);
            return;
        }
        $this->webservice->setStoreId($storeId);

        $originZip = trim((string)$this->helper->getOriginZipcode($storeId));
        if ($originZip === '') {
            $this->storage->save($scope, Checks::TEST_QUOTE, Checks::SEVERITY_ERROR, (string)__('No hay código postal de origen configurado: Hop no puede cotizar.'), [$storeId]);
            return;
        }

        $points = $this->webservice->getPickupPoints($originZip);
        $point = !empty($points->data) && is_array($points->data) ? reset($points->data) : null;
        if (!$point || empty($point->id)) {
            if ($this->isNoResponse()) {
                return;
            }
            $this->storage->save($scope, Checks::TEST_QUOTE, Checks::SEVERITY_WARNING, (string)__(
                'No se pudo probar la cotización: Hop no tiene puntos cerca del código postal de origen %1 en el país %2.',
                $originZip,
                (string)$this->helper->getStoreCountry($storeId)
            ), [$storeId]);
            return;
        }

        $sizeCategory = $this->helper->getSizeCategory($storeId) ?: self::SMALLEST_SIZE_CATEGORY;
        $price = $this->webservice->estimatePrice(
            $originZip,
            !empty($point->zip_code) ? $point->zip_code : $originZip,
            $this->helper->getSellerCode($storeId),
            $point->id,
            'E',
            [
                'weight' => self::TEST_PACKAGE_WEIGHT_GRAMS,
                'value' => self::TEST_PACKAGE_VALUE,
                'size_category' => $sizeCategory,
            ]
        );
        if ($price === false) {
            if ($this->isNoResponse()) {
                return;
            }
            $this->storage->save($scope, Checks::TEST_QUOTE, Checks::SEVERITY_ERROR, (string)__(
                'Hop no cotizó un envío de prueba desde el código postal %1 hasta el punto %2: el checkout no va a mostrar Hop.',
                $originZip,
                !empty($point->reference_name) ? $point->reference_name : $point->id
            ), [$storeId]);
            return;
        }
        $this->storage->save($scope, Checks::TEST_QUOTE, Checks::STATUS_OK, null, [$storeId]);
    }

    /**
     * 9. Medidas: sin tamaño de categoría, Hop necesita alto, ancho y largo de cada producto.
     *
     * @param int $storeId
     * @return void
     */
    public function checkMeasures($storeId)
    {
        $scope = Checks::storeScope($storeId);
        if ($this->helper->getSizeCategory($storeId)) {
            $this->storage->save($scope, Checks::MEASURES, Checks::STATUS_OK, null, [$storeId]);
            return;
        }
        try {
            $count = $this->countProductsWithoutMeasures($storeId);
        } catch (\Exception $e) {
            // Por ejemplo, un atributo de medida configurado que ya no existe.
            $this->logger->error('Hop: no se pudieron contar los productos sin medidas: ' . $e->getMessage());
            return;
        }
        if ($count > 0) {
            $this->storage->save($scope, Checks::MEASURES, Checks::SEVERITY_WARNING, (string)__(
                'No hay tamaño de categoría configurado y %1 productos habilitados no tienen alto, ancho o largo: Hop no puede cotizarlos ni despacharlos.',
                $count
            ), [$storeId]);
            return;
        }
        $this->storage->save($scope, Checks::MEASURES, Checks::STATUS_OK, null, [$storeId]);
    }

    /**
     * Productos simples habilitados en la vista de tienda a los que les falta alguna medida
     * (vacía o 0, como la lee el carrier).
     *
     * @param int $storeId
     * @return int
     */
    public function countProductsWithoutMeasures($storeId)
    {
        $collection = $this->productCollectionFactory->create();
        $collection->setStoreId($storeId)
            ->addStoreFilter($storeId)
            ->addAttributeToFilter('type_id', ProductType::TYPE_SIMPLE)
            ->addAttributeToFilter('status', ProductStatus::STATUS_ENABLED);

        $conditions = [];
        foreach (['alto', 'ancho', 'largo'] as $measure) {
            $code = $this->helper->getMeasureCode($measure, $storeId);
            $conditions[] = ['attribute' => $code, 'null' => true];
            $conditions[] = ['attribute' => $code, 'eq' => 0];
        }
        $collection->addAttributeToFilter($conditions, null, 'left');
        return (int)$collection->getSize();
    }

    /**
     * @return bool
     */
    private function isNoResponse()
    {
        $status = (int)$this->webservice->getLastStatus();
        return $status === 0 || $status >= 500;
    }

    /**
     * Borra resultados de cuentas y vistas de tienda que ya no se revisan.
     *
     * @param string[] $accountKeys
     * @return void
     */
    private function cleanUp(array $accountKeys)
    {
        // El estado de credenciales de esas cuentas también sale de la caché: si la cuenta vuelve
        // a configurarse, el primer inicio de sesión tiene que volver a escribir su fila.
        foreach ($this->storage->loadAll() as $row) {
            if (strpos($row['scope_key'], 'acct:') === 0 && !in_array($row['scope_key'], $accountKeys, true)) {
                $this->credentialState->forget($row['scope_key']);
            }
        }
        $this->storage->deleteStaleAccounts($accountKeys);
        $activeScopes = array_map([Checks::class, 'storeScope'], $this->accountResolver->getActiveStoreIds());
        foreach ($this->storage->loadAll() as $row) {
            if (strpos($row['scope_key'], 'store:') === 0
                && in_array($row['check_code'], Checks::REMOTE, true)
                && !in_array($row['scope_key'], $activeScopes, true)
            ) {
                $this->storage->delete($row['scope_key'], $row['check_code']);
            }
        }
    }
}
