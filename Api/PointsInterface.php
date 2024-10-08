<?php
namespace Hop\Envios\Api;

/**
 * Interface PointsInterface
 *
 * @version 1.0.0
 * @author Improntus <http://www.improntus.com> - Ecommerce done right
 * @copyright Copyright (c) 2021 Improntus
 * @package Hop\Envios\Api
 */
interface PointsInterface
{
    /**
     * Return hop points.
     *
     * @param string $zipCode
     * @return string
     */
    public function get($zipCode);

    /**
     * @return string
     */
    public function estimate();
}
