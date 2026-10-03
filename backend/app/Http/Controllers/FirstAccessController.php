<?php

namespace App\Http\Controllers;

use App\Services\AdminAccountProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class FirstAccessController extends Controller
{
    public function status(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'min:32'],
            'email' => ['required', 'email'],
        ]);

        $account = AdminAccountProvisioner::findPendingOwnerInvite($data['email']);

        if ($account === null) {
            return response()->json(['valid' => false])->header('Cache-Control', 'no-store');
        }

        $meta = AdminAccountProvisioner::pendingInviteMeta($account, $data['token']);

        if ($meta === null) {
            return response()->json(['valid' => false])->header('Cache-Control', 'no-store');
        }

        return response()->json([
            'valid' => true,
            'owner_name' => $meta['name'],
            'account_name' => $meta['account_name'],
        ])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, AdminAccountProvisioner $provisioner): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'min:32'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $account = AdminAccountProvisioner::findPendingOwnerInvite($data['email']);

        if ($account === null) {
            throw ValidationException::withMessages([
                'email' => ['Convite inválido ou expirado.'],
            ]);
        }

        $user = $provisioner->completeFirstAccess(
            $account,
            $data['token'],
            $data['email'],
            $data['password']
        );

        Auth::guard('web')->login($user);

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return response()->json($user->load('currentAccount'), 201);
    }
}
