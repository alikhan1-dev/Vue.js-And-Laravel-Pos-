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
        if (Schema::hasTable('sales')) {
            Schema::table('sales', function (Blueprint $table): void {
                if (! Schema::hasColumn('sales', 'deleted_at')) {
                    $table->softDeletes();
                }

                if (! Schema::hasColumn('sales', 'approved_by')) {
                    $table->foreignId('approved_by')
                        ->nullable()
                        ->after('created_by')
                        ->constrained('users')
                        ->nullOnDelete();
                }

                // Additional indexes for reporting and lookups
                $table->index(
                    ['company_id', 'branch_id', 'type', 'status', 'created_at'],
                    'sales_company_branch_type_status_created_index'
                );

                $table->index('return_for_sale_id', 'sales_return_for_sale_id_index');
                $table->index('customer_id', 'sales_customer_id_index');
            });
        }

        if (Schema::hasTable('sale_lines')) {
            Schema::table('sale_lines', function (Blueprint $table): void {
                if (! Schema::hasColumn('sale_lines', 'deleted_at')) {
                    $table->softDeletes();
                }

                if (! Schema::hasColumn('sale_lines', 'lot_number')) {
                    $table->string('lot_number', 100)->nullable()->after('stock_movement_id');
                }

                if (! Schema::hasColumn('sale_lines', 'imei_id')) {
                    $table->unsignedBigInteger('imei_id')->nullable()->after('lot_number');
                }

                // Indexes
                $table->index(['product_id', 'sale_id'], 'sale_lines_product_id_sale_id_index');
            });
        }

        if (Schema::hasTable('sale_audit_log')) {
            Schema::table('sale_audit_log', function (Blueprint $table): void {
                if (! Schema::hasColumn('sale_audit_log', 'idempotency_key')) {
                    $table->string('idempotency_key', 100)->nullable()->after('metadata');
                    $table->index('idempotency_key', 'sale_audit_idempotency_key_index');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('sales')) {
            Schema::table('sales', function (Blueprint $table): void {
                if (Schema::hasColumn('sales', 'deleted_at')) {
                    $table->dropSoftDeletes();
                }

                if (Schema::hasColumn('sales', 'approved_by')) {
                    $table->dropForeign(['approved_by']);
                    $table->dropColumn('approved_by');
                }

                $table->dropIndex('sales_company_branch_type_status_created_index');
                $table->dropIndex('sales_return_for_sale_id_index');
                $table->dropIndex('sales_customer_id_index');
            });
        }

        if (Schema::hasTable('sale_lines')) {
            Schema::table('sale_lines', function (Blueprint $table): void {
                if (Schema::hasColumn('sale_lines', 'deleted_at')) {
                    $table->dropSoftDeletes();
                }

                if (Schema::hasColumn('sale_lines', 'lot_number')) {
                    $table->dropColumn('lot_number');
                }

                if (Schema::hasColumn('sale_lines', 'imei_id')) {
                    $table->dropColumn('imei_id');
                }

                $table->dropIndex('sale_lines_product_id_sale_id_index');
            });
        }

        if (Schema::hasTable('sale_audit_log')) {
            Schema::table('sale_audit_log', function (Blueprint $table): void {
                if (Schema::hasColumn('sale_audit_log', 'idempotency_key')) {
                    $table->dropIndex('sale_audit_idempotency_key_index');
                    $table->dropColumn('idempotency_key');
                }
            });
        }
    }
};

