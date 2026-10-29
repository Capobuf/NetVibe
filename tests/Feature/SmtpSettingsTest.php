<?php

namespace Tests\Feature;

use App\Models\SmtpSetting;
use App\Models\User;
use App\Notifications\UserInvitation;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Tests\TestCase;

class SmtpSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_superadmins_can_read_save_and_test_smtp_settings(): void
    {
        $this->get(route('administration.email.edit'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['is_superadmin' => false]));

        $this->get(route('administration.email.edit'))->assertForbidden();
        $this->put(route('administration.email.update'), $this->settings())->assertForbidden();
        $this->post(route('administration.email.test'), ['recipient' => 'recipient@example.com'])->assertForbidden();

        $this->assertDatabaseCount('smtp_settings', 0);
    }

    public function test_password_is_encrypted_hidden_preserved_when_empty_and_replaceable(): void
    {
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
        $data = $this->settings();

        $this->put(route('administration.email.update'), $data)->assertSessionHasNoErrors()->assertRedirect();
        $ciphertext = DB::table('smtp_settings')->value('password');
        $this->assertNotSame($data['password'], $ciphertext);
        $this->assertSame($data['password'], Crypt::decryptString($ciphertext));
        $this->get(route('administration.email.edit'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('administration/email')
            ->where('settings.host', 'smtp.example.com')->where('hasPassword', true)
            ->missing('settings.password'));
        $this->assertArrayNotHasKey('password', SmtpSetting::findOrFail(1)->toArray());

        $this->put(route('administration.email.update'), [...$data, 'host' => 'relay.example.com', 'password' => ''])
            ->assertSessionHasNoErrors();
        $this->assertSame($ciphertext, DB::table('smtp_settings')->value('password'));
        $this->assertDatabaseHas('smtp_settings', ['id' => 1, 'host' => 'relay.example.com']);

        $this->put(route('administration.email.update'), [...$data, 'password' => 'replacement-secret'])
            ->assertSessionHasNoErrors();
        $this->assertSame('replacement-secret', SmtpSetting::findOrFail(1)->password);
        $this->assertDatabaseCount('smtp_settings', 1);
    }

    public function test_invalid_settings_are_rejected_and_credentials_are_optional(): void
    {
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));

        $this->put(route('administration.email.update'), [
            ...$this->settings(), 'host' => 'smtp://user:secret@example.com', 'port' => 65536,
            'security' => 'invalid', 'from_address' => 'invalid',
        ])->assertSessionHasErrors(['host', 'port', 'security', 'from_address'])
            ->assertSessionMissing('_old_input.password');
        $this->assertDatabaseCount('smtp_settings', 0);

        $this->put(route('administration.email.update'), [...$this->settings(), 'username' => '', 'password' => ''])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('smtp_settings', ['id' => 1, 'username' => null, 'password' => null]);
    }

    public function test_existing_mail_configuration_is_preserved_until_settings_are_saved(): void
    {
        config(['mail.default' => 'array', 'mail.from.address' => 'existing@example.com']);

        $this->assertInstanceOf(ArrayTransport::class, Mail::mailer()->getSymfonyTransport());
        $this->assertSame('existing@example.com', config('mail.from.address'));

        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
        $this->post(route('administration.email.test'), ['recipient' => 'recipient@example.com'])
            ->assertSessionHasErrors('recipient');
    }

    #[TestWith(['none', false, false, false])]
    #[TestWith(['starttls', true, true, false])]
    #[TestWith(['tls', false, true, true])]
    public function test_saved_transport_and_sender_apply_to_authentication_notifications(
        string $security, bool $autoTls, bool $requireTls, bool $implicitTls,
    ): void {
        config(['mail.default' => 'log', 'mail.mailers.smtp.url' => 'smtp://obsolete.example.com:25']);
        SmtpSetting::create(['id' => 1, ...$this->settings(), 'security' => $security, 'port' => 465]);

        $mailer = Mail::mailer();
        $smtp = $mailer->getSymfonyTransport();
        $this->assertInstanceOf(EsmtpTransport::class, $smtp);
        $this->assertSame('smtp.example.com', $smtp->getStream()->getHost());
        $this->assertSame(465, $smtp->getStream()->getPort());
        $this->assertSame(10.0, $smtp->getStream()->getTimeout());
        $this->assertSame('smtp-user', $smtp->getUsername());
        $this->assertSame('smtp-secret', $smtp->getPassword());
        $this->assertSame($autoTls, $smtp->isAutoTls());
        $this->assertSame($requireTls, $smtp->isTlsRequired());
        $this->assertSame($implicitTls, $smtp->getStream()->isTls());

        // Keep the configured Laravel mailer and replace only network delivery.
        $mailer->setSymfonyTransport($transport = new ArrayTransport);
        $user = User::factory()->unverified()->create();
        $user->notify(new VerifyEmail);
        $user->notify(new ResetPassword('reset-token'));
        $user->notify(new UserInvitation('invite-token'));

        $this->assertCount(3, $transport->messages());
        foreach ($transport->messages() as $sent) {
            $message = $sent->getOriginalMessage();
            $this->assertSame('sender@example.com', $message->getFrom()[0]->getAddress());
            $this->assertSame('NetVibe test', $message->getFrom()[0]->getName());
            $this->assertSame($user->email, $message->getTo()[0]->getAddress());
        }
    }

    public function test_test_email_uses_saved_settings_and_is_rate_limited(): void
    {
        SmtpSetting::create(['id' => 1, ...$this->settings()]);
        Mail::mailer()->setSymfonyTransport($transport = new ArrayTransport);
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->post(route('administration.email.test'), [
                'recipient' => 'recipient@example.com', 'host' => 'unsaved.example.com',
            ])->assertSessionHasNoErrors()->assertRedirect(route('administration.email.edit'));
        }
        $this->post(route('administration.email.test'), ['recipient' => 'recipient@example.com'])
            ->assertSessionHasErrors('recipient');

        $this->assertCount(3, $transport->messages());
        $message = $transport->messages()->first()->getOriginalMessage();
        $this->assertSame('recipient@example.com', $message->getTo()[0]->getAddress());
        $this->assertSame('sender@example.com', $message->getFrom()[0]->getAddress());
        $this->assertSame('NetVibe — Email di prova', $message->getSubject());
    }

    public function test_smtp_failure_does_not_expose_the_server_reply(): void
    {
        SmtpSetting::create(['id' => 1, ...$this->settings()]);
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
        Mail::shouldReceive('raw')->once()->andThrow(new TransportException('Rejected smtp-secret'));

        $this->post(route('administration.email.test'), ['recipient' => 'recipient@example.com'])
            ->assertSessionHasErrors(['recipient' => 'Invio non riuscito. Verifica server, porta, sicurezza e credenziali, poi riprova.']);
    }

    public function test_notification_failures_are_redacted_in_responses_and_logs(): void
    {
        SmtpSetting::create(['id' => 1, ...$this->settings()]);
        $transport = Mockery::mock(TransportInterface::class);
        $transport->shouldReceive('send')->once()->andThrow(new TransportException('Rejected smtp-secret'));
        Mail::mailer()->setSymfonyTransport($transport);
        Log::shouldReceive('warning')->once()->with('Invio email SMTP non riuscito.');

        $this->actingAs(User::factory()->unverified()->create())
            ->from(route('verification.notice'))
            ->post(route('verification.send'))
            ->assertRedirect(route('verification.notice'))
            ->assertSessionHasErrors(['email' => 'Invio email non riuscito. Riprova più tardi o contatta l’amministratore.']);
    }

    /** @return array<string, int|string> */
    private function settings(): array
    {
        return [
            'host' => 'smtp.example.com', 'port' => 587, 'security' => 'starttls',
            'username' => 'smtp-user', 'password' => 'smtp-secret',
            'from_address' => 'sender@example.com', 'from_name' => 'NetVibe test',
        ];
    }
}
