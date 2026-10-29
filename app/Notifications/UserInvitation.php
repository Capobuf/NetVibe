<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class UserInvitation extends ResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Invito a NetVibe')
            ->greeting('Benvenuto in NetVibe')
            ->line('Hai ricevuto un invito'.($notifiable->is_superadmin ? ' come Superadmin.' : '.'))
            ->action('Imposta password', $this->resetUrl($notifiable))
            ->line('Il link scade tra '.config('auth.passwords.users.expire').' minuti. Se è scaduto, usa «Password dimenticata?» nella pagina di accesso.');
    }
}
