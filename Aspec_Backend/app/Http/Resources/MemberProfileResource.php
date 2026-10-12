<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class MemberProfileResource extends JsonResource
{
    /**
     * Perfil do próprio membro, com contactos, horário, redes sociais e portfólio.
     * Não expõe ids internos (user_id, role_id, account_status_id) nem paths de ficheiros.
     * Espera as relações user, sector, location, weekDays, socialPlatforms e portfolios carregadas.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'business_name' => $this->business_name,
            'congregation' => $this->congregation,
            'role_in_congregation' => $this->role_in_congregation,
            'description' => $this->description,
            'website_url' => $this->website_url,
            'commercial_contacts' => $this->commercial_contacts,
            'address' => $this->address,
            'email' => $this->user->email,
            'phone' => $this->user->phone,
            'sector' => [
                'id' => $this->sector->id,
                'name' => $this->sector->name,
            ],
            'location' => [
                'id' => $this->location->id,
                'name' => $this->location->name,
            ],
            'logo_url' => $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null,
            'business_hours' => $this->weekDays->map(fn ($weekDay) => [
                'week_day_id' => $weekDay->id,
                'week_day' => $weekDay->name,
                'open_time' => Carbon::parse($weekDay->pivot->open_time)->format('H:i'),
                'close_time' => Carbon::parse($weekDay->pivot->close_time)->format('H:i'),
            ])->values(),
            'social_links' => $this->socialPlatforms->map(fn ($platform) => [
                'platform_id' => $platform->id,
                'platform' => $platform->name,
                'url' => $platform->pivot->url,
            ])->values(),
            'portfolio' => PortfolioResource::collection($this->portfolios),
        ];
    }
}
