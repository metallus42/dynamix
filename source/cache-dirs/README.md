**Dynamix Cache Directories**

Dynamix Cache Directories keeps folder information in memory to prevent unnecessary disk spin up.
Dynamix builds a GUI front-end to allow entering of parameters for the cache_dirs script which is running in the background.


## Pool-aware standby fork

This fork tracks physical HDD I/O using `/sys/class/block/*/stat` and uses
Unraid's `/var/local/emhttp/disks.ini` for mounted filesystem membership and
spindown state. It works with pool-only ZFS configurations as well as mixed
array/pool systems, including encrypted pools: the metadata names the physical
backing devices, so no `zpool`, `zfs`, SMART or block-device probe is needed.

If any HDD member is asleep, missing, or has an unknown state, the entire
filesystem is excluded from root discovery, root warming, recursive scans and
file counting. Missing helper/state access fails closed. The optional
`/mnt/user` scan is skipped when a managed filesystem is blocked, because the
union can reach that sleeping filesystem. SSD-only pools continue scanning.
Counters are stored in `/run`, keyed to the daemon and boot. Startup, reboot,
drive replacement, counter resets and wake transitions start with zero known
idle time; SSD I/O is ignored for HDD idle timing.

### Avoiding scan-induced HDD activity

Each discovery, root-warming, recursive-scan and file-counting phase is bracketed
by physical HDD counter snapshots. Any counter change or in-flight I/O at phase
end pauses the affected pool, even if the scan was fast. Other pools remain eligible.
The pause lasts for the configured spindown delay plus 60 seconds without new I/O;
further activity extends it. An observed sleep followed by an external wake permits
warming again. Unset per-pool delays inherit the global RAM configuration; disabled
or unavailable timers use a 31-minute quiet window. The optional user-share scan
is also suppressed while a pool is held. A RAM-only scan continues normally.

This deliberately treats concurrent application I/O as a reason to back off too: kernel
counters do not distinguish its source. Checks happen between scan phases, so the first
cold scan may issue I/O before the pause begins. Holds are per daemon and reset on restart.

### Limits

This is a best-effort scan policy based on Unraid's reported state, not a lock
against concurrent spindown. A drive can change state after a snapshot or during
an already running scan. Use Unraid's normal spindown controls: a direct `hdparm`
command can leave its cached status stale. Caching stops during sleep, so cached
names can be evicted and a later user directory listing may wake the pool.
Application reads, backups and ZFS maintenance can still wake disks. No longer
sleep-duration claim has been established by a full workload comparison yet.

### Test and build

From the repository root:

```sh
php tests/cache-dirs/state.php
php tests/cache-dirs/backoff.php
bash tests/cache-dirs/helper-limit.sh
python3 tests/cache-dirs/scans.py
bash -n source/cache-dirs/scripts/cache_dirs
php -l source/cache-dirs/scripts/cache_dirs_state.php
python3 tests/cache-dirs/build_package.py
```

The build refreshes the package and checksum referenced by the fork's plugin
manifest. The existing plugin name and settings are retained; install this as
an alternative version, never as a second daemon alongside the original.
Back up `/boot/config/plugins/dynamix.cache.dirs/` before installing. Roll back
through the official `unraid/dynamix` plugin manifest, preserving that settings
backup. Repository creation and packaging do not install the fork on a server.

The daemon retains its configured soft VM limit for directory scans. The PHP
helper restores the inherited soft VM limit solely to load its shared libraries,
with a 16 MiB PHP allocation limit; administrator hard limits are preserved.
