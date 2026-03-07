<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Imp 1: received_status on purchase_lines (line-level receiving state)
        if (Schema::hasTable('purchase_lines') && ! Schema::hasColumn('purchase_lines', 'received_status')) {
            Schema::table('purchase_lines', function (Blueprint $table): void {
                $table->string('received_status', 30)->default('pending')->after('received_quantity');
            });
        }

        // Imp 3: supplier_invoice_number on supplier_invoices (vendor's own invoice reference)
        if (Schema::hasTable('supplier_invoices') && ! Schema::hasColumn('supplier_invoices', 'supplier_invoice_number')) {
            Schema::table('supplier_invoices', function (Blueprint $table): void {
                $table->string('supplier_invoice_number', 100)->nullable()->after('invoice_number');
            });
        }

        // Imp 4: payment_reference on supplier_payments (bank_transfer_id, cheque_number, txn id)
        if (Schema::hasTable('supplier_payments') && ! Schema::hasColumn('supplier_payments', 'payment_reference')) {
            Schema::table('supplier_payments', function (Blueprint $table): void {
                $table->string('payment_reference', 100)->nullable()->after('account_id');
            });
        }

        // Imp 5: exchange_rate on supplier_invoices (snapshot for foreign currency)
        if (Schema::hasTable('supplier_invoices') && ! Schema::hasColumn('supplier_invoices', 'exchange_rate')) {
            Schema::table('supplier_invoices', function (Blueprint $table): void {
                $table->decimal('exchange_rate', 18, 8)->default(1)->after('currency_id');
            });
        }

        // Imp 6: received_by on goods_receipts (warehouse staff ≠ creator)
        if (Schema::hasTable('goods_receipts') && ! Schema::hasColumn('goods_receipts', 'received_by')) {
            Schema::table('goods_receipts', function (Blueprint $table): void {
                $table->foreignId('received_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            });
        }

        // Imp 8: soft deletes on suppliers
        if (Schema::hasTable('suppliers') && ! Schema::hasColumn('suppliers', 'deleted_at')) {
            Schema::table('suppliers', function (Blueprint $table): void {
                $table->softDeletes();
            });
        }

        // Imp 9: expected_delivery_date on purchases
        if (Schema::hasTable('purchases') && ! Schema::hasColumn('purchases', 'expected_delivery_date')) {
            Schema::table('purchases', function (Blueprint $table): void {
                $table->date('expected_delivery_date')->nullable()->after('purchase_date');
            });
        }

        // Imp 10: additional performance indexes
        if (Schema::hasTable('goods_receipt_lines')) {
            Schema::table('goods_receipt_lines', function (Blueprint $table): void {
                $table->index('purchase_line_id', 'grl_purchase_line_id_index');
            });
        }
        if (Schema::hasTable('supplier_payments') && ! Schema::hasIndex('supplier_payments', 'sp_supplier_invoice_id_index')) {
            Schema::table('supplier_payments', function (Blueprint $table): void {
                $table->index('supplier_invoice_id', 'sp_supplier_invoice_id_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('purchase_lines') && Schema::hasColumn('purchase_lines', 'received_status')) {
            Schema::table('purchase_lines', fn (Blueprint $table) => $table->dropColumn('received_status'));
        }
        if (Schema::hasTable('supplier_invoices') && Schema::hasColumn('supplier_invoices', 'supplier_invoice_number')) {
            Schema::table('supplier_invoices', fn (Blueprint $table) => $table->dropColumn('supplier_invoice_number'));
        }
        if (Schema::hasTable('supplier_payments') && Schema::hasColumn('supplier_payments', 'payment_reference')) {
            Schema::table('supplier_payments', fn (Blueprint $table) => $table->dropColumn('payment_reference'));
        }
        if (Schema::hasTable('supplier_invoices') && Schema::hasColumn('supplier_invoices', 'exchange_rate')) {
            Schema::table('supplier_invoices', fn (Blueprint $table) => $table->dropColumn('exchange_rate'));
        }
        if (Schema::hasTable('goods_receipts') && Schema::hasColumn('goods_receipts', 'received_by')) {
            Schema::table('goods_receipts', function (Blueprint $table): void {
                $table->dropForeign(['received_by']);
                $table->dropColumn('received_by');
            });
        }
        if (Schema::hasTable('suppliers') && Schema::hasColumn('suppliers', 'deleted_at')) {
            Schema::table('suppliers', fn (Blueprint $table) => $table->dropSoftDeletes());
        }
        if (Schema::hasTable('purchases') && Schema::hasColumn('purchases', 'expected_delivery_date')) {
            Schema::table('purchases', fn (Blueprint $table) => $table->dropColumn('expected_delivery_date'));
        }
        if (Schema::hasTable('goods_receipt_lines')) {
            Schema::table('goods_receipt_lines', function (Blueprint $table): void {
                $table->dropIndex('grl_purchase_line_id_index');
            });
        }
        if (Schema::hasTable('supplier_payments')) {
            Schema::table('supplier_payments', function (Blueprint $table): void {
                $table->dropIndex('sp_supplier_invoice_id_index');
            });
        }
    }
};
