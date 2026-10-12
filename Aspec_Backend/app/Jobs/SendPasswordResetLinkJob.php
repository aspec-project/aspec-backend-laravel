<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Password;

class SendPasswordResetLinkJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $userId) {}

    public function handle(): void
    {
        $user = User::query()
            ->whereKey($this->userId)
            ->whereHas('accountStatus', fn ($query) => $query->where('name', 'Active'))
            ->first();

        if (! $user) {
            return;
        }

        Password::broker()->sendResetLink([
            'email' => $user->email,
        ]);
    }
}
