<?php

namespace App\Http\Requests\Tenant;

use App\Models\Client;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreClientCertificateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $client = $this->route('client');

        return $client instanceof Client && Gate::allows('update', $client);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'certificate' => ['required', 'file', 'max:2048', 'extensions:pfx,p12'],
            'password' => ['required', 'string', 'max:1024'],
        ];
    }
}
