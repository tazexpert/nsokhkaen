<?php
/**
 * Reports page data - one "summary" action returns everything the page
 * needs in a single call:
 *   - items: materials withdrawn within the date range (quantity + current
 *     value), used for both the bar chart and the value table.
 *   - total_value: sum of every item's value, for the table's total row.
 *   - low_stock: ALL materials currently at/under their reorder point -
 *     a live snapshot, not scoped to the search date range.
 */
require_once __DIR__ . '/../bootstrap.php';

requireAdminApi();
$pdo = getDbConnection();

$startDate = sanitizeString($_GET['start_date'] ?? '');
$endDate = sanitizeString($_GET['end_date'] ?? '');

jsonResponse(['success' => true, 'data' => buildReportData($pdo, $startDate, $endDate)]);

function buildReportData(PDO $pdo, string $startDate, string $endDate): array
{
    $dateCondition = '';
    $params = [];
    if ($startDate !== '') {
        $dateCondition .= ' AND mt.created_at >= :start_date';
        $params['start_date'] = $startDate . ' 00:00:00';
    }
    if ($endDate !== '') {
        $dateCondition .= ' AND mt.created_at <= :end_date';
        $params['end_date'] = $endDate . ' 23:59:59';
    }

    // Current unit_cost is used for valuation - material_transactions doesn't
    // keep a historical price per withdrawal, so there's no "price at the time"
    // to report instead.
    $sql = "SELECT m.name, m.unit, m.unit_cost, SUM(mt.quantity) AS qty
            FROM inv_material_transactions mt
            JOIN inv_materials m ON m.id = mt.material_id
            WHERE mt.transaction_type = 'withdraw' $dateCondition
            GROUP BY m.id, m.name, m.unit, m.unit_cost
            HAVING SUM(mt.quantity) > 0
            ORDER BY qty DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $items = [];
    $totalValue = 0.0;
    foreach ($rows as $row) {
        $qty = (int) $row['qty'];
        $unitCost = (float) $row['unit_cost'];
        $value = $qty * $unitCost;
        $totalValue += $value;
        $items[] = [
            'name' => $row['name'],
            'unit' => $row['unit'],
            'qty' => $qty,
            'unit_cost' => $unitCost,
            'value' => $value,
        ];
    }

    $lowStock = $pdo->query("SELECT name, unit, stock_qty, min_stock
        FROM inv_materials WHERE stock_qty <= min_stock ORDER BY name")->fetchAll();

    return [
        'items' => $items,
        'total_value' => $totalValue,
        'low_stock' => $lowStock,
    ];
}
