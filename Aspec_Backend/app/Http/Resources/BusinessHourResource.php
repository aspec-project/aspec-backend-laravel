<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class BusinessHourResource extends JsonResource
{
    /**
     * Horário de um dia: recebe o WeekDay da relação weekDays do perfil, com as horas no pivot,
     * e devolve-as no formato H:i.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'week_day_id' => $this->id,
            'week_day' => $this->name,
            'open_time' => Carbon::parse($this->pivot->open_time)->format('H:i'),
            'close_time' => Carbon::parse($this->pivot->close_time)->format('H:i'),
        ];
    }
}
