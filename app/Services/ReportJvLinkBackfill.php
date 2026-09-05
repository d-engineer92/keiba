<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ReportJvLinkBackfill
{
    /** @param array<string, mixed> $report */
    public function store(array $report): array
    {
        return DB::transaction(function () use ($report): array {
            DB::select("SELECT pg_advisory_xact_lock(hashtext('keiba.jvlink.backfill_report'))");
            $now = CarbonImmutable::now('UTC');
            $existing = DB::table('jvlink_backfill_runs')->where('source_run_id', $report['source_run_id'])->lockForUpdate()->first();
            if ($existing !== null && ($existing->requested_from !== $report['requested_from'] || $existing->requested_to !== $report['requested_to'])) {
                throw new ConflictHttpException('A backfill run ID was reused for a different requested range.');
            }
            $run = collect($report)->except(['coverages'])->all() + ['updated_at' => $now];
            if ($existing === null) {
                DB::table('jvlink_backfill_runs')->insert($run + ['created_at' => $now]);
            } else {
                DB::table('jvlink_backfill_runs')->where('id', $existing->id)->update($run);
            }
            foreach ($report['coverages'] as $coverage) {
                $raceId = $coverage['venue_code'] === null ? null : $this->race($coverage);
                $key = ['coverage_date' => $coverage['coverage_date'], 'race_id' => $raceId, 'data_kind' => $coverage['data_kind']];
                DB::table('jvlink_backfill_coverages')->updateOrInsert($key, [
                    'status' => $coverage['status'], 'first_snapshot_at' => $coverage['first_snapshot_at'],
                    'last_snapshot_at' => $coverage['last_snapshot_at'], 'snapshot_count' => $coverage['snapshot_count'],
                    'last_checked_at' => $coverage['last_checked_at'], 'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            return ['run_id' => $report['source_run_id'], 'coverages' => count($report['coverages'])];
        }, 3);
    }

    /** @param array<string, mixed> $coverage */
    private function race(array $coverage): int
    {
        $mapping = DB::table('source_identifiers')->where([
            'source_system' => 'jvlink', 'entity_type' => 'venue', 'identifier_type' => 'venue_code',
            'identifier_value' => $coverage['venue_code'],
        ])->first();
        $race = $mapping === null ? null : DB::table('races')
            ->join('race_calendars', 'race_calendars.id', '=', 'races.race_calendar_id')
            ->where(['race_calendars.venue_id' => $mapping->entity_id, 'race_calendars.race_date' => $coverage['coverage_date'], 'races.race_no' => $coverage['race_no']])
            ->select('races.id')->first();
        if ($race === null) {
            throw new ConflictHttpException('Backfill coverage cannot be resolved to an existing canonical race.');
        }

        return $race->id;
    }
}
