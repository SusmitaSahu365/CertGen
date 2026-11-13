<?php
/**
 * Default configuration. Works out of the box (SQLite, no setup needed).
 *
 * To customise, copy this file to config/config.php (git-ignored) and edit,
 * or set the environment variables shown below (handy for Docker / hosting).
 */
return [
    "app_name"  => getenv("APP_NAME") ?: "CertGen",

    // Demo mode shows a banner and stamps a SAMPLE watermark on every PDF.
    // Turn it on for any public deployment so nobody mistakes output for an official document.
    "demo_mode" => filter_var(getenv("DEMO_MODE") ?: false, FILTER_VALIDATE_BOOLEAN),

    "db" => [
        "driver"      => getenv("DB_DRIVER") ?: "sqlite",      // "sqlite" or "mysql"
        "sqlite_path" => getenv("DB_PATH") ?: dirname(__DIR__) . "/storage/certgen.sqlite",
        "host"        => getenv("DB_HOST") ?: "127.0.0.1",
        "port"        => (int) (getenv("DB_PORT") ?: 3306),
        "name"        => getenv("DB_NAME") ?: "certgen",
        "user"        => getenv("DB_USER") ?: "root",
        "pass"        => getenv("DB_PASS") ?: "",
    ],

    "max_upload_mb"   => 5,      // spreadsheet upload limit
    "max_import_rows" => 5000,   // rows per import
    "max_pdf_records" => 1000,   // records per PDF download
];
