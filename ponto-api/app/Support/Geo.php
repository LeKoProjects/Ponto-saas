<?php

namespace App\Support;

/** Cálculos de distância para a cerca virtual. */
class Geo
{
    private const EARTH_RADIUS_M = 6_371_000;

    /** Folga máxima pela imprecisão do GPS (metros). */
    public const MAX_ACCURACY_TOLERANCE = 50;

    /** Distância em metros entre dois pontos (fórmula de Haversine). */
    public static function distance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_M * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Local mais próximo e se a posição está dentro do raio dele.
     * A precisão informada pelo aparelho dá uma folga de até 50 m (GPS dentro de prédios oscila).
     *
     * @param  array<int, array{name: string, latitude: float, longitude: float, radius_m: int}>  $locations
     * @return array{inside: bool, location: array, distance: float}|null
     */
    public static function nearest(array $locations, float $lat, float $lng, ?float $accuracy = null): ?array
    {
        $best = null;
        foreach ($locations as $loc) {
            $d = self::distance($lat, $lng, $loc['latitude'], $loc['longitude']);
            if (! $best || $d - $loc['radius_m'] < $best['distance'] - $best['location']['radius_m']) {
                $best = ['location' => $loc, 'distance' => $d];
            }
        }
        if (! $best) {
            return null;
        }
        $tolerance = min(max($accuracy ?? 0, 0), self::MAX_ACCURACY_TOLERANCE);
        $best['inside'] = $best['distance'] <= $best['location']['radius_m'] + $tolerance;

        return $best;
    }

    /** "850 m" ou "1,2 km". */
    public static function human(float $meters): string
    {
        return $meters < 1000
            ? number_format(round($meters / 10) * 10, 0, ',', '.').' m'
            : number_format($meters / 1000, 1, ',', '.').' km';
    }
}
