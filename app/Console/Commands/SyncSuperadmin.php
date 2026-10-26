<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SyncSuperadmin extends Command
{
    protected $signature = 'netvibe:superadmin';

    protected $description = 'Crea o aggiorna il superadmin con le credenziali configurate nel .env';

    public function handle(): int
    {
        $user = User::where('is_superadmin', true)->first() ?? new User;

        $validator = Validator::make(config('superadmin'), [
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)->ignore($user->id)],
            'password' => ['required', 'string', 'min:8'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        // Identify the account independently of its email; never promote an existing ordinary user.
        $user->forceFill([
            ...$validator->validated(),
            'name' => $user->name ?? 'Superadmin',
            'is_superadmin' => true,
            'email_verified_at' => now(),
            'remember_token' => Str::random(60),
        ])->save();

        $this->info('Superadmin aggiornato dalla configurazione.');

        return self::SUCCESS;
    }
}
