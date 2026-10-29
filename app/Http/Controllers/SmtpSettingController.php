<?php

namespace App\Http\Controllers;

use App\Http\Requests\SmtpSettingRequest;
use App\Models\SmtpSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class SmtpSettingController extends Controller
{
    public function edit(): Response
    {
        $settings = SmtpSetting::query()->find(1);

        return Inertia::render('administration/email', [
            'settings' => $settings?->only('host', 'port', 'security', 'username', 'from_address', 'from_name'),
            'hasPassword' => $settings?->getRawOriginal('password') !== null,
        ]);
    }

    public function update(SmtpSettingRequest $request): RedirectResponse
    {
        $data = $request->validated();
        if (! $request->filled('password')) {
            unset($data['password']);
        }

        SmtpSetting::query()->updateOrCreate(['id' => 1], $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Impostazioni email salvate.']);

        return to_route('administration.email.edit');
    }

    public function sendTest(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'recipient' => ['required', 'string', 'email', 'max:255'],
        ], [], ['recipient' => 'destinatario']);

        if (! SmtpSetting::query()->whereKey(1)->exists()) {
            throw ValidationException::withMessages(['recipient' => 'Salva le impostazioni email prima di inviare una prova.']);
        }

        try {
            Mail::raw('Email di prova inviata da NetVibe.', function (Message $message) use ($data) {
                $message->to($data['recipient'])->subject('NetVibe — Email di prova');
            });
        } catch (TransportExceptionInterface) {
            // SMTP responses can contain credentials; never expose or log the exception.
            throw ValidationException::withMessages([
                'recipient' => 'Invio non riuscito. Verifica server, porta, sicurezza e credenziali, poi riprova.',
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Email di prova accettata dal server SMTP.']);

        return to_route('administration.email.edit');
    }
}
