<?php
namespace Hop\Envios\Api;

/**
 * Interface PickupPointManagementInterface
 *
 * @version 1.0.0
 * @author Hop Envíos <https://hopenvios.com.ar>
 * @copyright Copyright (c) 2025 Hop Envíos
 * @package Hop\Envios\Api
 */
interface PickupPointManagementInterface
{
    /**
     * Return hop points.
     *
     * @param string $zipCode
     * @param string|null $countryCode
     * @return string
     */
    public function get($zipCode, $countryCode = null);

    /**
     * Saves the selected pickup point data in session and triggers rate recalculation.
     *
     * @return string
     */
    public function estimate();

    /**
     * Returns the currently selected pickup point for the active quote.
     *
     * @api
     * @return string JSON with hopPointId, hopPointPostcode and hopPointDescription, or null
     */
    public function getSelectedPoint();
}
