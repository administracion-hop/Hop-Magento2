<?php

namespace Hop\Envios\Model;

class EcomStatuses
{
    public const STATUSES = [
        'E_CON' => 'Pendiente de ingreso',
        'E_DEP' => 'Paquete recibido',
        'E_DIS' => 'En distribución',
        'E_LIS' => 'Listo para retirar',
        'E_ENT' => 'Entrega confirmada',
        'E_VEN' => 'Plazo de entrega vencido para devolución',
        'E_ICDD' => 'Inicio devolución',
        'E_EDE' => 'Devolución en proceso',
        'E_DEV' => 'Devuelto',
        'E_RESC' => 'En devolución (rescate)',
        'E_ANU' => 'En devolución (cancelado)',
        'E_ROT' => 'Rotura',
        'E_NDEST' => 'En distribución',
    ];
}
