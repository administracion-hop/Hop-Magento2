<?php

namespace Hop\Envios\Test\Unit\Model;

use Hop\Envios\Model\CredentialRetryCriteria;
use Magento\Framework\DataObject;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;

/**
 * Qué pedidos fallidos por credenciales se reintentan solos.
 */
class CredentialRetryCriteriaTest extends TestCase
{
    /**
     * @return array [estado, días de antigüedad, se reintenta]
     */
    public static function orders(): array
    {
        return [
            'pendiente de hace 2 días' => [Order::STATE_NEW, 2, true],
            'en proceso de hace 29 días' => [Order::STATE_PROCESSING, 29, true],
            'en proceso de hace 31 días' => [Order::STATE_PROCESSING, 31, false],
            'completo (resuelto por fuera de Hop)' => [Order::STATE_COMPLETE, 2, false],
            'retenido' => [Order::STATE_HOLDED, 2, false],
            'cancelado' => [Order::STATE_CANCELED, 2, false],
            'cerrado' => [Order::STATE_CLOSED, 2, false],
        ];
    }

    /**
     * @dataProvider orders
     */
    public function testIsOrderEligible($state, $days, $expected): void
    {
        $now = strtotime('2026-11-12 12:00:00 UTC');
        $order = new DataObject(['id' => 1, 'state' => $state, 'created_at' => gmdate('Y-m-d H:i:s', $now - $days * 86400)]);
        $this->assertSame($expected, (bool)CredentialRetryCriteria::isOrderEligible($order, $now));
    }
}
