<?php

namespace App\Http\Resources;

use App\Models\TimeEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TimeEntry */
class TimeEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nsr' => $this->nsr,
            'type' => $this->type,
            'type_label' => TimeEntry::TYPES[$this->type] ?? $this->type,
            'marked_at' => $this->marked_at->toIso8601String(),
            'date' => $this->marked_at->format('d/m/Y'),
            'time' => $this->marked_at->format('H:i:s'),
            'hash' => $this->hash,
        ];
    }
}
