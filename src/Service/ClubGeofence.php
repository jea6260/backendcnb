<?php

namespace App\Service;

/**
 * Geocerca del Club Nautico Bariloche (Lago Nahuel Huapi).
 */
final class ClubGeofence
{
    public const LAT = -41.128541;
    public const LNG = -71.347641;
    public const RADIUS_METERS = 150.0;

    /**
     * @return array{ok: true, distance_m: float}|array{ok: false, error: string, distance_m: float|null}
     */
    public static function validate(?float $lat, ?float $lng, ?float $radiusMeters = null): array
    {
        if ($lat === null || $lng === null) {
            return [
                'ok' => false,
                'error' => 'Ubicacion GPS requerida. Active la ubicacion y reintente cerca del club.',
                'distance_m' => null,
            ];
        }

        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return [
                'ok' => false,
                'error' => 'Coordenadas GPS invalidas.',
                'distance_m' => null,
            ];
        }

        $distance = self::haversineMeters(self::LAT, self::LNG, $lat, $lng);
        $radius = $radiusMeters ?? self::RADIUS_METERS;

        if ($distance > $radius) {
            return [
                'ok' => false,
                'error' => sprintf(
                    'Debe estar a no mas de %.0f m del CNB Lago Nahuel Huapi (ahora a %.0f m).',
                    $radius,
                    $distance
                ),
                'distance_m' => round($distance, 1),
            ];
        }

        return ['ok' => true, 'distance_m' => round($distance, 1)];
    }

    public static function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
