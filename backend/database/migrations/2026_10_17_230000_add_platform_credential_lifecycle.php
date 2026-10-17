<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_contracting_certificates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->unique()->constrained()->restrictOnDelete();
            $table->string('document', 14);
            $table->text('subject');
            $table->string('serial_number');
            $table->timestamp('valid_from');
            $table->timestamp('valid_until');
            $table->longText('certificate_encrypted');
            $table->text('password_encrypted');
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestamps();
            $table->unique(['id', 'account_id']);
        });
        Schema::table('serpro_connections', function (Blueprint $table): void {
            $table->text('consumer_key')->change();
            $table->unsignedBigInteger('revision')->default(1);
            $table->foreignId('account_contracting_certificate_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreign(['account_contracting_certificate_id', 'contracting_account_id'], 'serpro_platform_source_account_fk')
                ->references(['id', 'account_id'])->on('account_contracting_certificates')->restrictOnDelete();
        });
        // Use the query builder: the new model cast must never read historical plaintext.
        DB::table('serpro_connections')->orderBy('id')->each(function (object $row): void {
            DB::table('serpro_connections')->where('id', $row->id)->update(['consumer_key' => Crypt::encryptString($row->consumer_key)]);
        });
        $this->constraints(true);
    }

    public function down(): void
    {
        if (DB::table('account_contracting_certificates')->exists()) {
            throw new RuntimeException('Platform vault contains credentials. Explicitly recover them before rolling this migration back.');
        }
        $this->constraints(false);
        Schema::table('serpro_connections', function (Blueprint $table): void {
            $table->dropForeign(DB::getDriverName() === 'sqlite' ? ['account_contracting_certificate_id', 'contracting_account_id'] : 'serpro_platform_source_account_fk');
            $table->dropConstrainedForeignId('account_contracting_certificate_id');
            $table->dropColumn('revision');
        });
        DB::table('serpro_connections')->orderBy('id')->each(function (object $row): void {
            DB::table('serpro_connections')->where('id', $row->id)->update(['consumer_key' => Crypt::decryptString($row->consumer_key)]);
        });
        Schema::table('serpro_connections', fn (Blueprint $table) => $table->string('consumer_key')->change());
        Schema::dropIfExists('account_contracting_certificates');
    }

    private function constraints(bool $create): void
    {
        $checks = [
            'serpro_connections' => [
                'singleton_valid' => 'singleton = 1',
                'revision_valid' => 'revision > 0',
                'state_valid' => "state IS NULL OR state IN ('not_configured', 'configured', 'invalid', 'unavailable')",
                'source_valid' => 'account_contracting_certificate_id IS NULL OR (contracting_account_id IS NOT NULL AND certificate_encrypted IS NULL AND certificate_password_encrypted IS NULL)',
            ],
            'account_contracting_certificates' => ['revision_valid' => 'revision > 0'],
        ];
        foreach ($checks as $table => $constraints) {
            foreach ($constraints as $suffix => $condition) {
                $name = $table.'_'.$suffix;
                if (DB::getDriverName() === 'pgsql') {
                    DB::statement($create ? "ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$condition})" : "ALTER TABLE {$table} DROP CONSTRAINT {$name}");
                } elseif (DB::getDriverName() === 'sqlite') {
                    $expression = preg_replace('/\b(singleton|revision|state|account_contracting_certificate_id|contracting_account_id|certificate_encrypted|certificate_password_encrypted)\b/', 'NEW.$1', $condition);
                    foreach (['INSERT', 'UPDATE'] as $event) {
                        $trigger = $name.'_'.strtolower($event);
                        DB::unprepared($create ? "CREATE TRIGGER {$trigger} BEFORE {$event} ON {$table} WHEN NOT ({$expression}) BEGIN SELECT RAISE(ABORT, '{$name}'); END" : "DROP TRIGGER IF EXISTS {$trigger}");
                    }
                }
            }
        }
    }
};
