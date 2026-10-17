<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UploadAccountContractingCertificateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'certificate' => ['required', 'file', 'extensions:pfx,p12', 'max:2048'],
            'password' => ['present', 'string', 'max:255'],
            'document' => ['prohibited'],
        ];
    }
}
