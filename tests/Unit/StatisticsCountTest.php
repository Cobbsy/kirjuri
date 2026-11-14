<?php

namespace Kirjuri\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** kirjuri_count_by(): the per-classification and per-unit counts on the statistics page. */
final class StatisticsCountTest extends TestCase
{
    public function testCountsFollowTheOrderOfTheValues(): void
    {
        $rows = array(
            array('unit' => 'Unit 2'),
            array('unit' => 'Unit 1'),
            array('unit' => 'Unit 2'),
            array('unit' => 'Unit 9'), // Not a configured unit.
            array('unit' => null),
            array(),
        );
        $this->assertSame(array('Unit 1' => 1, 'Unit 2' => 2, 'Unit 3' => 0), kirjuri_count_by($rows, 'unit', array('Unit 1', 'Unit 2', 'Unit 3')));
    }

    public function testValuesMatchExactly(): void
    {
        $rows = array(array('c' => 'Cybercrime'), array('c' => 'cybercrime'), array('c' => 'Cybercrime '));
        $this->assertSame(array('Cybercrime' => 1), kirjuri_count_by($rows, 'c', array('Cybercrime')));
    }

    public function testNumericValuesCountToo(): void
    {
        $this->assertSame(array(7 => 2), kirjuri_count_by(array(array('c' => '7'), array('c' => 7)), 'c', array('7')));
    }
}
