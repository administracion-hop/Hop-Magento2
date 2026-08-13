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
     * $regionId/$provincia/$distrito are optional overrides sent by the frontend when it
     * already has the live-typed address on hand (quote.shippingAddress()), since the
     * checkout session's quote address is not yet persisted at this point in the flow.
     *
     * @param string $zipCode
     * @param string|null $countryCode
     * @param int|null $regionId
     * @param string|null $provincia
     * @param string|null $distrito
     * @return string
     */
    public function get($zipCode, $countryCode = null, $regionId = null, $provincia = null, $distrito = null);

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
