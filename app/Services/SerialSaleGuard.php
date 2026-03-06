<?php

namespace App\Services;

use App\Models\ProductSerial;
use InvalidArgumentException;

/**
 * Ensures serialized products are not sold twice: validates before sale_out and updates serial lifecycle.
 */
class SerialSaleGuard
{
    /**
     * Validate that the serial can be sold: exists, in_stock, not already sold, and in the sale warehouse.
     */
    public function validateSerialForSale(int $serialId, int $productId, int $warehouseId): void
    {
        $serial = ProductSerial::find($serialId);
        if (! $serial) {
            throw new InvalidArgumentException("Serial id {$serialId} not found.");
        }
        if ($serial->product_id !== $productId) {
            throw new InvalidArgumentException("Serial does not belong to product id {$productId}.");
        }
        if ($serial->status === 'sold') {
            throw new InvalidArgumentException("Serial/IMEI {$serial->serial_number} is already sold (serial id {$serialId}). Duplicate IMEI sales are not allowed.");
        }
        if ($serial->status !== 'in_stock') {
            throw new InvalidArgumentException("Serial is not available for sale (status: {$serial->status}).");
        }
        if ((int) $serial->warehouse_id !== $warehouseId) {
            throw new InvalidArgumentException('Serial warehouse does not match sale warehouse.');
        }
    }

    /**
     * Mark serial as sold and link to sale.
     */
    public function markSerialSold(ProductSerial $serial, int $saleId): void
    {
        $serial->update([
            'status' => 'sold',
            'sale_id' => $saleId,
            'reference_type' => 'Sale',
            'reference_id' => $saleId,
        ]);
    }

    /**
     * Mark serial as returned (e.g. after a return movement).
     */
    public function markSerialReturned(ProductSerial $serial): void
    {
        $serial->update([
            'status' => 'returned',
            'sale_id' => null,
            'reference_type' => null,
            'reference_id' => null,
        ]);
    }
}
