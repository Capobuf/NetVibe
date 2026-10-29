<?php

namespace App\Http\Controllers;

use App\Actions\DeleteUser;
use App\Models\Company;
use App\Models\User;
use App\Notifications\UserInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class UserController extends Controller
{
    public function index(Request $request, ?Company $company = null): Response
    {
        $options = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', 'in:name,email,is_superadmin,companies_count'],
            'direction' => ['sometimes', 'in:asc,desc'],
        ]);
        $search = $options['search'] ?? '';
        $sort = $options['sort'] ?? null;
        $direction = $sort === null ? 'asc' : ($options['direction'] ?? 'asc');
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';

        return Inertia::render('users/index', [
            'company' => $company?->only('id', 'name'),
            'users' => User::query()
                ->select('id', 'name', 'email', 'is_superadmin')
                ->with(['companies' => fn ($query) => $query->select('companies.id', 'name')->orderBy('name')])
                ->withCount('companies')
                ->when($company, fn ($query) => $query->where('is_superadmin', false)
                    ->whereHas('companies', fn ($query) => $query->whereKey($company->id)))
                ->when($search !== '', fn ($query) => $query->where(function ($query) use ($pattern) {
                    $query->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("email LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereHas('companies', fn ($query) => $query->whereRaw("name LIKE ? ESCAPE '!'", [$pattern]));
                }))
                ->orderBy($sort ?? 'name', $direction)->orderBy('id')->paginate(20)->withQueryString(),
            'query' => compact('search', 'sort', 'direction'),
            'superadminCount' => User::query()->where('is_superadmin', true)->count(),
        ]);
    }

    public function store(Request $request, ?Company $company = null): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);
        $email = Str::lower($data['email']);

        [$user, $created, $attached] = DB::transaction(function () use ($email, $company) {
            $user = User::query()->lockForUpdate()->firstOrCreate(['email' => $email], [
                'name' => $email,
                'password' => Str::random(64),
            ]);
            $created = $user->wasRecentlyCreated;
            $user = User::query()->lockForUpdate()->findOrFail($user->id);

            if ($company === null) {
                if (! $created && ! $user->is_superadmin) {
                    throw ValidationException::withMessages(['email' => 'Esiste già un utente aziendale con questa email. L’invito non modifica il tipo di account.']);
                }
                if ($created) {
                    $user->forceFill(['is_superadmin' => true])->save();
                }
            }

            $attached = $company !== null && ! $user->is_superadmin
                ? $user->companies()->syncWithoutDetaching([$company->id])['attached']
                : [];

            return [$user, $created, $attached];
        }, 3);

        if ($created) {
            try {
                $status = Password::broker()->sendResetLink(['email' => $user->email], function (User $user, string $token) {
                    $user->notify(new UserInvitation($token));
                });
            } catch (Throwable $exception) {
                report($exception);
                throw ValidationException::withMessages(['email' => 'Account creato, ma invio dell’invito non riuscito. Verifica la posta configurata; l’utente può richiedere il link da «Password dimenticata?».']);
            }

            if ($status !== Password::ResetLinkSent) {
                throw ValidationException::withMessages(['email' => 'Account creato. Il link non è stato inviato: '.__($status)]);
            }
            $message = 'Invito inviato.';
        } elseif ($user->is_superadmin) {
            $message = 'Questo account è già Superadmin e ha accesso a tutte le aziende.';
        } else {
            $message = $attached ? 'Utente esistente associato all’azienda.' : 'L’utente è già associato a questa azienda.';
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }

    public function destroy(Request $request, User $user, DeleteUser $deleteUser): RedirectResponse
    {
        $request->validate(['confirmed' => ['accepted']]);
        $self = $request->user()->is($user);
        $deleteUser->handle($user);

        if ($self) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return to_route('login');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Account eliminato da tutte le aziende.']);

        return back();
    }
}
