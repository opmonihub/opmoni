<?php

namespace Tests\Feature;

use App\Models\Erp\ErpCompany;
use App\Support\ErpCompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ErpCompanyContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_background_context_restores_after_success_and_failure(): void
    {
        $companyA = ErpCompany::factory()->create();
        $companyB = ErpCompany::factory()->create();
        $context = app(ErpCompanyContext::class);

        $this->assertNull($context->companyId);

        $context->run((int) $companyA->id, function () use ($context, $companyA): void {
            $this->assertSame((int) $companyA->id, $context->companyId);
        });
        $this->assertNull($context->companyId);

        try {
            $context->run((int) $companyA->id, function () use ($context, $companyB): void {
                $context->run((int) $companyB->id, function () use ($context, $companyB): void {
                    $this->assertSame((int) $companyB->id, $context->companyId);
                    throw new \RuntimeException('fail');
                });
            });
        } catch (\RuntimeException) {
        }

        $this->assertNull($context->companyId);

        $context->run((int) $companyB->id, function () use ($context, $companyB): void {
            $this->assertSame((int) $companyB->id, $context->companyId);
        });
        $this->assertNull($context->companyId);
    }
}
