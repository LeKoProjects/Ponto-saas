<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** Local permitido para bater o ponto (cerca virtual). */
class WorkLocation extends Model
{
    use BelongsToCompany;

    public const MIN_RADIUS = 30;

    public const MAX_RADIUS = 5000;

    protected $fillable = ['company_id', 'name', 'latitude', 'longitude', 'radius_m'];

    protected function casts(): array
    {
        return ['latitude' => 'float', 'longitude' => 'float', 'radius_m' => 'integer'];
    }

    /** @return array{id: int, name: string, latitude: float, longitude: float, radius_m: int} */
    public function toContext(): array
    {
        return $this->only(['id', 'name', 'latitude', 'longitude', 'radius_m']);
    }
}
