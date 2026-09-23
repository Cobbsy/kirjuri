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

        $this->assertSame(200, $admin->get('statistics.php')->status);
        $this->assertNull(kirjuri_statistics($db, 1999, $units));
        $this->assertSame('index.php', $admin->get('statistics.php?year=1999')->location());
    }
}
