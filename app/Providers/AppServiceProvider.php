<?php

namespace App\Providers;

use App\Models\Company;
use App\Models\SmtpSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('administer', fn (User $user): bool => $user->is_superadmin === true);
        Gate::define('access-company', fn (User $user, Company $company): bool => Company::query()->accessibleTo($user)->whereKey($company->id)->exists());

        RateLimiter::for('smtp-test', fn (Request $request) => Limit::perMinute(3)
            ->by($request->user()->id)
            ->response(fn () => back()->withErrors(['recipient' => 'Troppi tentativi. Attendi un minuto e riprova.'])));

        // Resolve persisted settings only when sending mail, including with cached configuration.
        $this->app->resolving('mail.manager', function (MailManager $manager): void {
            $settings = SmtpSetting::query()->find(1);
            if ($settings === null) {
                return;
            }

            config([
                'mail.default' => 'smtp',
                'mail.mailers.smtp' => [
                    'transport' => 'smtp',
                    'scheme' => $settings->security === 'tls' ? 'smtps' : 'smtp',
                    'host' => $settings->host,
                    'port' => $settings->port,
                    'auto_tls' => $settings->security === 'starttls',
                    'require_tls' => $settings->security !== 'none',
                    'username' => $settings->username,
                    'password' => $settings->password,
                    'timeout' => 10,
                    'local_domain' => config('mail.mailers.smtp.local_domain'),
                ],
                'mail.from.address' => $settings->from_address,
                'mail.from.name' => $settings->from_name,
            ]);

            if ($settings->security === 'starttls') {
                // Symfony otherwise infers implicit TLS on port 465, regardless of the selected mode.
                $manager->mailer('smtp')->getSymfonyTransport()->getStream()->disableTls();
            }
        });

        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(8)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
