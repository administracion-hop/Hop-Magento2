<?php

declare(strict_types=1);

namespace Hop\Envios\Test\Unit\Model\Config\Source;

use Hop\Envios\Model\Config\Source\HopDispatchStatus;
use Hop\Envios\Model\OrderGrid\DispatchStatusExpression as Status;
use PHPUnit\Framework\TestCase;

class HopDispatchStatusTest extends TestCase
{
    public function testOffersEveryValueOfTheColumn(): void
    {
        $options = [];
        foreach ((new HopDispatchStatus())->toOptionArray() as $option) {
            $options[$option['value']] = (string)$option['label'];
        }

        $this->assertSame([
            Status::DISPATCHED => 'Despachado',
            Status::PARTIAL => 'Parcial',
            Status::EXISTS_IN_HOP => 'Ya existe en Hop',
            Status::FAILED => 'Error al despachar',
            Status::PENDING => 'No despachado',
            Status::NOT_APPLICABLE => 'No aplica',
        ], $options);
    }
}
