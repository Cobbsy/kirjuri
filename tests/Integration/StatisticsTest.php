<?php

namespace Kirjuri\Tests\Integration;

final class StatisticsTest extends IntegrationTestCase
{
    public function testFiguresCountTheYearsCasesAndDevicesPerUnit(): void
    {
        $db = $this->server->pdo();
        $units = array('Unit 1', 'Unit 2', 'Unit 3');
        $before = kirjuri_statistics($db, date('Y'), $units) ?? array('count_new' => 0, 'count_phones' => 0, 'device_count_by_unit' => array_fill_keys($units, 0), 'device_data_by_unit' => array_fill_keys($units, 0), 'count_alldevs' => 0);

        $admin = $this->admin();
        $first = $this->createCase($admin, $this->uniqueName('Stats A '), array('case_investigator_unit' => 'Unit 2', 'case_contains_mob_dev' => '1'));
        $second = $this->createCase($admin, $this->uniqueName('Stats B '), array('case_investigator_unit' => 'Unit 3', 'case_contains_mob_dev' => '0'));
        $this->addDevice($admin, $first, $this->uniqueName('A'), array('device_size_in_gb' => '100'));
        $this->addDevice($admin, $first, $this->uniqueName('B'), array('device_size_in_gb' => '28'));
        $removed = $this->addDevice($admin, $second, $this->uniqueName('C'), array('device_size_in_gb' => '500'));
        $admin->post("submit.php?type=set_removed&uid=$removed&returnid=$second", array('token' => $this->token($admin), 'ct' => $this->caseToken($admin, $second)));

        $after = kirjuri_statistics($db, date('Y'), $units);
        $this->assertSame($before['count_new'] + 2, $after['count_new']);
        $this->assertSame($before['count_phones'] + 1, $after['count_phones']);
        $this->assertSame($before['count_alldevs'] + 2, $after['count_alldevs'], 'Removed devices are not counted.');
        $this->assertSame($before['device_count_by_unit']['Unit 2'] + 2, $after['device_count_by_unit']['Unit 2']);
        $this->assertSame($before['device_data_by_unit']['Unit 2'] + 128, $after['device_data_by_unit']['Unit 2']);
        $this->assertSame($before['device_data_by_unit']['Unit 3'], $after['device_data_by_unit']['Unit 3']);
        $this->assertSame(array('Unit 1', 'Unit 2', 'Unit 3'), array_keys($after['device_data_by_unit']), 'Units keep the settings order.');

        $page = $admin->get('statistics.php');
        $this->assertSame(200, $page->status);
        // The unit card lists each unit with cases as a labelled bar with its count.
        $this->assertMatchesRegularExpression('/<span class="bar-list-label">Unit 2<\/span>\s*<span class="bar-list-value">\d+<\/span>/', $page->body);
        // Device and media types can share a name, and a type without rows hides its row by id: the media
        // rows have ids of their own, or an empty media type would hide the device type's row.
        preg_match_all('/<tr id="(solu-[^"]+)">/', $page->body, $rows);
        $this->assertNotEmpty($rows[1]);
        $this->assertSame($rows[1], array_values(array_unique($rows[1])), 'Row ids are unique.');
        $this->assertNull(kirjuri_statistics($db, 1999, $units));
    }

