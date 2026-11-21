"""Build a deterministic, installable plugin ZIP without tests or development files.

Usage: python3 scripts/package.py [output_dir]   (default: dist/)
The version is read from the plugin header, so it never drifts from the code.
"""
import pathlib
import re
import sys
import zipfile

root = pathlib.Path(__file__).resolve().parents[1]
header = (root / 'community-pulse.php').read_text(encoding='utf-8')
match = re.search(r'^\s*\*\s*Version:\s*(\S+)', header, re.M)
if not match:
    sys.exit('Version header not found in community-pulse.php')
version = match.group(1)

files = ['community-pulse.php', 'uninstall.php', 'README.md', 'README.es.md', 'CHANGELOG.md', 'LICENSE', 'VALIDATION.md']
for folder in ('includes', 'assets', 'languages'):
    files += sorted(str(p.relative_to(root)) for p in (root / folder).iterdir() if p.is_file() and not p.name.startswith('.'))

out = pathlib.Path(sys.argv[1] if len(sys.argv) > 1 else root / 'dist')
out.mkdir(parents=True, exist_ok=True)
target = out / f'buddyboss-community-pulse-{version}.zip'
with zipfile.ZipFile(target, 'w', zipfile.ZIP_DEFLATED) as archive:
    for name in files:
        entry = zipfile.ZipInfo('buddyboss-community-pulse/' + name, (2026, 1, 1, 0, 0, 0))
        entry.external_attr = 0o100644 << 16
        entry.compress_type = zipfile.ZIP_DEFLATED
        archive.writestr(entry, (root / name).read_bytes())
print(target)
