"""Build a deterministic WordPress plugin ZIP and its checksum."""
from hashlib import sha256
from pathlib import Path
import re
from zipfile import ZIP_DEFLATED, ZipFile, ZipInfo

root = Path(__file__).resolve().parents[1]
plugin = root / "dexo.php"
version = re.search(r"^ \* Version: (\d+\.\d+\.\d+)$", plugin.read_text(), re.MULTILINE).group(1)
files = [
    plugin,
    root / "LICENSE",
    root / "README.md",
    root / "DESIGN.md",
    root / "CHANGELOG.md",
    root / "SECURITY.md",
    *sorted((root / "assets").glob("*")),
    *sorted((root / "includes").glob("*.php")),
    *sorted((root / "docs").glob("*.md")),
    root / "languages/dexo-fa_IR.mo",
    root / "languages/dexo-fa_IR.po",
    root / "languages/dexo.pot",
]
output = root / "dist"
output.mkdir(exist_ok=True)
archive = output / f"dexo-{version}.zip"
with ZipFile(archive, "w") as bundle:
    for path in files:
        member = ZipInfo("dexo/" + path.relative_to(root).as_posix())
        member.external_attr = 0o100644 << 16
        bundle.writestr(member, path.read_bytes(), compress_type=ZIP_DEFLATED)
digest = sha256(archive.read_bytes()).hexdigest()
archive.with_suffix(".zip.sha256").write_text(f"{digest}  {archive.name}\n")
print(archive)
