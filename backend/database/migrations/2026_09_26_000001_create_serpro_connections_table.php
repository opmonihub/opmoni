<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('serpro_connections', function (Blueprint $table): void {
            $table->id();
            $table->string('consumer_key');
            $table->text('consumer_secret_encrypted');
            $table->longText('certificate_encrypted')->nullable();
            $table->text('certificate_password_encrypted')->nullable();
            $table->string('certificate_subject')->nullable();
            $table->string('certificate_serial_number')->nullable();
            $table->timestamp('certificate_valid_from')->nullable();
            $table->timestamp('certificate_valid_until')->nullable();
            $table->string('contratante_numero', 14);
            $table->unsignedTinyInteger('contratante_tipo')->default(2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('serpro_connections');
    }
};
