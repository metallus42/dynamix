#!/usr/bin/env python3
"""Exercise the actual Bash discovery/deep/count functions with a find recorder."""
import pathlib, re, subprocess, tempfile
source = pathlib.Path(__file__).resolve().parents[2] / 'source/cache-dirs/scripts/cache_dirs'
text = source.read_text()
names = ['refresh_scan_roots', 'build_dir_list', 'wait_and_get_exit_codes', 'do_deep_scan', 'count_files']
functions = '\n'.join(re.search(r'^(?:function )?' + n + r'(?:\(\))? \{\n.*?^\}', text, re.M | re.S).group() for n in names)
with tempfile.TemporaryDirectory() as temp:
    root = pathlib.Path(temp)
    for name in ('awake', 'sleeping'):
        (root/name/'Media'/'child').mkdir(parents=True)
        (root/name/'Media'/'child'/'file').write_text('test')
    wrapper = root/'scan-command'
    wrapper.write_text("#!/bin/bash\nprintf '%s\\n' \"$*\" >> "+str(root/'deep-calls')+'\nexec find "$@"\n')
    wrapper.chmod(0o755)
    common = f'''
{functions}
log() {{ :; }}
read_pool_state() {{ php; }}
php() {{
  if [ "$scenario" = failure ]; then return 1; fi
  echo 'idle 60'
  echo 'blocked 1'
  if [ "$scenario" = mixed ]; then echo 'root {root}/awake'; fi
}}
find() {{
  printf '%s\\n' "$*" >> {root}/calls
  command find "$@"
}}
export -f find
array=""; pools=""; state_helper=ignored; io_state=ignored
include_array_count=0; exclude_array_count=0
maxDepthUnbounded=9999; maxDepth=3
NANO_PR_SEC=1000000000; NANO_PR_MS=1000000
multithreaded_scan=0; include_scan_of_user_share=1
command={root}/scan-command; args=-noleaf
lockfile={root}/lock
touch "$lockfile"
'''
    for scenario in ('mixed', 'asleep', 'failure'):
        (root/'calls').write_text('')
        (root/'deep-calls').write_text('')
        code = common + f'''
scenario={scenario}
refresh_scan_roots
for i in $array $pools; do find "$i" -maxdepth 1 -noleaf >/dev/null; done
dir_list=$(build_dir_list)
do_deep_scan 3 30
count_files 3 >/dev/null
'''
        subprocess.run(['bash', '-c', code], check=True, capture_output=True, text=True)
        calls = (root/'calls').read_text().splitlines()
        deep_calls = (root/'deep-calls').read_text().splitlines()
        if scenario == 'mixed':
            assert calls, 'awake root must be scanned'
            assert all(str(root/'awake') in c for c in calls), calls
            assert deep_calls and all(str(root/'awake'/'Media') in c for c in deep_calls), 'deep scan must execute only on awake root'
        else:
            assert not calls and not deep_calls, (scenario, calls, deep_calls)
        assert all('/mnt/user' not in c and 'sleeping' not in c for c in calls)
    # These are the real daemon entry points; protection must precede root warming.
    loop = text[text.index('  log "cache_dirs started"'):]
    assert loop.index('refresh_scan_roots') < loop.index('find $i -maxdepth 1')
    startup = text[text.index('# will update dir_list on each scan, in case new shares have been added\nrefresh_scan_roots'):]
    assert startup.index('refresh_scan_roots') < startup.index('dir_list=$(build_dir_list)')
print('3 scan scenarios passed: mixed, all asleep, unavailable state; no sleeping/union/cwd scans')
