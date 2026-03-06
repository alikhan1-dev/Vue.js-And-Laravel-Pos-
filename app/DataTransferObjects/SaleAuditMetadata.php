<?php

namespace App\DataTransferObjects;

/**
 * Standardized JSON schema for sale_audit_log.metadata.
 * Ensures consistent structure for traceability and compliance.
 *
 * Keys (all optional, use only when applicable):
 * - lines_count: int
 * - stock_movement_ids: int[]
 * - return_for_sale_id: int
 * - lines: array<{
 *      product_id: int,
 *      quantity: float,
 *      stock_movement_id: int|null,
 *      variant_id?: int|null,
 *      lot_number?: string|null,
 *      imei_id?: int|null
 *   }>
 * - user_id: int (redundant with created_by; optional for denormalized reads)
 */
final class SaleAuditMetadata
{
    public static function forCreated(int $linesCount, array $linesWithMovementIds): array
    {
        return [
            'lines_count' => $linesCount,
            'lines' => $linesWithMovementIds,
            'stock_movement_ids' => array_values(array_filter(array_column($linesWithMovementIds, 'stock_movement_id'))),
        ];
    }

    public static function forConvertedToSale(array $stockMovementIds, array $linesWithMovementIds): array
    {
        return [
            'stock_movement_ids' => $stockMovementIds,
            'lines' => $linesWithMovementIds,
        ];
    }

    public static function forReturnCreated(int $returnForSaleId, array $stockMovementIds, array $linesWithMovementIds): array
    {
        return [
            'return_for_sale_id' => $returnForSaleId,
            'stock_movement_ids' => $stockMovementIds,
            'lines' => $linesWithMovementIds,
        ];
    }

    public static function forStatusChanged(?array $extra = null): array
    {
        return $extra ?? [];
    }
}
