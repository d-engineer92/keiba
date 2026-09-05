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
                $raceId = $this->race($coverage);
                $key = ['source_race_key' => $coverage['source_race_key'], 'data_kind' => $coverage['data_kind']];
                DB::table('jvlink_backfill_coverages')->updateOrInsert($key, [
                    'coverage_date' => $coverage['coverage_date'], 'venue_code' => $coverage['venue_code'],
                    'race_no' => $coverage['race_no'], 'race_id' => $raceId,
                    'status' => $coverage['status'], 'first_snapshot_at' => $coverage['first_snapshot_at'],
                    'last_snapshot_at' => $coverage['last_snapshot_at'], 'snapshot_count' => $coverage['snapshot_count'],
                    'last_checked_at' => $coverage['last_checked_at'], 'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            return ['run_id' => $report['source_run_id'], 'coverages' => count($report['coverages'])];
        }, 3);
    }

    /** @param array<string, mixed> $coverage */
    private function race(array $coverage): ?int
    {
        $mapping = DB::table('source_identifiers')->where([
            'source_system' => 'jvlink', 'entity_type' => 'venue', 'identifier_type' => 'venue_code',
            'identifier_value' => $coverage['venue_code'],
        ])->first();
        $race = $mapping === null ? null : DB::table('races')
            ->join('race_calendars', 'race_calendars.id', '=', 'races.race_calendar_id')
            ->where(['race_calendars.venue_id' => $mapping->entity_id, 'race_calendars.race_date' => $coverage['coverage_date'], 'races.race_no' => $coverage['race_no']])
            ->select('races.id')->first();

        return $race === null ? null : $race->id;
    }
}
