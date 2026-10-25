<?php

namespace App\Http\Controllers\Api\V1\OfficeErp;

use App\Erp\Commercial\OfficeErpAdmission;
use App\Erp\Commercial\OfficeErpReceiptProofs;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class OfficeErpReceiptProofController extends Controller
{
    public function store(Request $request, OfficeErpAdmission $admission, OfficeErpReceiptProofs $proofs): JsonResponse
    {
        $file = $request->file('file');
        if (! $file instanceof UploadedFile || array_diff(array_keys($request->all()), ['file']) !== []) {
            throw ValidationException::withMessages(['file' => ['Envie um arquivo PDF, PNG ou JPEG.']]);
        }
        $staged = $proofs->stage($admission->context($request), $file);

        return response()->json(['data' => $staged], 201)->header('Cache-Control', 'no-store');
    }

    public function download(Request $request, string $proofId, OfficeErpAdmission $admission, OfficeErpReceiptProofs $proofs): StreamedResponse
    {
        return $proofs->download($admission->context($request), $proofId);
    }
}
