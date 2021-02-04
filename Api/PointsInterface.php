<?php
namespace Improntus\Hop\Api;

/**
 * Interface PointsInterface
 *
 * @version 1.0.0
 * @author Improntus <http://www.improntus.com> - Ecommerce done right
 * @copyright Copyright (c) 2021 Improntus
 * @package Improntus\Hop\Api
 */
interface PointsInterface
{
    /**
     * Return hop points.
     *
     * @return string
     */
    public function get();

    /**
     * @return string
     */
    public function estimate();
}