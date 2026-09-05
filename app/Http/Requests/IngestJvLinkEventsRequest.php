<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class IngestJvLinkEventsRequest extends FormRequest
{
    private const EVENT_KEYS = [
        'source_event_id', 'event_type', 'source_data_spec', 'source_record_type',
        'source_published_at', 'effective_at', 'captured_at', 'payload_sha256', 'payload',
    ];

    /** @var array<string, array<int, string>> */
    private const PAYLOAD_KEYS = [
        'odds_snapshot' => ['race_date', 'venue_code', 'race_no', 'jvlink_race_id', 'source_kind', 'snapshot_at', 'items'],
        'runner_status' => ['race_date', 'venue_code', 'race_no', 'jvlink_race_id', 'horse_no', 'status_type', 'reason_code'],
        'jockey_change' => ['race_date', 'venue_code', 'race_no', 'jvlink_race_id', 'horse_no', 'old_jockey_code', 'old_jockey_name', 'new_jockey_code', 'new_jockey_name'],
        'body_weight' => ['race_date', 'venue_code', 'race_no', 'jvlink_race_id', 'horse_no', 'body_weight', 'body_weight_delta', 'source_status_code'],
        'weather_track' => ['race_date', 'venue_code', 'change_type', 'weather', 'turf_condition', 'dirt_condition'],
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $timestamp = ['string', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,7})?(?:Z|[+-]\d{2}:\d{2})$/D'];

        return [
            'events' => ['required', 'array', 'list', 'min:1', 'max:500'],
            'events.*' => ['required', 'array'],
            'events.*.source_event_id' => ['required', 'string', 'max:255'],
            'events.*.event_type' => ['required', 'string', Rule::in(array_keys(self::PAYLOAD_KEYS))],
            'events.*.source_data_spec' => ['present', 'nullable', 'string', 'max:32'],
            'events.*.source_record_type' => ['present', 'nullable', 'string', 'max:8'],
            'events.*.source_published_at' => ['present', 'nullable', ...$timestamp],
            'events.*.effective_at' => ['present', 'nullable', ...$timestamp],
            'events.*.captured_at' => ['required', ...$timestamp],
            'events.*.payload_sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'events.*.payload' => ['required', 'array'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), ['events']) !== []) {
                $validator->errors()->add('payload', 'Unknown top-level fields are not allowed.');
            }
            foreach ((array) $this->input('events', []) as $index => $event) {
                if (! is_array($event)) {
                    continue;
                }
                if (array_diff(array_keys($event), self::EVENT_KEYS) !== []) {
                    $validator->errors()->add("events.$index", 'Unknown event fields are not allowed.');
                }
                $type = $event['event_type'] ?? null;
                $payload = $event['payload'] ?? null;
                if (! is_string($type) || ! isset(self::PAYLOAD_KEYS[$type]) || ! is_array($payload)) {
                    continue;
                }
                if (array_diff(array_keys($payload), self::PAYLOAD_KEYS[$type]) !== []) {
                    $validator->errors()->add("events.$index.payload", 'Unknown payload fields are not allowed.');
                }
                $this->validatePayload($validator, $index, $type, $payload);
            }
        }];
    }

    /** @param array<string, mixed> $payload */
    private function validatePayload(Validator $validator, int $index, string $type, array $payload): void
    {
        $required = match ($type) {
            'odds_snapshot' => ['race_date', 'venue_code', 'race_no', 'jvlink_race_id', 'source_kind', 'snapshot_at', 'items'],
            'runner_status' => ['race_date', 'venue_code', 'race_no', 'jvlink_race_id', 'horse_no', 'status_type', 'reason_code'],
            'jockey_change' => self::PAYLOAD_KEYS['jockey_change'],
            'body_weight' => self::PAYLOAD_KEYS['body_weight'],
            'weather_track' => self::PAYLOAD_KEYS['weather_track'],
            default => [],
        };
        foreach ($required as $key) {
            if (! array_key_exists($key, $payload)) {
                $validator->errors()->add("events.$index.payload.$key", 'The field must be present.');
            }
        }
        $date = $payload['race_date'] ?? null;
        $parsedDate = is_string($date) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
        if (! is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1 || $parsedDate === false || $parsedDate->format('Y-m-d') !== $date) {
            $validator->errors()->add("events.$index.payload.race_date", 'The race date is invalid.');
        }
        if (! is_string($payload['venue_code'] ?? null) || preg_match('/^[0-9]{2}$/D', $payload['venue_code']) !== 1) {
            $validator->errors()->add("events.$index.payload.venue_code", 'The venue code must contain two digits.');
        }
        if ($type !== 'weather_track' && (! is_int($payload['race_no'] ?? null) || $payload['race_no'] < 1 || $payload['race_no'] > 255)) {
            $validator->errors()->add("events.$index.payload.race_no", 'The race number is invalid.');
        }
        if ($type !== 'weather_track' && ($payload['jvlink_race_id'] ?? null) !== null
            && (! is_string($payload['jvlink_race_id']) || strlen($payload['jvlink_race_id']) > 255)) {
            $validator->errors()->add("events.$index.payload.jvlink_race_id", 'The JV-Link race identifier is invalid.');
        }
        if (array_key_exists('horse_no', $payload) && (! is_int($payload['horse_no']) || $payload['horse_no'] < 1 || $payload['horse_no'] > 28)) {
            $validator->errors()->add("events.$index.payload.horse_no", 'The horse number is invalid.');
        }
        if ($type === 'odds_snapshot') {
            if (! in_array($payload['source_kind'] ?? null, ['historical_timeseries', 'realtime'], true)) {
                $validator->errors()->add("events.$index.payload.source_kind", 'The source kind is invalid.');
            }
            if (($payload['snapshot_at'] ?? null) !== null && (! is_string($payload['snapshot_at']) || date_create_immutable($payload['snapshot_at']) === false)) {
                $validator->errors()->add("events.$index.payload.snapshot_at", 'The snapshot timestamp is invalid.');
            }
            $items = $payload['items'] ?? null;
            if (! is_array($items) || ! array_is_list($items) || count($items) < 1 || count($items) > 56) {
                $validator->errors()->add("events.$index.payload.items", 'Odds items must be a list of 1 to 56 items.');
            } else {
                foreach ($items as $itemIndex => $item) {
                    $keys = ['bet_type', 'horse_no', 'odds', 'odds_min', 'odds_max', 'status'];
                    if (! is_array($item) || array_diff(array_keys($item), $keys) !== [] || array_diff($keys, array_keys($item)) !== []) {
                        $validator->errors()->add("events.$index.payload.items.$itemIndex", 'Odds item fields are invalid.');

                        continue;
                    }
                    if (! in_array($item['bet_type'], ['win', 'place'], true) || ! is_int($item['horse_no']) || $item['horse_no'] < 1 || $item['horse_no'] > 28) {
                        $validator->errors()->add("events.$index.payload.items.$itemIndex", 'Odds item key is invalid.');
                    }
                    foreach (['odds', 'odds_min', 'odds_max'] as $field) {
                        if ($item[$field] !== null && (! is_numeric($item[$field]) || $item[$field] < 0 || $item[$field] > 99999.9)) {
                            $validator->errors()->add("events.$index.payload.items.$itemIndex.$field", 'The odds value is invalid.');
                        }
                    }
                    if ($item['status'] !== null && (! is_string($item['status']) || strlen($item['status']) > 255)) {
                        $validator->errors()->add("events.$index.payload.items.$itemIndex.status", 'The odds status is invalid.');
                    }
                }
            }
        }
        if ($type === 'runner_status' && ! in_array($payload['status_type'] ?? null, ['cancelled', 'excluded'], true)) {
            $validator->errors()->add("events.$index.payload.status_type", 'The runner status is invalid.');
        }
        if ($type === 'body_weight') {
            $weight = $payload['body_weight'] ?? null;
            $delta = $payload['body_weight_delta'] ?? null;
            if ($weight !== null && (! is_int($weight) || $weight < 2 || $weight > 998)) {
                $validator->errors()->add("events.$index.payload.body_weight", 'The body weight is invalid.');
            }
            if ($delta !== null && (! is_int($delta) || abs($delta) > 998)) {
                $validator->errors()->add("events.$index.payload.body_weight_delta", 'The body weight delta is invalid.');
            }
        }
        if ($type === 'jockey_change') {
            foreach (['old_jockey_code', 'new_jockey_code'] as $field) {
                $value = $payload[$field] ?? null;
                if ($value !== null && (! is_string($value) || preg_match('/^[0-9]{5}$/D', $value) !== 1)) {
                    $validator->errors()->add("events.$index.payload.$field", 'The jockey code is invalid.');
                }
            }
            foreach (['old_jockey_name', 'new_jockey_name'] as $field) {
                $value = $payload[$field] ?? null;
                if ($value !== null && (! is_string($value) || mb_strlen($value) > 255)) {
                    $validator->errors()->add("events.$index.payload.$field", 'The jockey name is invalid.');
                }
            }
        }
    }
}