    public function testAYearWithoutRequestsShowsAnEmptyPageAndArrowsSkipEmptyYears(): void
    {
        // A year without requests sent the user to the front page with an error, and the year arrows led there
        // too: from the first year with requests, the left arrow went to the empty year before it.
        $pdo = $this->server->pdo();
        $insert = function (string $date) use ($pdo): int {
            $pdo->exec("INSERT INTO exam_requests (parent_id, case_id, case_name, case_status, is_removed, case_devicecount, case_added_date)
                VALUES (0, 0, 'Old year', 1, 0, 0, '$date')");
            $id = (int) $pdo->lastInsertId();
            $pdo->exec("UPDATE exam_requests SET parent_id = id WHERE id = $id");
            return $id;
        };
        $ids = array($insert('1971-06-01 12:00:00'), $insert('1974-02-01 12:00:00'));
        try {
            $this->assertSame(array('previous' => null, 'next' => 1974), kirjuri_statistics_neighbours($pdo, 1971));
            $this->assertSame(array('previous' => 1971, 'next' => 1974), kirjuri_statistics_neighbours($pdo, 1972));
            $this->assertSame(1971, kirjuri_statistics_neighbours($pdo, 1974)['previous']);

            $admin = $this->admin();
            $empty = $admin->get('statistics.php?year=1972');
            $this->assertSame(200, $empty->status, 'An empty year is shown, not redirected: ' . $empty->location());
            $this->assertStringContainsString('class="empty-state"', $empty->body);
            $this->assertStringNotContainsString('<canvas', $empty->body, 'No charts of nothing.');
            $this->assertStringContainsString("href='statistics.php?year=1971'", $empty->body);
            $this->assertStringContainsString("href='statistics.php?year=1974'", $empty->body);
            $this->assertStringNotContainsString('year=1973', $empty->body, 'The arrows skip empty years.');

            $first = $admin->get('statistics.php?year=1971');
            $this->assertSame(200, $first->status);
            $this->assertStringContainsString('<canvas', $first->body);
            $this->assertStringNotContainsString('year=1970', $first->body, 'No arrow back from the first year with requests.');
            $this->assertStringContainsString("href='statistics.php?year=1974'", $first->body);
        } finally {
            $pdo->exec('DELETE FROM exam_requests WHERE id IN (' . implode(',', $ids) . ')');
        }
    }

    public function testUnitsMatchAsTheDatabaseComparesThem(): void
    {
        // Units used to be compared in SQL, which ignores letter case and trailing spaces; grouping and
        // then looking them up in PHP counted "uNIT 1" as no unit at all.
        $db = $this->server->pdo();
        $units = array('Unit 1', 'Unit 2', 'Unit 3');
        $before = kirjuri_statistics($db, date('Y'), $units);
        $admin = $this->admin();
        $caseId = $this->createCase($admin, $this->uniqueName('Stats case '), array('case_investigator_unit' => 'uNIT 1'));
        $this->addDevice($admin, $caseId, $this->uniqueName('D'), array('device_size_in_gb' => '7'));
        $after = kirjuri_statistics($db, date('Y'), $units);
        $this->assertSame(($before['device_count_by_unit']['Unit 1'] ?? 0) + 1, $after['device_count_by_unit']['Unit 1']);
        $this->assertSame(($before['device_data_by_unit']['Unit 1'] ?? 0) + 7, $after['device_data_by_unit']['Unit 1']);
    }

    public function testMonthsEndOnTheirLastDay(): void
    {
        // Every month ended on its 31st, which PHP rolls over: "31.2." is 3 March and "31.4." is 1 May, so
        // a case added on 2 March was counted in February as well as in March.
        $pdo = $this->server->pdo();
        $pdo->exec("INSERT INTO exam_requests (parent_id, case_id, case_name, case_status, is_removed, case_devicecount, case_added_date)
            VALUES (0, 0, 'Month end', 2, 0, 5, '2013-03-02 12:00:00')");
        $caseId = (int) $pdo->lastInsertId();
        $pdo->exec("UPDATE exam_requests SET parent_id = id WHERE id = $caseId");
        for ($i = 0; $i < 5; $i++) {
            $pdo->exec("INSERT INTO exam_requests (parent_id, is_removed, device_type, case_added_date) VALUES ($caseId, 0, 'Phone', '2013-03-02 12:00:00')");
        }
        try {
            $page = $this->admin()->get('statistics.php?year=2013');
            $this->assertSame(200, $page->status);
            // The monthly chart of devices, then of requests: only March has any.
            $this->assertStringContainsString('data: [0, 0, 5, 0, 0, 0, 0, 0, 0, 0, 0, 0, ]', $page->body);
            $this->assertStringContainsString('data: [0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, ]', $page->body);
            $this->assertStringNotContainsString('data: [0, 5, 5,', $page->body);
        } finally {
            $pdo->exec("DELETE FROM exam_requests WHERE parent_id = $caseId");
        }
    }

    public function testDeviceMonthsAddUpToTheYearsTotal(): void
    {
        // The monthly device counts summed the device counts of the requests added that month, while the total
        // counted the devices added in the year: a device added in May to a March request counted in March, and
        // one added in January to last December's request in neither year's months. A year with requests but no
        // devices printed no cells at all, and its charts got no data.
        $pdo = $this->server->pdo();
        $pdo->exec("INSERT INTO exam_requests (parent_id, case_id, case_name, case_status, is_removed, case_devicecount, case_added_date)
            VALUES (0, 0, 'Device months', 2, 0, 1, '2011-03-10 12:00:00'), (0, 0, 'No devices', 2, 0, 0, '2012-04-10 12:00:00')");
        $pdo->exec("UPDATE exam_requests SET parent_id = id WHERE parent_id = 0 AND case_name IN ('Device months', 'No devices')");
        $caseId = (int) $pdo->query("SELECT id FROM exam_requests WHERE case_name = 'Device months'")->fetchColumn();
        $empty = (int) $pdo->query("SELECT id FROM exam_requests WHERE case_name = 'No devices'")->fetchColumn();
        $pdo->exec("INSERT INTO exam_requests (parent_id, is_removed, device_type, device_size_in_gb, case_added_date) VALUES ($caseId, 0, 'Phone', 64, '2011-05-20 12:00:00')");
        try {
            $statistics = kirjuri_statistics($pdo, 2011, array('Unit 1'));
            $this->assertSame(array(0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0), $statistics['devices_per_month']);
            $this->assertSame($statistics['device_count'], array_sum($statistics['devices_per_month']));
            $this->assertSame(64, $statistics['gb_per_month'][4]);
            $page = $this->admin()->get('statistics.php?year=2011')->body;
            $this->assertStringContainsString('data: [0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0, ]', $page, 'The device is in May, when it was added.');

            $none = $this->admin()->get('statistics.php?year=2012')->body;
            $this->assertStringContainsString('data: [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, ]', $none, 'Twelve months of no devices.');
            $this->assertInlineScriptsParse($none, 'statistics.php?year=2012');
        } finally {
            $pdo->exec("DELETE FROM exam_requests WHERE parent_id IN ($caseId, $empty)");
        }
    }

    public function testCasesWithoutAStatusAreCountedWithoutWarnings(): void
    {
        $pdo = $this->server->pdo();
        $pdo->exec("INSERT INTO exam_requests (parent_id, case_id, case_name, is_removed, case_added_date) VALUES (0, 0, 'No status', 0, NOW())");
        $pdo->exec('UPDATE exam_requests SET parent_id = id WHERE parent_id = 0');
        $this->assertNotNull(kirjuri_statistics($pdo, date('Y'), array('Unit 1')));
        $this->assertSame(200, $this->admin()->get('statistics.php')->status);
    }
}
