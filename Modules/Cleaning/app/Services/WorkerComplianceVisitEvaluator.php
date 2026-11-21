<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Carbon\CarbonImmutable;
use Throwable;

final class WorkerComplianceVisitEvaluator
{
    public static function distanceMeters(float $latitudeA, float $longitudeA, float $latitudeB, float $longitudeB): float
    {
        $earthRadiusMeters = 6_371_000.0;
        $latitudeDelta = deg2rad($latitudeB - $latitudeA);
        $longitudeDelta = deg2rad($longitudeB - $longitudeA);
        $a = sin($latitudeDelta / 2) ** 2
            + cos(deg2rad($latitudeA)) * cos(deg2rad($latitudeB)) * sin($longitudeDelta / 2) ** 2;

        return 2 * $earthRadiusMeters * asin(min(1.0, sqrt($a)));
    }

    /**
     * @param  array<int, array{captured_at: mixed, latitude: mixed, longitude: mixed, accuracy_m: mixed}>  $samples
     * @return array{started_at: CarbonImmutable, ended_at: CarbonImmutable, sample_count: int, min_distance_m: float, max_accuracy_m: float, path_length_m: float}|null
     */
    public function qualifyingRun(array $samples, float $siteLatitude, float $siteLongitude): ?array
    {
        if (! $this->validCoordinate($siteLatitude, $siteLongitude)) {
            return null;
        }

        usort($samples, static function (array $left, array $right): int {
            return self::timestamp($left['captured_at'] ?? null) <=> self::timestamp($right['captured_at'] ?? null);
        });

        $run = [];
        $bestRun = null;
        foreach ($samples as $sample) {
            $capturedAt = $this->parseTimestamp($sample['captured_at'] ?? null);
            $latitude = is_numeric($sample['latitude'] ?? null) ? (float) $sample['latitude'] : NAN;
            $longitude = is_numeric($sample['longitude'] ?? null) ? (float) $sample['longitude'] : NAN;
            $accuracy = is_numeric($sample['accuracy_m'] ?? null) ? (float) $sample['accuracy_m'] : INF;

            if ($capturedAt === null
                || ! $this->validCoordinate($latitude, $longitude)
                || ! is_finite($accuracy)
                || $accuracy < 0
                || $accuracy > (float) config('cleaning.worker_compliance.maximum_accuracy_m', 50)) {
                $this->recordQualifiedRun($run, $bestRun);
                $run = [];

                continue;
            }

            $distance = self::distanceMeters($latitude, $longitude, $siteLatitude, $siteLongitude);
            if ($distance + $accuracy > (float) config('cleaning.worker_compliance.proximity_radius_m', 100)) {
                $this->recordQualifiedRun($run, $bestRun);
                $run = [];

                continue;
            }

            if ($run !== []) {
                $previous = $run[array_key_last($run)];
                $gapSeconds = $previous['captured_at']->diffInSeconds($capturedAt);
                if ($gapSeconds <= 0 || $gapSeconds > (int) config('cleaning.worker_compliance.maximum_sample_gap_seconds', 240)) {
                    $this->recordQualifiedRun($run, $bestRun);
                    $run = [];
                } else {
                    $stepDistance = self::distanceMeters(
                        $latitude,
                        $longitude,
                        $previous['latitude'],
                        $previous['longitude'],
                    );
                    $maximumPlausibleStep = max(100.0, ($gapSeconds / 60) * 100.0)
                        + $accuracy
                        + $previous['accuracy_m'];
                    if ($stepDistance > $maximumPlausibleStep) {
                        $this->recordQualifiedRun($run, $bestRun);
                        $run = [];
                    }
                }
            }

            $run[] = [
                'captured_at' => $capturedAt,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'accuracy_m' => $accuracy,
                'distance_m' => $distance,
            ];

        }

        $this->recordQualifiedRun($run, $bestRun);

        return $bestRun;
    }

    private static function timestamp(mixed $value): int
    {
        try {
            return CarbonImmutable::parse($value)->getTimestamp();
        } catch (Throwable) {
            return PHP_INT_MAX;
        }
    }

    /** Evaluate complete contiguous runs, not early prefixes of a moving path. */
    private function recordQualifiedRun(array $run, ?array &$bestRun): void
    {
        $candidate = $this->qualifyingRunSummary($run);
        if ($candidate !== null && ($bestRun === null || $candidate['sample_count'] > $bestRun['sample_count'])) {
            $bestRun = $candidate;
        }
    }

    private function qualifyingRunSummary(array $run): ?array
    {
        if (count($run) < (int) config('cleaning.worker_compliance.minimum_sample_count', 4)) {
            return null;
        }

        $startedAt = $run[0]['captured_at'];
        $endedAt = $run[array_key_last($run)]['captured_at'];
        $minimumDwellSeconds = (int) config('cleaning.worker_compliance.minimum_dwell_seconds', 600);
        if ($endedAt->getTimestamp() - $startedAt->getTimestamp() < $minimumDwellSeconds) {
            return null;
        }

        $pathLength = 0.0;
        for ($index = 1; $index < count($run); $index++) {
            $pathLength += self::distanceMeters(
                $run[$index - 1]['latitude'],
                $run[$index - 1]['longitude'],
                $run[$index]['latitude'],
                $run[$index]['longitude'],
            );
        }

        if ($pathLength > (float) config('cleaning.worker_compliance.maximum_dwell_path_m', 300)) {
            return null;
        }

        return [
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'sample_count' => count($run),
            'min_distance_m' => min(array_column($run, 'distance_m')),
            'max_accuracy_m' => max(array_column($run, 'accuracy_m')),
            'path_length_m' => $pathLength,
        ];
    }

    private function parseTimestamp(mixed $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function validCoordinate(float $latitude, float $longitude): bool
    {
        return is_finite($latitude)
            && is_finite($longitude)
            && $latitude >= -90
            && $latitude <= 90
            && $longitude >= -180
            && $longitude <= 180;
    }
}
