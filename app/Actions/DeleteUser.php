<?php

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class DeleteUser
{
    public function handle(User $user): void
    {
        DB::transaction(function () use ($user) {
            // Lock the same ordered set for every deletion, including self-deletion.
            $superadmins = User::query()->where('is_superadmin', true)->orderBy('id')->lockForUpdate()->get(['id']);
            $user = User::query()->lockForUpdate()->findOrFail($user->id);

            if ($user->is_superadmin && $superadmins->count() === 1) {
                throw ValidationException::withMessages([
                    'user' => 'Non puoi eliminare l’ultimo Superadmin.',
                ]);
            }

            Password::broker()->deleteToken($user);
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->delete();
        }, 3);
    }
}
