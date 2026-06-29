<?php
// ============================================================================
// Functions/Inventory.php
// ----------------------------------------------------------------------------
// Central inventory adjustment functions for Musky/Nora
//
// Primary external-call function:
//   inventory_adjust($pdo, $locationCode, $partId, $delta, $externalTxnId)
//
// UPDATED:
// - externalTxnId is now OPTIONAL
// - If not supplied, system auto-generates one
// - Generated ID is returned to caller
// ============================================================================


/**
 * Generate a unique inventory transaction ID.
 */
function inventory_generate_txn_id(): string {
    return 'INV-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
}


/**
 * Get inventory location row by code.
 */
function inventory_get_location(PDO $pdo, string $locationCode): array {
    $stmt = $pdo->prepare("SELECT * FROM inventory_locations WHERE code = ?");
    $stmt->execute([$locationCode]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new Exception("Unknown inventory location code: {$locationCode}");
    }
    return $row;
}


/**
 * Ensure a stock row exists (for non-virtual locations) and return current qty.
 */
function inventory_get_stock_qty(PDO $pdo, int $partId, string $locationCode): int {
    $stmt = $pdo->prepare("SELECT qty FROM inventory_stock WHERE part_id = ? AND location_code = ?");
    $stmt->execute([$partId, $locationCode]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? intval($row['qty']) : 0;
}


/**
 * Upsert stock qty (non-virtual).
 */
function inventory_set_stock_qty(PDO $pdo, int $partId, string $locationCode, int $newQty): void {
    $stmt = $pdo->prepare("
        INSERT INTO inventory_stock (part_id, location_code, qty)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE qty = VALUES(qty)
    ");
    $stmt->execute([$partId, $locationCode, $newQty]);
}


/**
 * Log a transaction.
 */
function inventory_log_transaction(
    PDO $pdo,
    int $partId,
    ?string $fromLocationCode,
    ?string $toLocationCode,
    int $deltaFrom,
    int $deltaTo,
    string $externalTxnId,
    string $action,
    ?string $note = null,
    ?string $actor = null
): void {
    $stmt = $pdo->prepare("
        INSERT INTO inventory_transactions
        (part_id, from_location_code, to_location_code, delta_from, delta_to, external_transaction_id, action, note, actor)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $partId,
        $fromLocationCode,
        $toLocationCode,
        $deltaFrom,
        $deltaTo,
        $externalTxnId,
        $action,
        $note,
        $actor
    ]);
}


/**
 * External-call function: adjust inventory at a given location by delta.
 *
 * Rules:
 * - For stocked locations (GHS_HDK, GMS_HDK): qty normally cannot go below 0.
 * - Callers can explicitly opt into negative stock by passing $allowNegative=true.
 *   That is useful for workflows where we must record part consumption now and
 *   let a later reconciliation/reporting tool worry about replenishment.
 * - For virtual location (NONINV): we do NOT maintain stock qty; we only log usage/adjustment.
 * - Transaction ID auto-generates if not supplied.
 */
function inventory_adjust(
    PDO $pdo,
    string $locationCode,
    int $partId,
    int $delta,
    ?string $externalTxnId = null,
    string $action = 'ADJUST',
    ?string $note = null,
    ?string $actor = null,
    bool $allowNegative = false
): array {

    if ($delta === 0) {
        throw new Exception("Delta cannot be 0.");
    }

    // Auto-generate transaction ID if not supplied
    if (!$externalTxnId || trim($externalTxnId) === '') {
        $externalTxnId = inventory_generate_txn_id();
    }

    $loc = inventory_get_location($pdo, $locationCode);
    $isVirtual = ($loc['is_virtual'] === 'TRUE');

    $pdo->beginTransaction();
    try {

        if ($isVirtual) {

            inventory_log_transaction(
                $pdo,
                $partId,
                null,
                $locationCode,
                0,
                $delta,
                $externalTxnId,
                $action,
                $note,
                $actor
            );

            $pdo->commit();

            return [
                'ok' => true,
                'location' => $locationCode,
                'part_id' => $partId,
                'new_qty' => null,
                'virtual' => true,
                'external_transaction_id' => $externalTxnId
            ];
        }

        $current = inventory_get_stock_qty($pdo, $partId, $locationCode);
        $newQty = $current + $delta;

        // Most inventory screens should still protect against negative stock.
        // The new PANDA after-actions workflow intentionally wants the opposite:
        // subtract the consumed parts now, even if stock drops below zero. That
        // behavior is only enabled when the caller opts in explicitly.
        if ($newQty < 0 && !$allowNegative) {
            throw new Exception("Insufficient inventory at {$locationCode} for PartID {$partId}. Current={$current}, Delta={$delta}");
        }

        inventory_set_stock_qty($pdo, $partId, $locationCode, $newQty);

        inventory_log_transaction(
            $pdo,
            $partId,
            null,
            $locationCode,
            0,
            $delta,
            $externalTxnId,
            $action,
            $note,
            $actor
        );

        $pdo->commit();

        return [
            'ok' => true,
            'location' => $locationCode,
            'part_id' => $partId,
            'new_qty' => $newQty,
            'virtual' => false,
            'external_transaction_id' => $externalTxnId
        ];

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}


/**
 * Transfer inventory between two stocked locations.
 * - Transaction ID auto-generates if not supplied.
 */
function inventory_transfer(
    PDO $pdo,
    string $fromLocationCode,
    string $toLocationCode,
    int $partId,
    int $qty,
    ?string $externalTxnId = null,
    ?string $note = null,
    ?string $actor = null
): array {

    if ($qty <= 0) {
        throw new Exception("Transfer qty must be > 0.");
    }

    if ($fromLocationCode === $toLocationCode) {
        throw new Exception("fromLocation and toLocation cannot be the same.");
    }

    if (!$externalTxnId || trim($externalTxnId) === '') {
        $externalTxnId = inventory_generate_txn_id();
    }

    $fromLoc = inventory_get_location($pdo, $fromLocationCode);
    $toLoc   = inventory_get_location($pdo, $toLocationCode);

    if ($fromLoc['is_virtual'] === 'TRUE' || $toLoc['is_virtual'] === 'TRUE') {
        throw new Exception("Transfers must be between stocked locations (not NONINV).");
    }

    $pdo->beginTransaction();
    try {

        $fromCurrent = inventory_get_stock_qty($pdo, $partId, $fromLocationCode);
        if ($fromCurrent < $qty) {
            throw new Exception("Insufficient inventory at {$fromLocationCode} for PartID {$partId}. Current={$fromCurrent}, Need={$qty}");
        }

        $toCurrent = inventory_get_stock_qty($pdo, $partId, $toLocationCode);

        inventory_set_stock_qty($pdo, $partId, $fromLocationCode, $fromCurrent - $qty);
        inventory_set_stock_qty($pdo, $partId, $toLocationCode, $toCurrent + $qty);

        inventory_log_transaction(
            $pdo,
            $partId,
            $fromLocationCode,
            $toLocationCode,
            -$qty,
            +$qty,
            $externalTxnId,
            'TRANSFER',
            $note,
            $actor
        );

        $pdo->commit();

        return [
            'ok' => true,
            'from' => $fromLocationCode,
            'to' => $toLocationCode,
            'part_id' => $partId,
            'qty' => $qty,
            'from_new' => $fromCurrent - $qty,
            'to_new' => $toCurrent + $qty,
            'external_transaction_id' => $externalTxnId
        ];

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}
