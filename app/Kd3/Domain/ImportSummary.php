<?php

namespace App\Kd3\Domain;

final class ImportSummary
{
    public int $inserted = 0;

    public int $updated = 0;

    public int $unchanged = 0;

    public int $skipped = 0;

    /** @return array{inserted_count:int,updated_count:int,unchanged_count:int,skipped_count:int} */
    public function counts(): array
    {
        return ['inserted_count' => $this->inserted, 'updated_count' => $this->updated, 'unchanged_count' => $this->unchanged, 'skipped_count' => $this->skipped];
    }
}
