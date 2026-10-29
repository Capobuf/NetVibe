<?php

namespace App\Http\Controllers;

use App\Actions\DeleteUser;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

class CompanyUserController extends Controller
{
    public function index(Request $request, Company $company, UserController $users): Response
    {
        return $users->index($request, $company);
    }

    public function store(Request $request, Company $company, UserController $users): RedirectResponse
    {
        return $users->store($request, $company);
    }

    public function destroy(Request $request, Company $company, User $user, UserController $users, DeleteUser $deleteUser): RedirectResponse
    {
        abort_if($user->is_superadmin, 404);

        return $users->destroy($request, $user, $deleteUser);
    }
}
