<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Improvement 1: received_quantity on purchase_lines (remaining = quantity - received_quantity)
        if (Schema::hasTable('purchase_lines')) {
            Schema::table('purchase_lines', function (Blueprint $table): void {
                if (! Schema::hasColumn('purchase_lines', 'received_quantity')) {
                    $table->decimal('received_quantity', 15, 4)->default(0)->after('quantity');
                }
            });
        }

        // Improvement 2: unit_cost snapshot on goods_receipt_lines (inventory valuation at receipt)
        if (Schema::hasTable('goods_receipt_lines')) {
            Schema::table('goods_receipt_lines', function (Blueprint $table): void {
                if (! Schema::hasColumn('goods_receipt_lines', 'unit_cost')) {
                    $table->decimal('unit_cost', 15, 4)->default(0)->after('quantity_received');
                }
            });
        }

        // Improvement 3: paid_amount on supplier_invoices (remaining = total - paid_amount)
        if (Schema::hasTable('supplier_invoices')) {
            Schema::table('supplier_invoices', function (Blueprint $table): void {
                if (! Schema::hasColumn('supplier_invoices', 'paid_amount')) {
                    $table->decimal('paid_amount', 15, 2)->default(0)->after('total');
                }
            });
        }

        // Improvement 4: account_id on supplier_payments (Cash/Bank/Wallet paid from)
        if (Schema::hasTable('supplier_payments')) {
            Schema::table('supplier_payments', function (Blueprint $table): void {
                if (! Schema::hasColumn('supplier_payments', 'account_id')) {
                    $table->foreignId('account_id')
                        ->nullable()
                        ->after('supplier_invoice_id')
                        ->constrained('accounts')
                        ->nullOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('purchase_lines') && Schema::hasColumn('purchase_lines', 'received_quantity')) {
            Schema::table('purchase_lines', fn (Blueprint $table) => $table->dropColumn('received_quantity'));
        }
        if (Schema::hasTable('goods_receipt_lines') && Schema::hasColumn('goods_receipt_lines', 'unit_cost')) {
            Schema::table('goods_receipt_lines', fn (Blueprint $table) => $table->dropColumn('unit_cost'));
        }
        if (Schema::hasTable('supplier_invoices') && Schema::hasColumn('supplier_invoices', 'paid_amount')) {
            Schema::table('supplier_invoices', fn (Blueprint $table) => $table->dropColumn('paid_amount'));
        }
        if (Schema::hasTable('supplier_payments') && Schema::hasColumn('supplier_payments', 'account_id')) {
            Schema::table('supplier_payments', function (Blueprint $table): void {
                $table->dropForeign(['account_id']);
                $table->dropColumn('account_id');
            });
        }
    }
};
