<?php

namespace App\Services;

use App\Events\WarrantyRegistered;
use App\Enums\WarrantyStatus;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\ProductWarranty;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\Warranty;
use App\Models\WarrantyRegistration;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class WarrantyService
{
    public function registerForSale(Sale $sale, array $lines): void
    {
        if (! $sale->company_id) {
            return;
        }

        $productIds = collect($lines)
            ->map(fn (array $line) => (int) ($line['product_id'] ?? 0))
            ->filter()
            ->unique()
            ->values();

        if ($productIds->isEmpty()) {
            return;
        }

        /** @var Collection<int, Product> $products */
        $products = Product::withoutGlobalScope('company')
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        /** @var Collection<int, Collection<int, ProductWarranty>> $productWarranties */
        $productWarranties = ProductWarranty::with('warranty')
            ->whereIn('product_id', $productIds)
            ->get()
            ->groupBy('product_id');

        foreach ($sale->lines as $lineModel) {
            /** @var SaleLine $lineModel */
            $productId = $lineModel->product_id;
            $product = $products->get($productId);

            if (! $product) {
                continue;
            }

            $warrantyLinks = $productWarranties->get($productId, collect())
                ->filter(function (ProductWarranty $pw): bool {
                    return $pw->is_default && $pw->warranty && $pw->warranty->is_active;
                });

            if ($warrantyLinks->isEmpty()) {
                continue;
            }

            $serialId = null;
            if (! empty($lineModel->imei_id)) {
                $serial = ProductSerial::find($lineModel->imei_id);
                if ($serial && $serial->product_id === $productId) {
                    $serialId = $serial->id;
                }
            }

            foreach ($warrantyLinks as $link) {
                /** @var ProductWarranty $link */
                /** @var Warranty $warranty */
                $warranty = $link->warranty;

                $start = $sale->created_at instanceof CarbonInterface
                    ? $sale->created_at->copy()->startOfDay()
                    : now()->startOfDay();

                $end = $start->copy()->addMonths($warranty->duration_months);

                $registration = WarrantyRegistration::create([
                    'company_id' => $sale->company_id,
                    'sale_id' => $sale->id,
                    'sale_line_id' => $lineModel->id,
                    'customer_id' => $sale->customer_id,
                    'product_id' => $productId,
                    'quantity' => (float) $lineModel->quantity,
                    'serial_id' => $serialId,
                    'warranty_id' => $warranty->id,
                    'start_date' => $start->toDateString(),
                    'end_date' => $end->toDateString(),
                    'status' => WarrantyStatus::Active,
                ]);
                WarrantyRegistered::dispatch($registration);
            }
        }
    }
}

