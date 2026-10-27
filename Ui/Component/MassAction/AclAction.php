<?php

declare(strict_types=1);

namespace Hop\Envios\Ui\Component\MassAction;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Ui\Component\Action;

/**
 * Acción masiva que sólo aparece si el usuario tiene el recurso ACL declarado en
 * `config/aclResource`. Magento\Ui\Component\MassAction descarta las acciones cuya
 * configuración trae `actionDisable`, así que alcanza con marcarla acá.
 *
 * @version 1.0.0
 * @author Hop Envíos <https://hopenvios.com.ar>
 * @copyright Copyright (c) 2025 Hop Envíos
 * @package Hop\Envios\Ui\Component\MassAction
 */
class AclAction extends Action
{
    /**
     * @var AuthorizationInterface
     */
    private AuthorizationInterface $authorization;

    /**
     * @param ContextInterface $context
     * @param AuthorizationInterface $authorization
     * @param array $components
     * @param array $data
     * @param array|\JsonSerializable|null $actions
     */
    public function __construct(
        ContextInterface $context,
        AuthorizationInterface $authorization,
        array $components = [],
        array $data = [],
        $actions = null
    ) {
        parent::__construct($context, $components, $data, $actions);
        $this->authorization = $authorization;
    }

    /**
     * @inheritDoc
     */
    public function getConfiguration()
    {
        $config = parent::getConfiguration();
        $acl = $config['aclResource'] ?? null;

        if ($acl && !$this->authorization->isAllowed($acl)) {
            $config['actionDisable'] = true;
        }

        return $config;
    }
}
