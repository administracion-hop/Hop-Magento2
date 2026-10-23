<?php

declare(strict_types=1);

namespace Hop\Envios\Model\Config\Source;

use Hop\Envios\Model\OrderGrid\DispatchStatusExpression as Status;

/**
 * Opciones de la columna/filtro "Despacho Hop" de la grilla de pedidos.
 *
 * @version 1.0.0
 * @author Hop Envíos <https://hopenvios.com.ar>
 * @copyright Copyright (c) 2025 Hop Envíos
 * @package Hop\Envios\Model\Config\Source
 */
class HopDispatchStatus implements \Magento\Framework\Data\OptionSourceInterface
{
    /**
     * @return array[]
     */
    public function toOptionArray()
    {
        return [
            ['value' => Status::DISPATCHED, 'label' => __('Despachado')],
            ['value' => Status::PENDING, 'label' => __('No despachado')],
            ['value' => Status::NOT_APPLICABLE, 'label' => __('No aplica')],
        ];
    }
}
