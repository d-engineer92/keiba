<?php

namespace App\Kd3;

use InvalidArgumentException;

final class Kd3ArtifactCatalog
{
    /** @return list<string> */
    public function types(): array
    {
        return array_keys(config('kd3.artifacts'));
    }

    /** @return array{entry_pattern: string} */
    public function get(string $type): array
    {
        $definition = config("kd3.artifacts.$type");
        if (! is_array($definition)) {
            throw new InvalidArgumentException("Unknown KD3 artifact type: $type");
        }

        $pattern = $definition['entry_pattern'] ?? null;
        if (! is_string($pattern)) {
            throw new Kd3Exception('configuration', null, "Entry pattern is not configured for artifact type $type.");
        }

        return ['entry_pattern' => $pattern];
    }
}
