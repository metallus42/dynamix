#!/usr/bin/env python3
"""Rebuild cache-dirs deterministically, retaining the upstream logrotate files."""
import hashlib, io, pathlib, re, tarfile
root = pathlib.Path(__file__).resolve().parents[2]
archive = root/'archive/dynamix.cache.dirs.txz'
prefix = 'usr/local/emhttp/plugins/dynamix.cache.dirs/'
entries = {}
with tarfile.open(archive, 'r:xz') as old:
    for item in old:
        name = item.name.lstrip('./')
        if not name or name.startswith(prefix) or name == prefix.rstrip('/'): continue
        if not (item.isfile() or item.isdir()): raise ValueError('Unexpected archive member: '+name)
        entries[name] = (item.mode, old.extractfile(item).read() if item.isfile() else None)
for path in (root/'source/cache-dirs').rglob('*'):
    name = prefix + str(path.relative_to(root/'source/cache-dirs'))
    executable = name.startswith(prefix+'scripts/') or name.startswith(prefix+'event/')
    entries[name] = (0o755 if path.is_dir() or executable else 0o644, None if path.is_dir() else path.read_bytes())
entries[prefix.rstrip('/')] = (0o755, None)
with tarfile.open(archive, 'w:xz', format=tarfile.USTAR_FORMAT) as out:
    for name, (mode, data) in sorted(entries.items()):
        item = tarfile.TarInfo(name)
        item.mode = mode; item.uid = item.gid = 0; item.uname = item.gname = 'root'; item.mtime = 0
        if data is None: item.type = tarfile.DIRTYPE
        else: item.size = len(data)
        out.addfile(item, io.BytesIO(data) if data is not None else None)
manifest = root/'unRAIDv6/dynamix.cache.dirs.plg'
s = re.sub(r'<!ENTITY MD5\s+"[a-f0-9]+">', '<!ENTITY MD5       "'+hashlib.md5(archive.read_bytes()).hexdigest()+'">', manifest.read_text())
manifest.write_text(s)
print('Package SHA256:', hashlib.sha256(archive.read_bytes()).hexdigest())
