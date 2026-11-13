<?php

namespace Kirjuri\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** kirjuri_fulltext_query() turns a search as typed into full text syntax that InnoDB accepts. */
final class FulltextQueryTest extends TestCase
{
    public static function searches(): array
    {
        return array(
            'plain words' => array('john doe', 'john doe'),
            'operators kept' => array('+suspect -mailbox zqab*', '+suspect -mailbox zqab*'),
            'e-mail address' => array('john.doe@example.com', '"john doe example com"'),
            'IMEI with dashes' => array('35-209900-176148-1', '"35 209900 176148 1"'),
            'hyphenated word' => array('e-mail', '"e mail"'),
            'required e-mail address' => array('+john@example.com', '+"john example com"'),
            'quoted phrase' => array('"stolen phone"', '"stolen phone"'),
            'excluded phrase with punctuation' => array('-"e-mail address"', '-"e mail address"'),
            'unclosed quote' => array('"stolen phone', '"stolen phone"'),
            'apostrophe inside a word' => array("O'Brien", "O'Brien"),
            'letters beyond ASCII' => array('Pihlajamäki Ødegård', 'Pihlajamäki Ødegård'),
            'operators alone' => array('+ - * ~ <> @ () ""', ''),
            'doubled operators' => array('+-x a** ~y <z >w (v)', '+x a* y z w v'),
            'empty' => array('', ''),
        );
    }

    #[DataProvider('searches')]
    public function testSearchIsRewritten(string $search, string $expected): void
    {
        $this->assertSame($expected, kirjuri_fulltext_query($search));
    }

    public static function wordBeginningSearches(): array
    {
        return array(
            'plain words' => array('john doe', 'john* doe*'),
            'operators kept, no doubled star' => array('+suspect -mailbox zqab*', '+suspect* -mailbox* zqab*'),
            'phrases stay exact' => array('"stolen phone" iphone', '"stolen phone" iphone*'),
            'punctuation makes a phrase' => array('john.doe@example.com', '"john doe example com"'),
            'letters beyond ASCII' => array('Pihlajamäki', 'Pihlajamäki*'),
            'operators alone' => array('+ - * ~', ''),
        );
    }

    #[DataProvider('wordBeginningSearches')]
    public function testWordBeginningsAddAStarToEverySingleWord(string $search, string $expected): void
    {
        $this->assertSame($expected, kirjuri_fulltext_query($search, true));
    }

    public function testRowsAreCountedPerYearNewestFirst(): void
    {
        $rows = array(
            array('case_added_date' => '2024-03-01 10:00:00'),
            array('case_added_date' => '2026-01-01 00:00:00'),
            array('case_added_date' => '2024-12-31 23:59:59'),
            array('case_added_date' => null),
        );
        $this->assertSame(array(2026 => 1, 2024 => 2), kirjuri_rows_per_year($rows));
    }

    public function testFoundDevicesCountInTheirRequestsYear(): void
    {
        $rows = array(
            array('id' => '1', 'parent_id' => '1', 'case_added_date' => '2019-05-06 07:08:09'),
            array('id' => '2', 'parent_id' => '1', 'case_added_date' => '2026-01-02 00:00:00'), // Added later.
            array('id' => '3', 'parent_id' => '9', 'case_added_date' => '2026-01-02 00:00:00'), // Its request was not found.
        );
        $this->assertSame(array(2021 => 1, 2019 => 2), kirjuri_rows_per_year($rows, array('9' => array('id' => '9', 'case_added_date' => '2021-02-03 00:00:00'))));
    }

    public function testIndexesHoldAtMostSixteenColumns(): void
    {
        foreach (array('cases', 'devices') as $index) {
            $this->assertLessThanOrEqual(16, count(kirjuri_fulltext_columns($index)), $index);
        }
    }
}
