<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

class LimitPlatformCertificateUpload
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/v1/serpro/connection', 'api/v1/admin/accounts/*/contracting-certificate')
            && ! in_array($request->method(), ['GET', 'HEAD'], true)) {
            $overflow = (int) $request->server('CONTENT_LENGTH', 0) > 4 * 1024 * 1024;
            foreach (Arr::flatten($request->files->all()) as $file) {
                if ($file instanceof UploadedFile) {
                    $overflow = $overflow || in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                        || ($file->isValid() && $file->getSize() > 2 * 1024 * 1024);
                }
            }
            if ($overflow) {
                return response()->json(['code' => 'payload_too_large', 'message' => 'O certificado deve ter até 2 MiB e a solicitação até 4 MiB.'], 413, ['Cache-Control' => 'no-store']);
            }
        }

        return $next($request);
    }
}
