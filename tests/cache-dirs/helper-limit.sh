#!/bin/bash
set -eu
if [ "$(uname -s)" != Linux ]; then
  echo 'SKIP: Linux virtual memory limits are required'
  exit 0
fi
# Use the production wrapper, not a separately reimplemented PHP invocation.
script_dir=$(cd "$(dirname "$0")/../../source/cache-dirs/scripts" && pwd)
eval "$(sed -n '/^read_pool_state() (/,/^)/p' "$script_dir/cache_dirs")"
state_helper_vmem=$(ulimit -Sv)
state_helper="$script_dir/cache_dirs_state.php"
io_state=$(mktemp)
trap 'rm -f "$io_state"' EXIT
# PHP must load its libraries under the exact daemon soft VM limit. Its helper
# fails safely off Unraid (no emhttp state), but a dynamic-linker exit is a bug.
ulimit -Sv 50000
rc=0
read_pool_state >/dev/null || rc=$?
if [ "$rc" -ne 0 ] && [ "$rc" -ne 1 ]; then
  echo "Helper failed to load under daemon limit: $rc" >&2
  exit 1
fi
[ "$(ulimit -Sv)" = 50000 ]
echo 'PHP helper starts; daemon VM limit remains 50000 KiB'
