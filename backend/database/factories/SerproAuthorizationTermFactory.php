<?php

namespace Database\Factories;

use App\Enums\SerproAuthorizationTermState;
use App\Models\Account;
use App\Models\SerproAuthorizationTerm;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Crypt;

/**
 * @extends Factory<SerproAuthorizationTerm>
 */
class SerproAuthorizationTermFactory extends Factory
{
    /**
     * Um termo recém-assinado, ainda sem token: é o estado real entre a
     * assinatura e a resposta do provedor, e é o único que a factory pode
     * produzir sem rede.
     *
     * As duas colunas cifradas são cifradas de verdade, no formato que o
     * `SerproTermManager` grava — `Crypt::encryptString()` direto, sem
     * `base64_encode`, porque o termo é texto. O documento é um XML de
     * descarte com a forma do termo, para que um teste que o leia de volta
     * encontre `vigencia/@data` onde procura; **não** é um termo assinado, e
     * a factory não tem como produzir um sem um e-CNPJ e sem o gate aberto.
     * O teste que precisa do termo de verdade sobe pelo `SerproTermManager`.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'author_document' => '33683111000107',
            'document_encrypted' => Crypt::encryptString(
                '<?xml version="1.0" encoding="UTF-8"?>'
                .'<termoDeAutorizacao><dados><vigencia data="'.now()->addDays(30)->format('Ymd').'"/>'
                .'</dados></termoDeAutorizacao>',
            ),
            'token_encrypted' => null,
            'document_expires_on' => now()->addDays(30)->startOfDay(),
            'token_expires_at' => null,
            'state' => SerproAuthorizationTermState::Pendente,
            'state_reason' => null,
            'signed_at' => now(),
            'last_submitted_at' => now(),
        ];
    }

    /**
     * Um termo que o provedor aceitou e que já rendeu token, que é o estado
     * em que a linha sustenta uma chamada ao gateway.
     */
    public function autenticado(string $token = 'b06feea3-1ca8-49f4-bdb4-211ab006cb92'): static
    {
        return $this->state([
            'token_encrypted' => Crypt::encryptString($token),
            'token_expires_at' => now()->addDay(),
            'state' => SerproAuthorizationTermState::Autenticado,
        ]);
    }

    /**
     * Um termo cuja vigência já passou: o documento continua no lugar, e ele
     * já não sustenta chamada nenhuma.
     */
    public function vencido(): static
    {
        return $this->state([
            'document_expires_on' => now()->subDay()->startOfDay(),
            'state' => SerproAuthorizationTermState::Vencido,
        ]);
    }
}
