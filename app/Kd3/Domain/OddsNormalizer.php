<?php

namespace App\Kd3\Domain;

final class OddsNormalizer
{
    /** @return array{odds:?float,odds_min:?float,odds_max:?float,status:?string} */
    public function value(string $raw, bool $range = false): array
    {
        $value = trim($raw);
        if ($value === '') {
            return ['odds' => null, 'odds_min' => null, 'odds_max' => null, 'status' => 'missing'];
        }
        if (str_contains($value, '/')) {
            return ['odds' => null, 'odds_min' => null, 'odds_max' => null, 'status' => 'cancelled'];
        }
        if (str_contains($value, '-')) {
            return ['odds' => null, 'odds_min' => null, 'odds_max' => null, 'status' => 'not_offered'];
        }
        if (str_contains($value, '*')) {
            return ['odds' => null, 'odds_min' => null, 'odds_max' => null, 'status' => 'above_limit'];
        }
        if (preg_match('/^[0-9]+$/', $value) !== 1) {
            throw new Kd3ImportException('Invalid odds value.', 'mapping', 'race_odds');
        }
        if ($range) {
            $half = intdiv(strlen($value), 2);

            return ['odds' => null, 'odds_min' => ((int) substr($value, 0, $half)) / 10, 'odds_max' => ((int) substr($value, $half)) / 10, 'status' => null];
        }

        return ['odds' => ((int) $value) / 10, 'odds_min' => null, 'odds_max' => null, 'status' => null];
    }

    /** @return list<list<int>> */
    public function combinations(string $market): array
    {
        $values = [];
        if (in_array($market, ['win', 'place'], true)) {
            for ($a = 1; $a <= 18; $a++) {
                $values[] = [$a];
            }
        } elseif ($market === 'bracket_quinella') {
            for ($a = 1; $a <= 8; $a++) {
                for ($b = $a; $b <= 8; $b++) {
                    $values[] = [$a, $b];
                }
            }
        } elseif (in_array($market, ['quinella', 'wide'], true)) {
            for ($a = 1; $a <= 18; $a++) {
                for ($b = $a + 1; $b <= 18; $b++) {
                    $values[] = [$a, $b];
                }
            }
        } elseif ($market === 'exacta') {
            for ($a = 1; $a <= 18; $a++) {
                for ($b = 1; $b <= 18; $b++) {
                    if ($a !== $b) {
                        $values[] = [$a, $b];
                    }
                }
            }
        } elseif ($market === 'trio') {
            for ($a = 1; $a <= 18; $a++) {
                for ($b = $a + 1; $b <= 18; $b++) {
                    for ($c = $b + 1; $c <= 18; $c++) {
                        $values[] = [$a, $b, $c];
                    }
                }
            }
        } elseif ($market === 'trifecta') {
            for ($a = 1; $a <= 18; $a++) {
                for ($b = 1; $b <= 18; $b++) {
                    for ($c = 1; $c <= 18; $c++) {
                        if ($a !== $b && $a !== $c && $b !== $c) {
                            $values[] = [$a, $b, $c];
                        }
                    }
                }
            }
        }

        return $values;
    }

    /** @param list<int> $selections */
    public function key(array $selections): string
    {
        return implode('-', array_map(static fn (int $value): string => str_pad((string) $value, 2, '0', STR_PAD_LEFT), $selections));
    }
}
