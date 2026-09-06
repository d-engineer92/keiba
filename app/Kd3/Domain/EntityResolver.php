<?php

namespace App\Kd3\Domain;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class EntityResolver
{
    public function resolve(string $entityType, string $identifierType, string $identifierValue, ?string $name = null): int
    {
        $table = match ($entityType) {
            'horse' => 'horses', 'jockey' => 'jockeys', 'trainer' => 'trainers',
            default => throw new Kd3ImportException('Unsupported entity type.', 'mapping', $entityType),
        };
        $mapping = DB::table('source_identifiers')->where(['source_system' => 'kd3', 'entity_type' => $entityType,
            'identifier_type' => $identifierType, 'identifier_value' => $identifierValue])->lockForUpdate()->first();
        $now = CarbonImmutable::now('UTC');
        if ($mapping !== null) {
            $entity = DB::table($table)->where('id', $mapping->entity_id)->lockForUpdate()->first();
            if ($entity === null) {
                throw new Kd3ImportException('Source identifier points to a missing entity.', 'identity_conflict', $entityType, $identifierValue);
            }
            if ($entity->name === null && $name !== null && trim($name) !== '') {
                DB::table($table)->where('id', $entity->id)->update(['name' => trim($name), 'updated_at' => $now]);
            }
            DB::table('source_identifiers')->where('id', $mapping->id)->update(['last_seen_at' => $now, 'updated_at' => $now]);

            return (int) $entity->id;
        }
        $id = DB::table($table)->insertGetId(['name' => $name === null || trim($name) === '' ? null : trim($name), 'created_at' => $now, 'updated_at' => $now]);
        try {
            DB::table('source_identifiers')->insert(['source_system' => 'kd3', 'entity_type' => $entityType, 'entity_id' => $id,
                'identifier_type' => $identifierType, 'identifier_value' => $identifierValue, 'first_seen_at' => $now, 'last_seen_at' => $now,
                'created_at' => $now, 'updated_at' => $now]);
        } catch (\Throwable $exception) {
            throw new Kd3ImportException('External identifier is already assigned.', 'identity_conflict', $entityType, $identifierValue);
        }

        return (int) $id;
    }
}
