<?php
namespace Hop\Envios\Api;

/**
 * Interface PointsInterface
 *
 * @version 1.0.0
 * @author Hop Envíos <https://hopenvios.com.ar>
 * @copyright Copyright (c) 2025 Hop Envíos
 * @package Hop\Envios\Api
 */
interface PointsInterface
{
    /**
     * Return hop points.
     *
     * @param string $zipCode
     * @param string $countryCode
     * @return string
     */
    public function get($zipCode, $countryCode);

    /**
     * @return string
     */
    public function estimate();
}
