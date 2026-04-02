<?php
/**
 * Securis — Monthly Auto-Bill Generation Cron
 * Runs on 1st of each month via cPanel cron job.
 * Command: 0 2 1 * * php /home/iwatechnology/securis.iwatechnology.in/cron/generate_monthly_bills.php
 */

require_once __DIR__ . '/../include/dbconfig.php';
require_once __DIR__ . '/../include/helpers.php';

$month = (int)date('n');
$year = (int)date('Y');
$logFile = __DIR__ . '/logs/generate_' . date('Y-m') . '.log';

// Ensure log directory exists
if (!is_dir(__DIR__ . '/logs')) {
    mkdir(__DIR__ . '/logs', 0755, true);
}

function cronLog($msg, $logFile) {
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $msg\n", FILE_APPEND);
}

cronLog("Starting bill generation for $month/$year", $logFile);

// Get all active societies
$societies = $conn->query("SELECT id, name FROM tbl_society WHERE status = 'active'");

$totalGenerated = 0;
$totalSkipped = 0;

while ($society = $societies->fetch_assoc()) {
    $societyId = (int)$society['id'];
    $societyName = $society['name'];

    // Get active maintenance heads for this society
    $headStmt = $conn->prepare(
        "SELECT id, name, amount FROM tbl_maintenance_head WHERE society_id = ? AND is_active = 1"
    );
    $headStmt->bind_param('i', $societyId);
    $headStmt->execute();
    $heads = $headStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $headStmt->close();

    if (empty($heads)) {
        cronLog("  $societyName: No active maintenance heads, skipping", $logFile);
        continue;
    }

    // Get all occupied flats
    $flatStmt = $conn->prepare(
        "SELECT f.id AS flat_id FROM tbl_flat f
         JOIN tbl_tower t ON f.tower_id = t.id
         WHERE t.society_id = ? AND f.status = 'occupied'"
    );
    $flatStmt->bind_param('i', $societyId);
    $flatStmt->execute();
    $flats = $flatStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $flatStmt->close();

    $generated = 0;
    $skipped = 0;

    foreach ($flats as $flat) {
        $flatId = (int)$flat['flat_id'];

        // Check if bill already exists for this flat/month/year
        $checkStmt = $conn->prepare(
            "SELECT id FROM tbl_maintenance_bill WHERE flat_id = ? AND month = ? AND year = ?"
        );
        $checkStmt->bind_param('iii', $flatId, $month, $year);
        $checkStmt->execute();
        if ($checkStmt->get_result()->num_rows > 0) {
            $checkStmt->close();
            $skipped++;
            continue;
        }
        $checkStmt->close();

        // Calculate total amount
        $totalAmount = 0;
        foreach ($heads as $head) {
            $totalAmount += (float)$head['amount'];
        }

        // Create bill
        $dueDate = date('Y-m-15'); // Due by 15th of the month
        $billStmt = $conn->prepare(
            "INSERT INTO tbl_maintenance_bill (society_id, flat_id, month, year, total_amount, due_date, status, generated_at)
             VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())"
        );
        $billStmt->bind_param('iiiids', $societyId, $flatId, $month, $year, $totalAmount, $dueDate);
        $billStmt->execute();
        $billId = $billStmt->insert_id;
        $billStmt->close();

        // Create bill items
        foreach ($heads as $head) {
            $itemStmt = $conn->prepare(
                "INSERT INTO tbl_bill_item (bill_id, head_id, amount, description) VALUES (?, ?, ?, ?)"
            );
            $headId = (int)$head['id'];
            $headAmount = (float)$head['amount'];
            $headName = $head['name'];
            $itemStmt->bind_param('iids', $billId, $headId, $headAmount, $headName);
            $itemStmt->execute();
            $itemStmt->close();
        }

        $generated++;
    }

    $totalGenerated += $generated;
    $totalSkipped += $skipped;
    cronLog("  $societyName: Generated $generated bills, skipped $skipped (already exist)", $logFile);
}

cronLog("Done. Total generated: $totalGenerated, skipped: $totalSkipped", $logFile);
$conn->close();
