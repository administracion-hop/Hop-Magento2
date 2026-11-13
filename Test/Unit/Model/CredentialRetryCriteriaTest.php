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
            'completo (facturado y con su envío de Magento)' => [Order::STATE_COMPLETE, 2, true],
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

    public function testHistoricalPatchAlsoExcludesComplete(): void
    {
        $where = [];
        $select = $this->createMock(\Magento\Framework\DB\Select::class);
        $select->method('where')->willReturnCallback(function ($condition, $value = null) use (&$where, $select) {
            $where[$condition] = $value;
            return $select;
        });

        CredentialRetryCriteria::apply($select);
        $this->assertNotContains(Order::STATE_COMPLETE, $where['o.state NOT IN (?)'], 'un fallo nuevo suele quedar en un pedido completo');

        CredentialRetryCriteria::apply($select, null, true);
        $this->assertContains(Order::STATE_COMPLETE, $where['o.state NOT IN (?)'], 'un pedido viejo completo pudo resolverse por fuera de Hop');
    }
}
