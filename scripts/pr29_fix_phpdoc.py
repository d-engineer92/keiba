from pathlib import Path

path = Path("app/Kd3/Domain/Kd3DomainImporter.php")
text = path.read_text()
old = "    /** @param array<string, mixed> $fields */\n    private function mergeRaceFacts(int $raceId, ?string $name = null, ?CarbonImmutable $start = null): void"
new = "    private function mergeRaceFacts(int $raceId, ?string $name = null, ?CarbonImmutable $start = null): void"
if text.count(old) != 1:
    raise SystemExit(f"stale mergeRaceFacts PHPDoc: expected 1 occurrence, found {text.count(old)}")
path.write_text(text.replace(old, new, 1))
Path(__file__).unlink()
