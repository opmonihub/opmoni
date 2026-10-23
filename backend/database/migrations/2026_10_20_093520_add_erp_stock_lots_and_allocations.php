<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_stock_movements', function (Blueprint $table): void {
            $table->unsignedBigInteger('source_line_id')->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('source_currency', 3)->nullable();
            $table->json('cost_breakdown')->nullable();
            $table->decimal('total_cost', 22, 4)->nullable();
            $table->decimal('known_cost', 22, 4)->nullable();
            $table->decimal('unvalued_quantity', 22, 4)->nullable();
            $table->string('cost_status', 32)->default('legacy_unknown');
            $table->string('reason', 255)->nullable();
            $table->unique(['erp_company_id', 'erp_establishment_id', 'erp_product_variant_id', 'id'], 'erp_movements_company_position_unique');
        });
        Schema::table('erp_stock_balances', function (Blueprint $table): void {
            $table->timestamp('ledger_initialized_at')->nullable();
            $table->json('ledger_reconciliation')->nullable();
            $table->unique(['erp_company_id', 'erp_establishment_id', 'erp_product_variant_id'], 'erp_balances_company_position_unique');
            $table->unique(['erp_company_id', 'erp_establishment_id', 'erp_product_variant_id', 'id'], 'erp_balances_company_identity_unique');
        });
        Schema::create('erp_stock_lots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->restrictOnDelete();
            $table->foreignId('erp_establishment_id')->constrained('erp_establishments')->restrictOnDelete();
            $table->unsignedBigInteger('erp_product_variant_id');
            $table->foreignId('erp_stock_movement_id')->nullable()->constrained('erp_stock_movements')->restrictOnDelete();
            $table->foreignId('opening_balance_id')->nullable()->constrained('erp_stock_balances')->restrictOnDelete();
            $table->foreignId('root_lot_id')->nullable()->constrained('erp_stock_lots')->restrictOnDelete();
            $table->unsignedBigInteger('parent_lot_id')->nullable();
            $table->unsignedBigInteger('parent_allocation_id')->nullable();
            $table->unsignedBigInteger('parent_settlement_id')->nullable();
            $table->decimal('quantity', 22, 4);
            $table->decimal('available_quantity', 22, 4);
            $table->decimal('total_cost', 22, 4)->nullable();
            $table->decimal('consumed_quantity', 22, 4)->default(0);
            $table->decimal('consumed_cost', 22, 4)->default(0);
            $table->string('currency', 3)->nullable();
            $table->string('cost_status', 32);
            $table->timestamp('received_at');
            $table->timestamps();
            $table->index(['erp_company_id', 'erp_establishment_id', 'erp_product_variant_id'], 'erp_lots_position');
            $table->unique('opening_balance_id');
            $table->unique(['erp_company_id', 'erp_product_variant_id', 'id'], 'erp_lots_company_variant_unique');
            $table->unique(['erp_company_id', 'erp_establishment_id', 'erp_product_variant_id', 'id'], 'erp_lots_company_position_unique');
            $table->foreign(['erp_company_id', 'erp_establishment_id', 'erp_product_variant_id', 'opening_balance_id'], 'erp_lots_opening_fk')->references(['erp_company_id', 'erp_establishment_id', 'erp_product_variant_id', 'id'])->on('erp_stock_balances')->restrictOnDelete();
            $table->foreign(['erp_company_id', 'erp_establishment_id', 'erp_product_variant_id'], 'erp_lots_balance_fk')->references(['erp_company_id', 'erp_establishment_id', 'erp_product_variant_id'])->on('erp_stock_balances')->restrictOnDelete();
            $table->foreign(['erp_company_id', 'erp_establishment_id', 'erp_product_variant_id', 'erp_stock_movement_id'], 'erp_lots_movement_fk')->references(['erp_company_id', 'erp_establishment_id', 'erp_product_variant_id', 'id'])->on('erp_stock_movements')->restrictOnDelete();
            $table->foreign(['erp_company_id', 'erp_product_variant_id', 'parent_lot_id'], 'erp_lots_parent_lot_fk')->references(['erp_company_id', 'erp_product_variant_id', 'id'])->on('erp_stock_lots')->restrictOnDelete();
            $table->foreign(['erp_company_id', 'erp_product_variant_id', 'root_lot_id'], 'erp_lots_root_fk')->references(['erp_company_id', 'erp_product_variant_id', 'id'])->on('erp_stock_lots')->restrictOnDelete();
        });
        Schema::create('erp_stock_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->restrictOnDelete();
            $table->foreignId('erp_establishment_id')->constrained('erp_establishments')->restrictOnDelete();
            $table->unsignedBigInteger('erp_product_variant_id');
            $table->foreignId('erp_stock_movement_id')->nullable()->constrained('erp_stock_movements')->restrictOnDelete();
            $table->foreignId('opening_balance_id')->nullable()->constrained('erp_stock_balances')->restrictOnDelete();
            $table->foreignId('erp_stock_lot_id')->nullable()->constrained('erp_stock_lots')->restrictOnDelete();
            $table->foreignId('original_allocation_id')->nullable()->constrained('erp_stock_allocations')->restrictOnDelete();
            $table->unsignedInteger('ordinal');
            $table->string('kind', 16)->default('issue');
            $table->decimal('quantity', 22, 4);
            $table->decimal('cost', 22, 4)->nullable();
            $table->decimal('returned_quantity', 22, 4)->default(0);
            $table->decimal('returned_cost', 22, 4)->default(0);
            $table->decimal('settled_quantity', 22, 4)->default(0);
            $table->string('currency', 3)->nullable();
            $table->string('cost_status', 32);
            $table->timestamp('issued_at');
            $table->timestamps();
            $table->unique(['erp_stock_movement_id', 'ordinal']);
            $table->unique('opening_balance_id');
            $table->index(['erp_company_id', 'erp_establishment_id', 'erp_product_variant_id'], 'erp_allocations_position');
            $table->unique(['erp_company_id', 'erp_product_variant_id', 'id'], 'erp_allocations_company_variant_unique');
            $table->unique(['erp_company_id', 'erp_establishment_id', 'erp_product_variant_id', 'id'], 'erp_allocations_company_position_unique');
            $table->foreign(['erp_company_id', 'erp_establishment_id', 'erp_product_variant_id', 'opening_balance_id'], 'erp_allocations_opening_fk')->references(['erp_company_id', 'erp_establishment_id', 'erp_product_variant_id', 'id'])->on('erp_stock_balances')->restrictOnDelete();
            $table->foreign(['erp_company_id', 'erp_establishment_id', 'erp_product_variant_id'], 'erp_allocations_balance_fk')->references(['erp_company_id', 'erp_establishment_id', 'erp_product_variant_id'])->on('erp_stock_balances')->restrictOnDelete();
            $table->foreign(['erp_company_id', 'erp_establishment_id', 'erp_product_variant_id', 'erp_stock_movement_id'], 'erp_allocations_movement_fk')->references(['erp_company_id', 'erp_establishment_id', 'erp_product_variant_id', 'id'])->on('erp_stock_movements')->restrictOnDelete();
            $table->foreign(['erp_company_id', 'erp_establishment_id', 'erp_product_variant_id', 'erp_stock_lot_id'], 'erp_allocations_lot_fk')->references(['erp_company_id', 'erp_establishment_id', 'erp_product_variant_id', 'id'])->on('erp_stock_lots')->restrictOnDelete();
            $table->foreign(['erp_company_id', 'erp_establishment_id', 'erp_product_variant_id', 'original_allocation_id'], 'erp_allocations_original_fk')->references(['erp_company_id', 'erp_establishment_id', 'erp_product_variant_id', 'id'])->on('erp_stock_allocations')->restrictOnDelete();
        });
        Schema::create('erp_stock_cost_settlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->restrictOnDelete();
            $table->foreignId('erp_establishment_id')->constrained('erp_establishments')->restrictOnDelete();
            $table->unsignedBigInteger('erp_product_variant_id');
            $table->foreignId('erp_stock_allocation_id')->constrained('erp_stock_allocations')->restrictOnDelete();
            $table->foreignId('erp_stock_lot_id')->constrained('erp_stock_lots')->restrictOnDelete();
            $table->foreignId('erp_stock_movement_id')->constrained('erp_stock_movements')->restrictOnDelete();
            $table->foreignId('reversal_of_id')->nullable()->constrained('erp_stock_cost_settlements')->restrictOnDelete();
            $table->decimal('quantity', 22, 4);
            $table->decimal('cost', 22, 4)->nullable();
            $table->decimal('returned_quantity', 22, 4)->default(0);
            $table->decimal('returned_cost', 22, 4)->default(0);
            $table->string('currency', 3)->nullable();
            $table->timestamp('posted_at');
            $table->timestamps();
            $table->unique(['erp_company_id', 'erp_product_variant_id', 'id'], 'erp_settlements_company_variant_unique');
            $table->foreign(['erp_company_id', 'erp_establishment_id', 'erp_product_variant_id', 'erp_stock_allocation_id'], 'erp_settlements_allocation_fk')->references(['erp_company_id', 'erp_establishment_id', 'erp_product_variant_id', 'id'])->on('erp_stock_allocations')->restrictOnDelete();
            $table->foreign(['erp_company_id', 'erp_establishment_id', 'erp_product_variant_id', 'erp_stock_lot_id'], 'erp_settlements_lot_fk')->references(['erp_company_id', 'erp_establishment_id', 'erp_product_variant_id', 'id'])->on('erp_stock_lots')->restrictOnDelete();
            $table->foreign(['erp_company_id', 'erp_establishment_id', 'erp_product_variant_id', 'erp_stock_movement_id'], 'erp_settlements_movement_fk')->references(['erp_company_id', 'erp_establishment_id', 'erp_product_variant_id', 'id'])->on('erp_stock_movements')->restrictOnDelete();
            $table->foreign(['erp_company_id', 'erp_product_variant_id', 'reversal_of_id'], 'erp_settlements_original_fk')->references(['erp_company_id', 'erp_product_variant_id', 'id'])->on('erp_stock_cost_settlements')->restrictOnDelete();
        });
        Schema::table('erp_stock_lots', function (Blueprint $table): void {
            $table->foreign(['erp_company_id', 'erp_product_variant_id', 'parent_allocation_id'], 'erp_lots_parent_allocation_fk')->references(['erp_company_id', 'erp_product_variant_id', 'id'])->on('erp_stock_allocations')->restrictOnDelete();
            $table->foreign(['erp_company_id', 'erp_product_variant_id', 'parent_settlement_id'], 'erp_lots_parent_settlement_fk')->references(['erp_company_id', 'erp_product_variant_id', 'id'])->on('erp_stock_cost_settlements')->restrictOnDelete();
        });
        Schema::table('erp_sale_lines', function (Blueprint $table): void {
            $table->decimal('unit_conversion_factor', 22, 8)->default(1);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE erp_stock_lots ADD CONSTRAINT erp_lots_quantity_bounds CHECK (quantity > 0 AND available_quantity >= 0 AND consumed_quantity >= 0 AND available_quantity + consumed_quantity = quantity AND (total_cost IS NULL OR total_cost >= 0) AND consumed_cost >= 0 AND (total_cost IS NULL OR consumed_cost <= total_cost))');
            DB::statement('ALTER TABLE erp_stock_allocations ADD CONSTRAINT erp_allocations_quantity_bounds CHECK (quantity > 0 AND returned_quantity >= 0 AND returned_quantity <= quantity AND settled_quantity >= 0 AND settled_quantity <= quantity AND (cost IS NULL OR cost >= 0) AND returned_cost >= 0 AND (cost IS NULL OR returned_cost <= cost))');
            DB::statement('ALTER TABLE erp_stock_cost_settlements ADD CONSTRAINT erp_settlements_quantity_bounds CHECK (quantity > 0 AND returned_quantity >= 0 AND returned_quantity <= quantity AND (cost IS NULL OR cost >= 0) AND returned_cost >= 0 AND (cost IS NULL OR returned_cost <= cost))');
        }

        DB::table('erp_stock_balances')->orderBy('id')->each(function (object $balance): void {
            $quantity = (string) $balance->quantity_on_hand;
            $signedHistory = '0.0000';
            $lastId = null;
            foreach (DB::table('erp_stock_movements')->where('erp_company_id', $balance->erp_company_id)->where('erp_establishment_id', $balance->erp_establishment_id)->where('erp_product_variant_id', $balance->erp_product_variant_id)->orderBy('id')->cursor() as $movement) {
                $signedHistory = $movement->direction === 'in' ? bcadd($signedHistory, (string) $movement->quantity, 4) : bcsub($signedHistory, (string) $movement->quantity, 4);
                $lastId = $movement->id;
            }
            $base = ['erp_company_id' => $balance->erp_company_id, 'erp_establishment_id' => $balance->erp_establishment_id, 'erp_product_variant_id' => $balance->erp_product_variant_id, 'opening_balance_id' => $balance->id, 'cost_status' => 'legacy_unknown', 'created_at' => now(), 'updated_at' => now()];
            if (bccomp($quantity, '0', 4) > 0) {
                DB::table('erp_stock_lots')->insert([...$base, 'quantity' => $quantity, 'available_quantity' => $quantity, 'received_at' => now()]);
            } elseif (bccomp($quantity, '0', 4) < 0) {
                DB::table('erp_stock_allocations')->insert([...$base, 'ordinal' => 0, 'quantity' => bcsub('0', $quantity, 4), 'issued_at' => now()]);
            }
            DB::table('erp_stock_balances')->where('id', $balance->id)->update(['ledger_initialized_at' => now(), 'ledger_reconciliation' => json_encode(['watermark_movement_id' => $lastId, 'observed_quantity' => $quantity, 'signed_history_quantity' => $signedHistory, 'needs_reconciliation' => bccomp($quantity, $signedHistory, 4) !== 0, 'provenance' => 'legacy_unknown'], JSON_THROW_ON_ERROR)]);
        });
    }

    public function down(): void
    {
        if (DB::table('erp_stock_lots')->exists() || DB::table('erp_stock_allocations')->exists() || DB::table('erp_stock_cost_settlements')->exists()) {
            throw new RuntimeException('Stock ledger history cannot be removed; deploy a compatible application version.');
        }
        Schema::table('erp_stock_lots', function (Blueprint $table): void {
            $table->dropForeign(DB::getDriverName() === 'sqlite' ? ['erp_company_id', 'erp_product_variant_id', 'parent_allocation_id'] : 'erp_lots_parent_allocation_fk');
            $table->dropForeign(DB::getDriverName() === 'sqlite' ? ['erp_company_id', 'erp_product_variant_id', 'parent_settlement_id'] : 'erp_lots_parent_settlement_fk');
        });
        Schema::dropIfExists('erp_stock_cost_settlements');
        Schema::dropIfExists('erp_stock_allocations');
        Schema::dropIfExists('erp_stock_lots');
        Schema::table('erp_sale_lines', fn (Blueprint $table) => $table->dropColumn('unit_conversion_factor'));
        Schema::table('erp_stock_balances', function (Blueprint $table): void {
            $table->dropUnique('erp_balances_company_position_unique');
            $table->dropUnique('erp_balances_company_identity_unique');
            $table->dropColumn(['ledger_initialized_at', 'ledger_reconciliation']);
        });
        Schema::table('erp_stock_movements', function (Blueprint $table): void {
            $table->dropUnique('erp_movements_company_position_unique');
            $table->dropColumn(['source_line_id', 'currency', 'source_currency', 'cost_breakdown', 'total_cost', 'known_cost', 'unvalued_quantity', 'cost_status', 'reason']);
        });
    }
};
