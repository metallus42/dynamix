#!/usr/bin/php
<?php
/* SPDX-License-Identifier: GPL-2.0-only
 * Read only emhttp's RAM state and kernel counters. Never query a drive or
 * enumerate a mount: even finding scan targets must not wake sleeping pools.
 */
function cache_dirs_state(array $disks, array $previous, callable $stat, float $now, string $boot, string $phase = 'sample', int $defaultDelay = 30): array {
    $old = ($previous['boot'] ?? '') === $boot ? ($previous['devices'] ?? []) : [];
    $scan = ($previous['boot'] ?? '') === $boot ? ($previous['scan'] ?? []) : [];
    $state = ['boot' => $boot, 'devices' => []];
    $roots = []; $blocked = false; $idle = 9999; $held = []; $eligible = [];
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
        $delay = (int)($disk['spindownDelay'] ?? -1);
        if ($delay < 0) $delay = $defaultDelay;
        // Even with automatic spindown disabled, avoid continuous cold scans.
        $holdSeconds = ($delay > 0 ? $delay : 30) * 60 + 60;
        foreach ($members as $member) {
            $memberDelay = (int)($member['spindownDelay'] ?? -1);
            if ($memberDelay > 0) $holdSeconds = max($holdSeconds, $memberDelay * 60 + 60);
        }
        $rootDevices = [];
        $rootActivity = false; $rootWake = false; $poolHold = 0;
        if (!$safe) $idle = 0;
        foreach ($members as $member) {
            $dev = $member['device'] ?? '';
            $rotational = (string)($member['rotational'] ?? '');
            if (!preg_match('/^[a-zA-Z0-9_-]+$/D', $dev) || !in_array($rotational, ['0', '1'], true) ||
                ($member['status'] ?? '') !== 'DISK_OK') {
                $safe = false; $idle = 0; continue;
            }
            if ($rotational === '0') continue;
            $rootDevices[] = $dev;
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
            $holdUntil = ($prior['id'] ?? null) === $identity ? (float)($prior['hold_until'] ?? 0) : 0;
            $wake = ($prior['power'] ?? null) === '1' && $power === '0';
            $rootActivity = $rootActivity || $changed;
            $rootWake = $rootWake || $wake;
            if ($wake) $holdUntil = 0; // Let an externally awakened pool warm again.
            elseif ($holdUntil > 0 && $changed) $holdUntil = $now + $holdSeconds;
            $baseline = $scan[$dev] ?? null;
            if ($phase === 'end' && $baseline !== null &&
                (($baseline['id'] ?? null) !== $identity || ($baseline['counters'] ?? null) !== $counters ||
                 ($baseline['power'] ?? null) !== $power || (int)$values[8] !== 0)) {
                $holdUntil = $now + $holdSeconds;
            }
            if ($holdUntil <= $now) $holdUntil = 0;
            $state['devices'][$dev] = ['id' => $identity, 'counters' => $counters, 'power' => $power, 'last' => $last,
                                     'hold_until' => $holdUntil];
            $poolHold = max($poolHold, $holdUntil);
            if ($power === '0') $idle = min($idle, max(0, (int)($now - $last)));
        }
        // A hold belongs to the whole pool: activity on a different RAID member
        // must also restart its quiet window. Allow staggered member wake-ups.
        if ($rootWake && $phase !== 'end') $poolHold = 0;
        elseif ($poolHold > $now && $rootActivity) $poolHold = $now + $holdSeconds;
        foreach ($rootDevices as $dev) {
            if (isset($state['devices'][$dev])) $state['devices'][$dev]['hold_until'] = $poolHold;
        }
        if ($poolHold > $now) { $safe = false; $held[$root] = (int)ceil($poolHold - $now); }
        if ($safe) { $roots[] = $root; foreach ($rootDevices as $dev) $eligible[$dev] = $state['devices'][$dev]; }
        else $blocked = true;
    }
    if ($phase === 'begin') $state['scan'] = $eligible;
    elseif ($phase !== 'end') $state['scan'] = $scan;
    return ['state' => $state, 'roots' => array_values(array_unique($roots)), 'idle' => $idle, 'blocked' => $blocked, 'held' => $held];
}

function cache_dirs_state_main(string $stateFile, string $phase = 'sample'): int {
    if (!in_array($phase, ['sample', 'begin', 'end'], true)) return 1;
    $disks = @parse_ini_file('/var/local/emhttp/disks.ini', true, INI_SCANNER_RAW);
    $uptime = @file_get_contents('/proc/uptime');
    $boot = @file_get_contents('/proc/sys/kernel/random/boot_id');
    if (!is_array($disks) || !$uptime || !$boot) return 1;
    $previous = json_decode(@file_get_contents($stateFile) ?: '{}', true) ?: [];
    $vars = @parse_ini_file('/var/local/emhttp/var.ini', false, INI_SCANNER_RAW) ?: [];
    $result = cache_dirs_state($disks, $previous, static function ($dev) {
        $raw = @file_get_contents('/sys/class/block/'.$dev.'/stat');
        if ($raw === false || !preg_match('/^\s*\d+(?:\s+\d+)+\s*$/D', $raw)) return null;
        return array_map('intval', preg_split('/\s+/', trim($raw)));
    }, (float)$uptime, trim($boot), $phase, (int)($vars['spindownDelay'] ?? 30));
    $tmp = tempnam(dirname($stateFile), '.cache_dirs.');
    if (!$tmp) return 1;
    if (file_put_contents($tmp, json_encode($result['state'])) === false || !rename($tmp, $stateFile)) {
        @unlink($tmp); return 1;
    }
    echo 'idle ', $result['idle'], "\nblocked ", (int)$result['blocked'], "\n";
    foreach ($result['roots'] as $root) echo 'root ', $root, "\n";
    foreach ($result['held'] as $root => $seconds) echo 'hold ', $root, ' ', $seconds, "\n";
    return 0;
}
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    if ($argc < 2 || $argc > 3) exit(1);
    exit(cache_dirs_state_main($argv[1], $argv[2] ?? 'sample'));
}
