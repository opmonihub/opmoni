<?php

namespace App\Http\Controllers\Api\V1\OfficeErp;

use App\Erp\Commercial\OfficeErpAdmission;
use App\Erp\Commercial\OfficeErpCompanies;
use App\Erp\Commercial\OfficeErpCompanyProfiles;
use App\Erp\Commercial\OfficeErpCompanyRegistrations;
use App\Erp\ErpOperationJournal;
use App\Services\CnpjLookupException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OfficeErpCompanyController
{
    public function index(Request $request, OfficeErpAdmission $admission, OfficeErpCompanies $companies): JsonResponse
    {
        return response()->json($companies->index($admission->context($request), $request->query()));
    }

    public function store(Request $request, OfficeErpAdmission $admission, OfficeErpCompanyRegistrations $registrations, ErpOperationJournal $journal): JsonResponse
    {
        $ctx = $admission->context($request);
        $key = $journal->key($request, true);
        try {
            return response()->json(['data' => $registrations->register($ctx, $request->all(), $key)], 201);
        } catch (CnpjLookupException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->status)->header('Cache-Control', 'no-store');
        }
    }

    public function update(Request $request, int $company, OfficeErpAdmission $admission, OfficeErpCompanyProfiles $profiles, ErpOperationJournal $journal): JsonResponse
    {
        return response()->json(['data' => $profiles->update($admission->context($request), $company, $request->all(), $journal->key($request, true))]);
    }

    public function show(Request $request, int $company, OfficeErpAdmission $admission, OfficeErpCompanyProfiles $profiles): JsonResponse
    {
        return response()->json(['data' => $profiles->show($admission->context($request), $company)]);
    }
}
