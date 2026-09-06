<?php

namespace App\Kd3\Domain;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class EntityResolver
{
    /** @var array<string, array{id:int,named:bool}> */
    private array $cache = [];

    public function resetCache(): void
    {
        $this->cache = [];
    }

    public function resolve(string $entityType, string $identifierType, string $identifierValue, ?string $name = null): int
    {
        $table = match ($entityType) {
            'horse' => 'horses', 'jockey' => 'jockeys', 'trainer' => 'trainers',
            default => throw new Kd3ImportException('Unsupported entity type.', 'mapping', $entityType),
        };
        $cacheKey = implode(':', [$entityType, $identifierType, $identifierValue]);
        $normalizedName = $name === null || trim($name) === '' ? null : trim($name);
        if (isset($this->cache[$cacheKey])) {
            $cached = $this->cache[$cacheKey];
            if (! $cached['named'] && $normalizedName !== null) {
                DB::table($table)->where('id', $cached['id'])->whereNull('name')->update([
                    'name' => $normalizedName,
                    'updated_at' => CarbonImmutable::now('UTC'),
                ]);
                $this->cache[$cacheKey]['named'] = true;
            }

            return $cached['id'];
        }

        $mapping = DB::table('source_identifiers')->where([
            'source_system' => 'kd3',
            'entity_type' => $entityType,
            'identifier_type' => $identifierType,
            'identifier_value' => $identifierValue,
        ])->lockForUpdate()->first();
        $now = CarbonImmutable::now('UTC');
        if ($mapping !== null) {
            $entity = DB::table($table)->where('id', $mapping->entity_id)->lockForUpdate()->first();
            if ($entity === null) {
                throw new Kd3ImportException('Source identifier points to a missing entity.', 'identity_conflict', $entityType, $identifierValue);
            }
            if ($entity->name === null && $normalizedName !== null) {
                DB::table($table)->where('id', $entity->id)->update(['name' => $normalizedName, 'updated_at' => $now]);
            }
            // Identity mappings are immutable. Do not rewrite last_seen_at merely because an old
            // immutable source file is replayed during a historical rebuild.
            $this->cache[$cacheKey] = ['id' => (int) $entity->id, 'named' => $entity->name !== null || $normalizedName !== null];

            return (int) $entity->id;
        }

        $id = DB::table($table)->insertGetId([
            'name' => $normalizedName,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        try {
            DB::table('source_identifiers')->insert([
                'source_system' => 'kd3',
                'entity_type' => $entityType,
                'entity_id' => $id,
                'identifier_type' => $identifierType,
                'identifier_value' => $identifierValue,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\Throwable $exception) {
            throw new Kd3ImportException('External identifier is already assigned.', 'identity_conflict', $entityType, $identifierValue);
        }
        $this->cache[$cacheKey] = ['id' => (int) $id, 'named' => $normalizedName !== null];

        return (int) $id;
    }
}
