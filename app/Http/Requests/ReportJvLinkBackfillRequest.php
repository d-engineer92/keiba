<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ReportJvLinkBackfillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source_run_id' => ['required', 'string', 'max:255'],
            'requested_from' => ['required', 'date_format:Y-m-d', 'after_or_equal:2008-01-01'],
            'requested_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:requested_from'],
            'actual_min_date' => ['present', 'nullable', 'date_format:Y-m-d'],
            'actual_max_date' => ['present', 'nullable', 'date_format:Y-m-d'],
            'status' => ['required', Rule::in(['running', 'completed', 'failed'])],
            'races_requested' => ['required', 'integer:strict', 'min:0'],
            'races_found' => ['required', 'integer:strict', 'min:0'],
            'snapshots_inserted' => ['required', 'integer:strict', 'min:0'],
            'started_at' => ['required', 'date'],
            'finished_at' => ['present', 'nullable', 'date'],
            'error_category' => ['present', 'nullable', 'string', 'max:255'],
            'coverages' => ['required', 'array', 'list', 'max:1000'],
            'coverages.*' => ['array:source_race_key,coverage_date,venue_code,race_no,data_kind,status,first_snapshot_at,last_snapshot_at,snapshot_count,last_checked_at'],
            'coverages.*.source_race_key' => ['required', 'string', 'regex:/^[0-9]{12}$/D'],
            'coverages.*.coverage_date' => ['required', 'date_format:Y-m-d'],
            'coverages.*.venue_code' => ['required', 'string', 'regex:/^[0-9]{2}$/D'],
            'coverages.*.race_no' => ['required', 'integer:strict', 'between:1,255'],
            'coverages.*.data_kind' => ['required', Rule::in(['win_place_timeseries'])],
            'coverages.*.status' => ['required', Rule::in(['available', 'imported', 'no_data', 'outside_provider_retention', 'error'])],
            'coverages.*.first_snapshot_at' => ['present', 'nullable', 'date'],
            'coverages.*.last_snapshot_at' => ['present', 'nullable', 'date'],
            'coverages.*.snapshot_count' => ['required', 'integer:strict', 'min:0'],
            'coverages.*.last_checked_at' => ['required', 'date'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed = ['source_run_id', 'requested_from', 'requested_to', 'actual_min_date', 'actual_max_date', 'status',
                'races_requested', 'races_found', 'snapshots_inserted', 'started_at', 'finished_at', 'error_category', 'coverages'];
            if (array_diff(array_keys($this->all()), $allowed) !== []) {
                $validator->errors()->add('payload', 'Unknown top-level fields are not allowed.');
            }
            foreach ((array) $this->input('coverages', []) as $index => $coverage) {
                if (is_array($coverage) && isset($coverage['source_race_key'], $coverage['coverage_date'], $coverage['venue_code'], $coverage['race_no'])) {
                    $expected = str_replace('-', '', (string) $coverage['coverage_date']).$coverage['venue_code'].sprintf('%02d', $coverage['race_no']);
                    if ($coverage['source_race_key'] !== $expected) {
                        $validator->errors()->add("coverages.$index.source_race_key", 'Source race key does not match its date, venue, and race number.');
                    }
                }
            }
        }];
    }
}
