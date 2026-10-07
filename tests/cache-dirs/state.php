<?php
require __DIR__.'/../../source/cache-dirs/scripts/cache_dirs_state.php';
$checks = 0;
function check($actual, $expected, $label) {
    global $checks; ++$checks;
    if ($actual !== $expected) throw new RuntimeException($label.': '.json_encode($actual).' != '.json_encode($expected));
}
function disk($idx, $dev, $extra = []) {
    return array_merge(['idx' => (string)$idx, 'device' => $dev, 'id' => 'serial-'.$dev, 'rotational' => '1',
        'spundown' => '0', 'status' => 'DISK_OK'], $extra);
}
$disks = ['main' => disk(34, 'sda', ['fsMountpoint' => '/mnt/main', 'fsStatus' => 'Mounted', 'slots' => '2', 'devices' => '2']),
    'main2' => disk(35, 'sdb'), 'cache' => disk(32, 'nvme0n1', ['fsMountpoint' => '/mnt/cache', 'fsStatus' => 'Mounted', 'rotational' => '0'])];
$stats = ['sda' => array_fill(0, 17, 0), 'sdb' => array_fill(0, 17, 0)];
$read = function ($dev) use (&$stats) { return $stats[$dev] ?? null; };
$a = cache_dirs_state($disks, [], $read, 100, 'boot1');
check($a['idle'], 0, 'new observation starts conservatively');
check($a['roots'], ['/mnt/main', '/mnt/cache'], 'pool-only server');
$b = cache_dirs_state($disks, $a['state'], $read, 165, 'boot1');
check($b['idle'], 65, 'real idle duration without md array');
$stats['sdb'][0]++;
$c = cache_dirs_state($disks, $b['state'], $read, 170, 'boot1');
check($c['idle'], 0, 'IO on second RAIDZ member resets idle');
$d = cache_dirs_state($disks, $c['state'], $read, 200, 'boot1');
check($d['idle'], 30, 'idle after IO');
$stats['sda'][8] = 1;
check(cache_dirs_state($disks, $d['state'], $read, 210, 'boot1')['idle'], 0, 'inflight IO');
$stats['sda'][8] = 0;
$disks['main2']['spundown'] = '1';
$e = cache_dirs_state($disks, $d['state'], $read, 215, 'boot1');
check($e['roots'], ['/mnt/cache'], 'one sleeping member blocks entire pool');
check($e['blocked'], true, 'union share must be skipped');
$disks['main']['spundown'] = '1';
check(cache_dirs_state($disks, $e['state'], $read, 220, 'boot1')['idle'], 9999, 'all HDDs sleeping');
$disks['main']['spundown'] = $disks['main2']['spundown'] = '0';
check(cache_dirs_state($disks, $e['state'], $read, 230, 'boot1')['idle'], 0, 'wake transition resets observation');
check(cache_dirs_state($disks, $d['state'], $read, 5, 'boot2')['idle'], 0, 'reboot resets observation');
check(cache_dirs_state($disks, $d['state'], $read, 1, 'boot1')['idle'], 0, 'clock cannot produce negative age');
unset($stats['sdb']);
$f = cache_dirs_state($disks, $d['state'], $read, 300, 'boot1');
check($f['roots'], ['/mnt/cache'], 'missing counters block pool');
check($f['idle'], 0, 'unknown is not idle');
$stats['sdb'] = array_fill(0, 17, 0);
check(cache_dirs_state($disks, $d['state'], $read, 300, 'boot1')['idle'], 0, 'reset counters are activity');
$disks['main2']['device'] = '';
check(cache_dirs_state($disks, [], $read, 300, 'boot1')['roots'], ['/mnt/cache'], 'missing member blocks pool');
$disks['main2']['device'] = 'sdb';
unset($disks['main2']['spundown']);
check(cache_dirs_state($disks, [], $read, 300, 'boot1')['roots'], ['/mnt/cache'], 'unknown power blocks pool');
$disks['main2']['spundown'] = '0';
$disks['main2']['id'] = 'replacement';
check(cache_dirs_state($disks, $d['state'], $read, 300, 'boot1')['idle'], 0, 'replacement disk');
$disks['disk1'] = disk(1, 'sdc', ['fsStatus' => 'Mounted']);
$stats['sdc'] = array_fill(0, 17, 0);
check(cache_dirs_state($disks, [], $read, 300, 'boot1')['roots'], ['/mnt/main', '/mnt/cache', '/mnt/disk1'], 'array and pool together');
$disks['main']['fsMountpoint'] = '/mnt/user';
check(cache_dirs_state($disks, [], $read, 300, 'boot1')['roots'], ['/mnt/cache', '/mnt/disk1'], 'no union as physical root');
$disks['main']['fsMountpoint'] = '/mnt/data2026';
check(cache_dirs_state($disks, [], $read, 300, 'boot1')['roots'][0], '/mnt/data2026', 'pool name can end in digits');
$disks['main']['fsStatus'] = 'Unmountable';
check(cache_dirs_state($disks, [], $read, 300, 'boot1')['roots'], ['/mnt/cache', '/mnt/disk1'], 'unmounted pool');
echo "$checks checks passed\n";
