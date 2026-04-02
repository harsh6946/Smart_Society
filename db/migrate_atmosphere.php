<?php
/**
 * Migration: Add atmosphere columns to tbl_society
 * Run once: php migrate_atmosphere.php
 */
require_once __DIR__ . '/../include/dbconfig.php';

$queries = [
    "ALTER TABLE tbl_society ADD COLUMN IF NOT EXISTS atmosphere_type VARCHAR(30) DEFAULT 'auto'",
    "ALTER TABLE tbl_society ADD COLUMN IF NOT EXISTS atmosphere_intensity VARCHAR(10) DEFAULT 'normal'",
    "ALTER TABLE tbl_society ADD COLUMN IF NOT EXISTS atmosphere_message VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE tbl_society ADD COLUMN IF NOT EXISTS atmosphere_expires_at DATETIME DEFAULT NULL",
];

foreach ($queries as $q) {
    if ($conn->query($q)) {
        echo "OK: $q\n";
    } else {
        echo "ERR: " . $conn->error . " | $q\n";
    }
}

echo "\nMigration complete.\n";
