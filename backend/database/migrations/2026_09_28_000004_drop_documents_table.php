<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('documents');
    }

    public function down(): void
    {
        // Deliberadamente vazio. `documents` era scaffolding do template: nenhuma
        // migration posterior depende dela e nenhum código a consome. Recriá-la
        // no rollback devolveria uma tabela que a aplicação não sabe ler, o que é
        // pior do que a sua ausência. Ver design.md decisão 12.
    }
};
