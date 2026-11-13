<?php

namespace Kirjuri\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ToolReservationsTest extends TestCase
{
    public static function overlaps(): array
    {
        // Existing reservation: 10:00 to 12:00.
        return array(
            'same time' => array('10:00', '12:00', true),
            'starts inside' => array('11:00', '13:00', true),
            'ends inside' => array('09:00', '11:00', true),
            'inside' => array('10:30', '11:30', true),
            'covers it' => array('09:00', '13:00', true),
            'same start, longer' => array('10:00', '13:00', true),
            'same end, earlier start' => array('09:00', '12:00', true),
            'ends when it starts' => array('08:00', '10:00', false),
            'starts when it ends' => array('12:00', '14:00', false),
            'before' => array('07:00', '09:00', false),
            'after' => array('13:00', '14:00', false),
        );
    }

    #[DataProvider('overlaps')]
    public function testOverlap(string $start, string $end, bool $expected): void
    {
        $at = fn ($time) => strtotime('2030-01-01 ' . $time);
        $this->assertSame($expected, kirjuri_reservations_overlap($at($start), $at($end), $at('10:00'), $at('12:00')));
    }

    public function testFindsTheFirstConflict(): void
    {
        $reservations = array(
            3 => array('reserve_start' => '2030-01-01 08:00', 'reserve_end' => '2030-01-01 09:00', 'reserved_for' => 'A'),
            7 => array('reserve_start' => '2030-01-01 10:00', 'reserve_end' => '2030-01-01 12:00', 'reserved_for' => 'B'),
        );
        $this->assertSame(7, kirjuri_find_reservation_conflict($reservations, '2030-01-01 11:00', '2030-01-01 11:30'));
        $this->assertNull(kirjuri_find_reservation_conflict($reservations, '2030-01-01 09:00', '2030-01-01 10:00'));
        $this->assertNull(kirjuri_find_reservation_conflict(array(), '2030-01-01 09:00', '2030-01-01 10:00'));
    }

    public function testSortsByStartAndRenumbers(): void
    {
        $sorted = kirjuri_sort_reservations(array(
            5 => array('reserve_start' => '2030-02-01 08:00'),
            2 => array('reserve_start' => '2030-01-01 08:00'),
        ));
        $this->assertSame(array(0, 1), array_keys($sorted));
        $this->assertSame('2030-01-01 08:00', $sorted[0]['reserve_start']);
    }

    public function testReservationsBelongToTheirUsernameAndOldOnesToTheName(): void
    {
        $user = array('username' => 'mk', 'name' => 'Maija Renamed', 'access' => '1');
        $this->assertTrue(kirjuri_reservation_belongs_to(array('username' => 'mk', 'reserved_for' => 'Maija Old'), $user), 'A rename keeps the reservation.');
        $this->assertFalse(kirjuri_reservation_belongs_to(array('username' => 'other', 'reserved_for' => 'Maija Renamed'), $user), 'A username is not overridden by the name.');
        $this->assertTrue(kirjuri_reservation_belongs_to(array('reserved_for' => 'Maija Renamed'), $user), 'An old reservation matches by name.');
        $this->assertFalse(kirjuri_reservation_belongs_to(array('reserved_for' => 'Maija Old'), $user));
    }

    public function testPresentedReservationsShowTheCurrentNameAndWhoMayDropThem(): void
    {
        $users = array(array('username' => 'mk', 'name' => 'Maija Renamed'), array('username' => 'admin', 'name' => 'Administrator'));
        $reservations = array(
            array('username' => 'mk', 'reserved_for' => 'Maija Old'),
            array('username' => 'gone', 'reserved_for' => 'Left the lab'),
            'not a reservation',
        );
        $shown = kirjuri_present_reservations($reservations, $users, array('username' => 'mk', 'name' => 'Maija Renamed', 'access' => '1'));
        $this->assertSame(array(0, 1), array_keys($shown));
        $this->assertSame(array('Maija Renamed', true), array($shown[0]['name'], $shown[0]['can_drop']));
        $this->assertSame(array('Left the lab', false), array($shown[1]['name'], $shown[1]['can_drop']));
        $byAdmin = kirjuri_present_reservations($reservations, $users, array('username' => 'admin', 'name' => 'Administrator', 'access' => '0'));
        $this->assertTrue($byAdmin[1]['can_drop']);
    }
}
