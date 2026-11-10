<?php

namespace Hop\Envios\Model\PickupPoint;

/**
 * Builds a pickup point's opening hours text exactly as the checkout's point list does
 * (generarContenidoLista() in view/frontend/web/js/view/hop.js), which is what ends up in
 * the order's shipping description.
 */
class ScheduleFormatter
{
    /**
     * @param array $schedules Hop schedules: day_code, day_description, hour_from, hour_to
     * @return string
     */
    public function format(array $schedules): string
    {
        $schedules = array_values(array_map(function ($schedule) {
            return (array)$schedule;
        }, $schedules));
        usort($schedules, function ($a, $b) {
            return ($a['day_code'] ?? null) <=> ($b['day_code'] ?? null);
        });

        $text = '';
        $sameHours = [];
        $otherHours = [];
        foreach ($schedules as $i => $schedule) {
            if ($i === 0) {
                $text .= $schedule['day_description'] . ', ' . $this->hour($schedule['hour_from'])
                    . ' a ' . $this->hour($schedule['hour_to']);
                $sameHours[] = $schedule;
                continue;
            }

            $text .= ' - ' . $schedule['day_description'] . ' de ' . $this->hour($schedule['hour_from'])
                . ' a ' . $this->hour($schedule['hour_to']);

            $previous = $schedules[$i - 1];
            if ($schedule['hour_from'] === $previous['hour_from'] && $schedule['hour_to'] === $previous['hour_to']) {
                $sameHours[] = $schedule;
            } else {
                $otherHours[] = $schedule;
            }
        }

        if (count($sameHours) > 1) {
            $first = $sameHours[0];
            $last = end($sameHours);
            $text = $first['day_description'] . ' a ' . $last['day_description'] . ' de '
                . $this->hour($first['hour_from']) . ' a ' . $this->hour($first['hour_to']) . '. ';
            foreach ($otherHours as $schedule) {
                $text .= $schedule['day_description'] . ' de ' . $this->hour($schedule['hour_from'])
                    . ' a ' . $this->hour($schedule['hour_to']);
            }
        }

        return $text;
    }

    /**
     * "07:00:00" -> "07:00", like the JS slice(0, -3).
     *
     * @param string|null $hour
     * @return string
     */
    private function hour($hour): string
    {
        return substr((string)$hour, 0, -3);
    }
}
