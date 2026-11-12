<?php

namespace Hop\Envios\Model\Health;

use Hop\Envios\Helper\Data as HelperHop;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Agrupa las vistas de tienda por cuenta de Hop: mismas credenciales y mismo modo (prueba o
 * productivo). Las credenciales se pueden cargar por vista de tienda, así que dos vistas pueden
 * compartir cuenta o no (mismo criterio que Cron/LoadPoints, que agrupa por client_id).
 *
 * La clave de la cuenta es un HMAC con la clave de la instalación: identifica la cuenta sin
 * guardar ni loguear las credenciales.
 */
class AccountResolver
{
    /**
     * @var HelperHop
     */
    private $helper;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var EncryptorInterface
     */
    private $encryptor;

    /**
     * @param HelperHop $helper
     * @param StoreManagerInterface $storeManager
     * @param EncryptorInterface $encryptor
     */
    public function __construct(
        HelperHop $helper,
        StoreManagerInterface $storeManager,
        EncryptorInterface $encryptor
    ) {
        $this->helper = $helper;
        $this->storeManager = $storeManager;
        $this->encryptor = $encryptor;
    }

    /**
     * Clave de la cuenta a partir de sus datos, o null si no hay client_id.
     *
     * @param string|null $clientId
     * @param string|null $clientSecret
     * @param string|null $email
     * @param string|null $password
     * @param bool $productivo
     * @return string|null
     */
    public function buildKey($clientId, $clientSecret, $email, $password, $productivo)
    {
        if ((string)$clientId === '') {
            return null;
        }
        $data = json_encode([
            (string)$clientId,
            (string)$clientSecret,
            (string)$email,
            (string)$password,
            (bool)$productivo,
        ]);
        return 'acct:' . $this->encryptor->hash($data);
    }

    /**
     * @param int $storeId
     * @return string|null
     */
    public function getAccountKey($storeId)
    {
        return $this->buildKey(
            $this->helper->getClientId($storeId),
            $this->helper->getClientSecret($storeId),
            $this->helper->getEmail($storeId),
            $this->helper->getPassword($storeId),
            $this->helper->getProductivo($storeId)
        );
    }

    /**
     * Vistas de tienda con Hop habilitado. Las demás no se revisan.
     *
     * @return int[]
     */
    public function getActiveStoreIds()
    {
        $ids = [];
        foreach ($this->storeManager->getStores() as $store) {
            if ($store->getIsActive() && $this->helper->isActive($store->getId())) {
                $ids[] = (int)$store->getId();
            }
        }
        return $ids;
    }

    /**
     * Cuentas de las vistas de tienda con Hop habilitado: clave => vistas de tienda.
     *
     * @param int[]|null $storeIds limitar a estas vistas de tienda
     * @return array<string, int[]>
     */
    public function getAccounts(?array $storeIds = null)
    {
        $accounts = [];
        foreach ($this->getActiveStoreIds() as $storeId) {
            if ($storeIds !== null && !in_array($storeId, $storeIds, true)) {
                continue;
            }
            $key = $this->getAccountKey($storeId);
            if ($key !== null) {
                $accounts[$key][] = $storeId;
            }
        }
        return $accounts;
    }

    /**
     * Todas las vistas de tienda (con Hop habilitado o no) que usan esta cuenta. Se usa para
     * reintentar pedidos: un pedido de una vista donde hoy Hop está deshabilitado igual se
     * despachó con esa cuenta.
     *
     * @param string $accountKey
     * @return int[]
     */
    public function getStoreIdsForAccount($accountKey)
    {
        $ids = [];
        foreach ($this->storeManager->getStores() as $store) {
            if ($this->getAccountKey($store->getId()) === $accountKey) {
                $ids[] = (int)$store->getId();
            }
        }
        return $ids;
    }
}
