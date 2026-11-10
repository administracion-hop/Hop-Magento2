<?php

namespace Hop\Envios\Test\Unit\Model\PickupPoint;

use Hop\Envios\Model\PickupPoint\ScheduleFormatter;
use PHPUnit\Framework\TestCase;

class ScheduleFormatterTest extends TestCase
{
    /**
     * @param string $code
     * @param string $day
     * @param string $from
     * @param string $to
     * @return array
     */
    private static function day(string $code, string $day, string $from, string $to): array
    {
        return ['day_code' => $code, 'day_description' => $day, 'hour_from' => $from, 'hour_to' => $to];
    }

    public function testEveryDaySameHoursIsGroupedAsInCheckout(): void
    {
        $schedules = [
            self::day('6', 'Sábado', '07:00:00', '20:00:00'),
            self::day('0', 'Domingo', '07:00:00', '20:00:00'),
            self::day('3', 'Miércoles', '07:00:00', '20:00:00'),
            self::day('1', 'Lunes', '07:00:00', '20:00:00'),
        ];

        $this->assertSame('Domingo a Sábado de 07:00 a 20:00. ', (new ScheduleFormatter())->format($schedules));
    }

    public function testDifferentHoursAreAppendedAfterTheGroup(): void
    {
        $schedules = [
            self::day('2', 'Martes', '09:00:00', '13:00:00'),
            self::day('1', 'Lunes', '09:00:00', '13:00:00'),
            self::day('3', 'Miércoles', '14:00:00', '19:00:00'),
        ];

        $this->assertSame(
            'Lunes a Martes de 09:00 a 13:00. Miércoles de 14:00 a 19:00',
            (new ScheduleFormatter())->format($schedules)
        );
    }

    public function testSingleDay(): void
    {
        $this->assertSame(
            'Lunes, 08:00 a 18:00',
            (new ScheduleFormatter())->format([self::day('1', 'Lunes', '08:00:00', '18:00:00')])
        );
    }

    public function testNoSchedules(): void
    {
        $this->assertSame('', (new ScheduleFormatter())->format([]));
    }

    public function testAcceptsDecodedObjects(): void
    {
        $this->assertSame(
            'Lunes, 08:00 a 18:00',
            (new ScheduleFormatter())->format([(object)self::day('1', 'Lunes', '08:00:00', '18:00:00')])
        );
    }
}
