#!/usr/bin/php
<?php
/* SPDX-License-Identifier: GPL-2.0-only
 * Read only emhttp's RAM state and kernel counters. Never query a drive or
 * enumerate a mount: even finding scan targets must not wake sleeping pools.
 */
function cache_dirs_state(array $disks, array $previous, callable $stat, float $now, string $boot): array {
    $old = ($previous['boot'] ?? '') === $boot ? ($previous['devices'] ?? []) : [];
    $state = ['boot' => $boot, 'devices' => []];
    $roots = []; $blocked = false; $idle = 9999;
    foreach ($disks as $name => $disk) {
        $root = $disk['fsMountpoint'] ?? '';
        if (!$root && preg_match('/^disk[0-9]+$/', $name)) $root = '/mnt/'.$name;
        // Only mounted, managed filesystems; never /mnt/user, remote or UD paths.
        if (!preg_match('#^/mnt/[a-zA-Z0-9_.-]+$#D', $root) ||
            in_array($root, ['/mnt/user', '/mnt/user0', '/mnt/disks']) ||
            ($disk['fsStatus'] ?? '') !== 'Mounted') continue;
        $members = [$disk];
        if (isset($disk['slots'])) {
            $members = [];
            $first = (int)($disk['idx'] ?? -1);
            $slots = (int)$disk['slots'];
            foreach ($disks as $member) {
                $idx = (int)($member['idx'] ?? -999);
                if ($idx >= $first && $idx < $first + $slots && ($member['device'] ?? '') !== '') $members[] = $member;
            }
        }
        $safe = count($members) > 0 && count($members) === (int)($disk['devices'] ?? 1);
        if (!$safe) $idle = 0;
        foreach ($members as $member) {
            $dev = $member['device'] ?? '';
            $rotational = (string)($member['rotational'] ?? '');
            if (!preg_match('/^[a-zA-Z0-9_-]+$/D', $dev) || !in_array($rotational, ['0', '1'], true) ||
                ($member['status'] ?? '') !== 'DISK_OK') {
                $safe = false; $idle = 0; continue;
            }
            if ($rotational === '0') continue;
            $power = (string)($member['spundown'] ?? '');
            if (!in_array($power, ['0', '1'], true)) { $safe = false; $idle = 0; continue; }
            // One sleeping member is enough to skip the entire mirror/RAIDZ pool.
            if ($power === '1') $safe = false;
            $values = $stat($dev);
            if (!is_array($values) || count($values) < 11) { $safe = false; $idle = 0; continue; }
            // Completions AND sectors catch tiny metadata IO. Ignore elapsed IO
            // times, but include discard/flush counters on kernels exposing them.
            $counters = [];
            foreach ([0, 2, 4, 6, 11, 13, 15] as $i) $counters[] = $values[$i] ?? 0;
            $identity = $member['id'] ?? $dev;
            $prior = $old[$dev] ?? [];
            $changed = ($prior['id'] ?? null) !== $identity || ($prior['counters'] ?? null) !== $counters ||
                ($prior['power'] ?? null) !== $power || (int)$values[8] !== 0;
            $last = $changed ? $now : min($now, (float)($prior['last'] ?? $now));
            $state['devices'][$dev] = ['id' => $identity, 'counters' => $counters, 'power' => $power, 'last' => $last];
            if ($power === '0') $idle = min($idle, max(0, (int)($now - $last)));
        }
        if ($safe) $roots[] = $root;
        else $blocked = true;
    }
    return ['state' => $state, 'roots' => array_values(array_unique($roots)), 'idle' => $idle, 'blocked' => $blocked];
}

function cache_dirs_state_main(string $stateFile): int {
    $disks = @parse_ini_file('/var/local/emhttp/disks.ini', true, INI_SCANNER_RAW);
    $uptime = @file_get_contents('/proc/uptime');
    $boot = @file_get_contents('/proc/sys/kernel/random/boot_id');
    if (!is_array($disks) || !$uptime || !$boot) return 1;
    $previous = json_decode(@file_get_contents($stateFile) ?: '{}', true) ?: [];
    $result = cache_dirs_state($disks, $previous, static function ($dev) {
        $raw = @file_get_contents('/sys/class/block/'.$dev.'/stat');
        if ($raw === false || !preg_match('/^\s*\d+(?:\s+\d+)+\s*$/D', $raw)) return null;
        return array_map('intval', preg_split('/\s+/', trim($raw)));
    }, (float)$uptime, trim($boot));
    $tmp = tempnam(dirname($stateFile), '.cache_dirs.');
    if (!$tmp) return 1;
    if (file_put_contents($tmp, json_encode($result['state'])) === false || !rename($tmp, $stateFile)) {
        @unlink($tmp); return 1;
    }
    echo 'idle ', $result['idle'], "\nblocked ", (int)$result['blocked'], "\n";
    foreach ($result['roots'] as $root) echo 'root ', $root, "\n";
    return 0;
}
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    if ($argc !== 2) exit(1);
    exit(cache_dirs_state_main($argv[1]));
}
