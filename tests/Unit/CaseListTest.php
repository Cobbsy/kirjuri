<?php

namespace Kirjuri\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The front page's status filters and their counts: kirjuri_case_standing() and kirjuri_filter_case_list(). */
final class CaseListTest extends TestCase
{
    private const STALLED_BEFORE = 1767225600; // 2026-01-01 00:00 UTC

    private static function row(int $id, string $status, array $fields = array()): array
    {
        return $fields + array(
            'id' => (string) $id,
            'parent_id' => (string) $id,
            'case_status' => $status,
            'forensic_investigator' => '',
            'phone_investigator' => '',
            'last_updated' => '2026-06-01 12:00:00',
        );
    }

    public static function standings(): array
    {
        return array(
            'new' => array(self::row(1, '1'), 'new', false),
            'open' => array(self::row(1, '2'), 'open', false),
            'ready' => array(self::row(1, '3'), 'ready', false),
            'unknown status' => array(self::row(1, '9'), '', false),
            'open with the user as forensic examiner' => array(self::row(1, '2', array('forensic_investigator' => 'Alice Examiner')), 'mine', false),
            'open with the user as mobile examiner' => array(self::row(1, '2', array('phone_investigator' => 'Alice Examiner')), 'mine', false),
            'open with someone else as examiner' => array(self::row(1, '2', array('forensic_investigator' => 'Bob Examiner')), 'open', false),
            'new with the user as examiner is still new' => array(self::row(1, '1', array('forensic_investigator' => 'Alice Examiner')), 'new', false),
            'open and not updated since the limit' => array(self::row(1, '2', array('last_updated' => '2025-06-01 12:00:00')), 'open', true),
            'mine and not updated since the limit' => array(self::row(1, '2', array('last_updated' => '2025-06-01 12:00:00', 'phone_investigator' => 'Alice Examiner')), 'mine', true),
            'new cases do not stall' => array(self::row(1, '1', array('last_updated' => '2025-06-01 12:00:00')), 'new', false),
            'ready cases do not stall' => array(self::row(1, '3', array('last_updated' => '2025-06-01 12:00:00')), 'ready', false),
            'no update time' => array(self::row(1, '2', array('last_updated' => null)), 'open', false),
        );
    }

    #[DataProvider('standings')]
    public function testCaseStanding(array $case, string $status, bool $stalled): void
    {
        $this->assertSame(array('status' => $status, 'stalled' => $stalled), kirjuri_case_standing($case, 'Alice Examiner', self::STALLED_BEFORE));
    }

    public function testAUserWithoutANameHasNoCasesOfTheirOwn(): void
    {
        // A case with no examiners would otherwise count as the nameless user's own.
        $this->assertSame('open', kirjuri_case_standing(self::row(1, '2'), '', self::STALLED_BEFORE)['status']);
    }

    public function testNothingStallsWithoutALimit(): void
    {
        $this->assertFalse(kirjuri_case_standing(self::row(1, '2', array('last_updated' => '2000-01-01 00:00:00')), 'Alice Examiner', null)['stalled']);
    }

    private static function list(): array
    {
        return array(
            self::row(1, '1'),
            self::row(2, '2', array('forensic_investigator' => 'Alice Examiner')),
            self::row(3, '2', array('last_updated' => '2025-06-01 12:00:00')),
            self::row(4, '3'),
            // A device found by a search, in case 3.
            self::row(5, '2', array('parent_id' => '3')),
        );
    }

    public static function filters(): array
    {
        return array(
            'no filter' => array('', array('1', '2', '3', '4', '5')),
            'new' => array('1', array('1')),
            'open, including the user\'s own' => array('2', array('2', '3', '5')),
            'the user\'s own' => array('4', array('2')),
            'stalled' => array('5', array('3')),
            'ready' => array('3', array('4')),
            'unknown filter shows all' => array('9', array('1', '2', '3', '4', '5')),
        );
    }

    #[DataProvider('filters')]
    public function testFilterKeepsTheMatchingRows(string $filter, array $ids): void
    {
        [$rows] = kirjuri_filter_case_list(self::list(), $filter, 'Alice Examiner', self::STALLED_BEFORE);
        $this->assertSame($ids, array_column($rows, 'id'));
    }

    public function testCountsAreForCasesWhateverTheFilter(): void
    {
        $expected = array('all' => 4, 'new' => 1, 'open' => 2, 'mine' => 1, 'stalled' => 1, 'ready' => 1);
        foreach (array('', '1', '5') as $filter) {
            [, $counts] = kirjuri_filter_case_list(self::list(), $filter, 'Alice Examiner', self::STALLED_BEFORE);
            $this->assertSame($expected, $counts, 'Filter ' . $filter);
        }
    }

    public function testRowsCarryTheirStanding(): void
    {
        [$rows] = kirjuri_filter_case_list(self::list(), '', 'Alice Examiner', self::STALLED_BEFORE);
        $this->assertSame(array('new', 'mine', 'open', 'ready', 'open'), array_column($rows, 'standing'));
        $this->assertSame(array(false, false, true, false, false), array_column($rows, 'stalled'));
    }
}
