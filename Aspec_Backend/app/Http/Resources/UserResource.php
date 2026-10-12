<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->whenLoaded('role', [
                'id' => $this->role?->id,
                'name' => $this->role?->name,
            ]),
            'account_status' => $this->whenLoaded('accountStatus', [
                'id' => $this->accountStatus?->id,
                'name' => $this->accountStatus?->name,
            ]),
            'member_profile' => MemberProfileResource::make(
                $this->whenLoaded('memberProfile')
            ),
        ];
    }
}