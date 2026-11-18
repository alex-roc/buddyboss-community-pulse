"""Build a deterministic, installable plugin ZIP without test/runtime/private files."""
import pathlib, sys, zipfile
root = pathlib.Path(__file__).resolve().parents[1]
out = pathlib.Path(sys.argv[1] if len(sys.argv)>1 else 'dist')
out.mkdir(parents=True, exist_ok=True)
files = ['community-pulse.php','includes/class-report.php','includes/class-plugin.php','README.md','LICENSE','VALIDATION.md']
with zipfile.ZipFile(out/'buddyboss-community-pulse-0.1.0.zip','w',zipfile.ZIP_DEFLATED) as archive:
    for name in files:
        entry = zipfile.ZipInfo('buddyboss-community-pulse/'+name, (2026,11,18,0,0,0))
        entry.external_attr = 0o100644 << 16
        entry.compress_type = zipfile.ZIP_DEFLATED
        archive.writestr(entry, (root/name).read_bytes())
print(out/'buddyboss-community-pulse-0.1.0.zip')
