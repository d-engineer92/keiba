<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class IngestSchedulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $timestamp = ['string', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,7})?(?:Z|[+-]\d{2}:\d{2})$/D'];

        return [
            'captured_at' => ['required', ...$timestamp],
            'schedules' => ['required', 'array', 'list', 'min:1', 'max:1000'],
            'schedules.*' => ['required', 'array:venue_code,venue_name,race_date,meeting_no,meeting_day,status,source_updated_at'],
            'schedules.*.venue_code' => ['required', 'string', 'regex:/^[0-9]{2}$/D'],
            'schedules.*.venue_name' => ['present', 'nullable', 'string', 'max:255'],
            'schedules.*.race_date' => ['required', 'date_format:Y-m-d'],
            'schedules.*.meeting_no' => ['present', 'nullable', 'integer:strict', 'between:1,32767'],
            'schedules.*.meeting_day' => ['present', 'nullable', 'integer:strict', 'between:1,32767'],
            'schedules.*.status' => ['required', 'string', Rule::in(['scheduled', 'completed', 'cancelled', 'deleted'])],
            'schedules.*.source_updated_at' => ['present', 'nullable', ...$timestamp],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), ['captured_at', 'schedules']) !== []) {
                $validator->errors()->add('payload', 'Unknown top-level fields are not allowed.');
            }
        }];
    }
}
