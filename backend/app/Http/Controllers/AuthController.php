<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function registrationStatus(): JsonResponse
    {
        return response()->json([
            'registration_available' => $this->isInitialRegistrationAvailable(),
        ])->header('Cache-Control', 'no-store');
    }

    public function register(Request $request): JsonResponse
    {
        // Atalho preservado: registro já fechado responde 403 antes de
        // validar (ver SecurityRefactorTest::test_register_is_throttled_after_five_attempts).
        // Não é a checagem autoritativa — duas requisições simultâneas passam
        // por aqui juntas; quem decide é o gate transacional abaixo.
        if (! $this->isInitialRegistrationAvailable()) {
            abort(403, 'Registro inicial indisponível.');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users'],
            'password' => ['required', 'string', 'min:8'],
            'company' => ['required', 'string', 'max:255'],
            'size' => ['required', 'string', 'max:50'],
        ]);

        $user = DB::transaction(function () use ($data) {
            // O gate de "registro inicial" é reavaliado dentro da transação:
            // uma checagem de leitura fora dela deixa dois POST /api/register
            // simultâneos verem o mesmo estado vazio. Atenção ao alcance real
            // do lock: Account::lockForUpdate() serializa Transactions que
            // disputam linhas existentes, mas no primeiro registro a tabela
            // está vazia por definição e o Postgres não trava nada sobre um
            // result set vazio. O que fecha a corrida na prática é o índice
            // único de users.email — o perdedor da corrida estoura a
            // constraint e a transação inteira é desfeita. Para uma garantia
            // dura, independente do schema, o lock teria que ser um advisory
            // lock (pg_advisory_xact_lock), que funciona com a tabela vazia.
            Account::lockForUpdate()->count();

            if (! $this->isInitialRegistrationAvailable()) {
                abort(403, 'Registro inicial indisponível.');
            }

            $user = new User;
            $user->name = $data['name'];
            $user->email = $data['email'];
            $user->password = $data['password'];
            $user->is_super_admin = true;
            $user->save();

            $account = Account::create(['name' => $data['company']]);
            $account->members()->attach($user, ['role' => 'admin']);
            $user->current_account_id = $account->getKey();
            $user->save();

            return $user;
        });

        Auth::login($user);

        return response()->json($user->load('currentAccount'), 201);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => ['As credenciais informadas são inválidas.'],
            ]);
        }

        $request->session()->regenerate();

        return response()->json($request->user()->load('currentAccount'));
    }

    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->load(['currentAccount', 'accountLinks.account']);

        $accounts = $user->accountLinks->map(fn ($link): array => [
            'id' => $link->account->getKey(),
            'name' => $link->account->name,
            'role' => $link->role,
        ])->all();

        return response()->json([
            'id' => $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
            'is_super_admin' => $user->is_super_admin,
            'accounts' => $accounts,
            'current_account' => $user->currentAccount,
        ]);
    }

    private function isInitialRegistrationAvailable(): bool
    {
        return ! User::exists() && ! Account::exists();
    }
}
