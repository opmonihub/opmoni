<?php

namespace Tests\Feature\Fiscal;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DocumentsRemovedTest extends TestCase
{
    use RefreshDatabase;

    public function test_documents_table_is_gone(): void
    {
        $this->assertFalse(Schema::hasTable('documents'));
    }

    public function test_documents_routes_are_gone(): void
    {
        $this->assertFalse(Route::has('documents.index'));
        $this->assertFalse(Route::has('documents.store'));
    }
}
