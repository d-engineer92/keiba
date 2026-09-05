<?php

namespace App\Kd3;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class Kd3Downloader
{
    public function __construct(
        private readonly Kd3Gateway $gateway,
        private readonly Kd3ArtifactCatalog $catalog,
        private readonly Kd3Archive $archive,
    ) {}

    /** @return array{status: string, sha256?: string, source_file_id?: int} */
    public function download(CarbonImmutable $raceDate, string $artifactType): array
    {
        $date = $raceDate->setTimezone('Asia/Tokyo')->toDateString();
        $this->beginAttempt($date, $artifactType);

        try {
            $result = $this->gateway->fetch($raceDate, $artifactType);
            if (! $result->available) {
                $this->recordObservation($date, $artifactType, 'not_available', $result->httpStatus, null);

                return ['status' => 'not_available'];
            }

            $definition = $this->catalog->get($artifactType);
            $entryPattern = str_replace('{ymd2}', $raceDate->format('ymd'), $definition['entry_pattern']);
            $extracted = $this->archive->extract((string) $result->body, $entryPattern);
            if ($extracted === null) {
                $this->recordObservation($date, $artifactType, 'not_available', $result->httpStatus, null);

                return ['status' => 'not_available'];
            }
            $sha = hash('sha256', $extracted['contents']);
            $diskName = (string) config('kd3.storage_disk');
            $path = sprintf('kd3/raw/%s/%s/%s/%s/%s.lzh', substr($date, 0, 4), substr($date, 5, 2), $date, $artifactType, $sha);
            $disk = Storage::disk($diskName);
            $created = false;
            if (! $disk->exists($path)) {
                if (! $disk->put($path, $extracted['contents'])) {
                    throw new Kd3Exception('storage', $result->httpStatus, 'Unable to persist the LZH artifact.');
                }
                $created = true;
            }

            try {
                $sourceFileId = DB::transaction(function () use ($date, $artifactType, $extracted, $sha, $diskName, $path, $result): int {
                    DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["keiba.kd3.$date.$artifactType"]);
                    $now = CarbonImmutable::now('UTC');
                    DB::table('source_files')->insertOrIgnore([
                        'source_system' => 'kd3', 'artifact_type' => $artifactType, 'race_date' => $date,
                        'original_filename' => $extracted['name'], 'storage_disk' => $diskName, 'storage_path' => $path,
                        'sha256' => $sha, 'size_bytes' => strlen($extracted['contents']),
                        'source_url' => $this->safeSourceUrl($result->sourceUrl), 'downloaded_at' => $now,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                    $sourceFileId = DB::table('source_files')->where([
                        'source_system' => 'kd3', 'artifact_type' => $artifactType,
                        'race_date' => $date, 'sha256' => $sha,
                    ])->value('id');
                    if (! is_int($sourceFileId)) {
                        throw new Kd3Exception('database', $result->httpStatus, 'Unable to resolve the source file version.');
                    }
                    DB::table('kd3_artifact_statuses')->where([
                        'race_date' => $date, 'artifact_type' => $artifactType,
                    ])->update([
                        'status' => 'downloaded', 'latest_source_file_id' => $sourceFileId,
                        'last_checked_at' => $now, 'last_success_at' => $now,
                        'last_http_status' => $result->httpStatus, 'last_error_category' => null,
                        'updated_at' => $now,
                    ]);

                    return $sourceFileId;
                }, 3);
            } catch (Throwable $exception) {
                if ($created) {
                    $disk->delete($path);
                }
                throw $exception;
            }

            return ['status' => 'downloaded', 'sha256' => $sha, 'source_file_id' => $sourceFileId];
        } catch (Kd3Exception $exception) {
            $this->recordObservation($date, $artifactType, 'failed', $exception->httpStatus, $exception->category);
            throw $exception;
        } catch (Throwable $exception) {
            $this->recordObservation($date, $artifactType, 'failed', null, 'unexpected');
            throw new Kd3Exception('unexpected', null, 'Unexpected KD3 download failure.', $exception);
        }
    }

    private function beginAttempt(string $date, string $artifactType): void
    {
        DB::transaction(function () use ($date, $artifactType): void {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["keiba.kd3.$date.$artifactType"]);
            $row = DB::table('kd3_artifact_statuses')->where(['race_date' => $date, 'artifact_type' => $artifactType])->lockForUpdate()->first();
            $now = CarbonImmutable::now('UTC');
            if ($row === null) {
                DB::table('kd3_artifact_statuses')->insert([
                    'race_date' => $date, 'artifact_type' => $artifactType, 'status' => 'pending',
                    'attempt_count' => 1, 'created_at' => $now, 'updated_at' => $now,
                ]);
            } else {
                DB::table('kd3_artifact_statuses')->where('id', $row->id)->update([
                    'attempt_count' => $row->attempt_count + 1, 'updated_at' => $now,
                ]);
            }
        }, 3);
    }

    private function recordObservation(string $date, string $artifactType, string $status, ?int $httpStatus, ?string $category): void
    {
        $now = CarbonImmutable::now('UTC');
        DB::table('kd3_artifact_statuses')->where(['race_date' => $date, 'artifact_type' => $artifactType])->update([
            'status' => $status, 'last_checked_at' => $now,
            'last_http_status' => $httpStatus, 'last_error_category' => $category,
            'updated_at' => $now,
        ]);
    }

    private function safeSourceUrl(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return '';
        }
        $safe = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').($parts['path'] ?? '');
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
            foreach (array_keys($query) as $key) {
                if (preg_match('/pass|token|auth|session|cookie|secret/i', (string) $key) === 1) {
                    unset($query[$key]);
                }
            }
            if ($query !== []) {
                $safe .= '?'.http_build_query($query);
            }
        }

        return $safe;
    }
}
