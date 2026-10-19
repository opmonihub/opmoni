<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_contacts', function (Blueprint $table): void {
            $table->unique(['erp_company_id', 'id'], 'erp_contacts_company_id_id_unique');
        });

        Schema::create('erp_contact_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->restrictOnDelete();
            $table->unsignedBigInteger('erp_contact_id');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('heading', 255)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_private')->default(false);
            $table->string('state', 24)->default('active');
            $table->timestamps();

            $table->foreign(['erp_company_id', 'erp_contact_id'], 'erp_contact_notes_contact_company_fk')
                ->references(['erp_company_id', 'id'])->on('erp_contacts')->restrictOnDelete();
            $table->unique(['erp_company_id', 'id'], 'erp_contact_notes_company_id_id_unique');
            $table->index(['erp_company_id', 'erp_contact_id', 'state', 'id'], 'erp_contact_notes_contact_state_idx');
            $table->index(['erp_company_id', 'created_by', 'is_private', 'state'], 'erp_contact_notes_visibility_idx');
        });

        Schema::create('erp_contact_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->restrictOnDelete();
            $table->unsignedBigInteger('erp_contact_note_id');
            $table->string('original_name', 255);
            $table->string('mime_type', 127);
            $table->unsignedInteger('byte_length');
            $table->char('sha256', 64);
            $table->string('storage_key', 128)->unique();
            $table->string('status', 24)->default('ready');
            $table->timestamps();

            $table->foreign(['erp_company_id', 'erp_contact_note_id'], 'erp_contact_attachments_note_company_fk')
                ->references(['erp_company_id', 'id'])->on('erp_contact_notes')->restrictOnDelete();
            $table->index(['erp_company_id', 'erp_contact_note_id', 'status', 'id'], 'erp_contact_attachments_note_state_idx');
        });
    }

    public function down(): void
    {
        if (DB::table('erp_contact_attachments')->exists() || DB::table('erp_contact_notes')->exists()) {
            throw new RuntimeException('Cannot roll back ERP Contact note data. Restore a compatible application version instead.');
        }

        Schema::dropIfExists('erp_contact_attachments');
        Schema::dropIfExists('erp_contact_notes');

        Schema::table('erp_contacts', function (Blueprint $table): void {
            $table->dropUnique('erp_contacts_company_id_id_unique');
        });
    }
};
