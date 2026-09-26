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

    public function testIndexesHoldAtMostSixteenColumns(): void
    {
        foreach (array('cases', 'devices') as $index) {
            $this->assertLessThanOrEqual(16, count(kirjuri_fulltext_columns($index)), $index);
        }
    }
}
