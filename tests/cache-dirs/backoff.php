<?php
require __DIR__.'/../../source/cache-dirs/scripts/cache_dirs_state.php';
$checks = 0;
function check($actual, $expected, $label) {
    global $checks; ++$checks;
    if ($actual !== $expected) throw new RuntimeException($label.': '.json_encode($actual));
}
$member = ['status'=>'DISK_OK', 'rotational'=>'1', 'spundown'=>'0'];
$mounted = ['fsStatus'=>'Mounted'];
$disks = [
    'main'=>array_merge($member, $mounted, ['idx'=>'34','device'=>'sda','id'=>'a','fsMountpoint'=>'/mnt/main','slots'=>'2','devices'=>'2','spindownDelay'=>'30']),
    'main2'=>array_merge($member, ['idx'=>'35','device'=>'sdb','id'=>'b']),
    'other'=>array_merge($member, $mounted, ['idx'=>'36','device'=>'sdc','id'=>'c','fsMountpoint'=>'/mnt/other','spindownDelay'=>'15']),
    'cache'=>array_merge($member, $mounted, ['idx'=>'32','device'=>'nvme0n1','rotational'=>'0','fsMountpoint'=>'/mnt/cache'])
];
$stats = array_fill_keys(['sda','sdb','sdc'], array_fill(0,17,0));
$read = function($dev) use (&$stats) {return $stats[$dev] ?? null;};
$a = cache_dirs_state($disks, [], $read, 100, 'boot', 'begin');
$stats['sdb'][0]++; // One tiny physical metadata read in a sub-second scan.
$middle = cache_dirs_state($disks, $a['state'], $read, 100.1, 'boot');
$b = cache_dirs_state($disks, $middle['state'], $read, 100.2, 'boot', 'end');
check($b['roots'], ['/mnt/other','/mnt/cache'], 'one member read blocks entire pool, other pools continue');
check($b['blocked'], true, 'union scanning blocked during backoff');
check($b['held']['/mnt/main'], 1860, '30-minute timer plus 60-second grace');
check(isset($b['state']['scan']), false, 'end consumes baseline even across intermediate samples');
$c = cache_dirs_state($disks, $b['state'], $read, 1900, 'boot', 'begin');
check($c['roots'], ['/mnt/other','/mnt/cache'], 'still excluded after 30 minutes');
check(isset($c['state']['scan']['sdb']), false, 'held disks never armed as scan targets');
$stats['sdb'][4]++; // An external write during the hold resets the quiet window.
$d = cache_dirs_state($disks, $c['state'], $read, 1901, 'boot', 'end');
check($d['held']['/mnt/main'], 1860, 'external activity extends pause');
check(in_array('/mnt/main', cache_dirs_state($disks, $d['state'], $read, 3760, 'boot')['roots']), false, 'no early retry');
$expired = cache_dirs_state($disks, $d['state'], $read, 3761, 'boot');
check(in_array('/mnt/main', $expired['roots']), true, 'retry after full quiet interval');
check($expired['state']['devices']['sdb']['hold_until'], 0, 'expired pause cleared');
$disks['main']['spundown'] = $disks['main2']['spundown'] = '1';
$sleep = cache_dirs_state($disks, $d['state'], $read, 2000, 'boot');
check(in_array('/mnt/main', $sleep['roots']), false, 'sleeping pool remains excluded');
$disks['main']['spundown'] = $disks['main2']['spundown'] = '0';
$wake = cache_dirs_state($disks, $sleep['state'], $read, 2001, 'boot', 'begin');
check(in_array('/mnt/main', $wake['roots']), true, 'external wake permits warming again');
$warm = cache_dirs_state($disks, $wake['state'], $read, 2002, 'boot', 'end');
check($warm['held'], [], 'RAM-only scan does not pause');
$begin = cache_dirs_state($disks, $warm['state'], $read, 2003, 'boot', 'begin');
$stats['sdc'][15]++;
$flush = cache_dirs_state($disks, $begin['state'], $read, 2004, 'boot', 'end');
check($flush['held']['/mnt/other'], 960, 'flush also triggers configured 15-minute delay');
check(in_array('/mnt/main', $flush['roots']), true, 'other pool hold does not suppress main');
$stats['sda'][8] = 1;
$begin = cache_dirs_state($disks, $flush['state'], $read, 2005, 'boot', 'begin');
$busy = cache_dirs_state($disks, $begin['state'], $read, 2006, 'boot', 'end');
check(isset($busy['held']['/mnt/main']), true, 'inflight IO at scan end triggers backoff');
$stats['sda'][8] = 0;
check(cache_dirs_state($disks, $busy['state'], $read, 5, 'newboot')['held'], [], 'reboot resets monotonic hold');
$disks['main']['spindownDelay'] = '-1';
$begin = cache_dirs_state($disks, [], $read, 100, 'boot', 'begin', 60);
$stats['sda'][0]++;
$inherit = cache_dirs_state($disks, $begin['state'], $read, 101, 'boot', 'end', 60);
check($inherit['held']['/mnt/main'], 3660, 'inherits global timeout from RAM metadata');
echo "$checks backoff checks passed\n";
