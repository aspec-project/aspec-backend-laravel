<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class MemberDetailResource extends JsonResource
{
    /**
     * @param  mixed  $resource  Perfil com sector, location, weekDays, socialPlatforms, portfolios e user (id, phone) carregados.
     * @param  bool  $showContacts  Decidido pelo controller: true só para quem consulta com conta Active.
     */
    public function __construct($resource, private readonly bool $showContacts = false)
    {
        parent::__construct($resource);
    }

    /**
     * Perfil público do diretório. A montra (morada, website, horário, redes, portfólio) é pública;
     * telefone e contactos comerciais só em members_only, que é null para quem não tem conta Active.
     * O email da conta e a congregação nunca são devolvidos, para ninguém.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'business_name' => $this->business_name,
            'description' => $this->description,
            'sector' => [
                'id' => $this->sector->id,
                'name' => $this->sector->name,
            ],
            'location' => [
                'id' => $this->location->id,
                'name' => $this->location->name,
            ],
            'logo_url' => $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null,
            'website_url' => $this->website_url,
            'address' => $this->address,
            'business_hours' => BusinessHourResource::collection($this->weekDays),
            'social_links' => SocialLinkResource::collection($this->socialPlatforms),
            'portfolio' => PortfolioResource::collection($this->portfolios),
            'average_rating' => isset($this->average_rating) ? round((float) $this->average_rating, 1) : null,
            'reviews_count' => (int) ($this->reviews_count ?? 0),
            'members_only' => $this->showContacts ? [
                'phone' => $this->user->phone,
                'commercial_contacts' => $this->commercial_contacts,
            ] : null,
        ];
    }
}
