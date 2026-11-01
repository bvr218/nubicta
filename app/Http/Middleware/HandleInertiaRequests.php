<?php

namespace App\Http\Middleware;

use App\Support\Navigation;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /** @return array<string, mixed> */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => fn () => $user ? [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'initials' => $user->initials(),
                    'is_admin' => $user->hasRole('Administrador'),
                ],
                'permissions' => $user->hasRole('Administrador')
                    ? ['*']
                    : $user->getAllPermissions()->pluck('name')->values(),
            ] : null,
            'company' => fn () => $user?->company ? [
                'id' => $user->company->id,
                'name' => $user->company->business_name,
                'brand' => $user->company->show_trade_name && filled($user->company->trade_name) ? $user->company->trade_name : null,
                'nit' => $user->company->fullNit(),
                'email' => $user->company->email,
                'logo' => $user->company->logo_path ? '/storage/'.$user->company->logo_path : null,
            ] : null,
            'nav' => fn () => $user ? [
                'sidebar' => Navigation::sidebar($user),
                'create' => Navigation::createMenu($user),
                'config' => config('config_menu'),
            ] : null,
            'flash' => fn () => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'api_token' => $request->session()->get('api_token'),
                'account_tree_focus_id' => $request->session()->get('account_tree_focus_id'),
            ],
        ];
    }
}
