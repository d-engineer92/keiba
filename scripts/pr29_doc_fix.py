from pathlib import Path

path = Path('docs/database/logical-design.md')
text = path.read_text()
old = 'Migration `2026_09_05_000006_create_kd3_domain_tables.php` が唯一の物理schema定義である。すべてのFKは削除をRESTRICTし、source lineageを保持する。'
new = 'Migration `2026_09_05_000006_create_kd3_domain_tables.php` をKD3 domainの初期schemaとし、後続migration（現在は `2026_09_06_000009_refactor_kd3_speed_references.php`）で互換性を保ちながら進化させる。すべてのFKは削除をRESTRICTし、source lineageを保持する。'
if text.count(old) != 1:
    raise SystemExit(f'logical schema wording: expected 1 occurrence, found {text.count(old)}')
path.write_text(text.replace(old, new, 1))
Path('.github/workflows/pr29-doc-fix.yml').unlink(missing_ok=True)
Path(__file__).unlink()
