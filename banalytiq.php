<?php

require_once __DIR__ . '/defines.php';

function record_visit($db_name = null, ?callable $url_filter = null, $ip_list_to_skip = array()) { 
    // TODO: Implement ip_list_to_skip

    $db_path = __DIR__ . '/' . ($db_name !== null ? $db_name : BANALYTIQ_DB);
    if (!file_exists($db_path)) {
        error_log("DB file not found: $db_path");
        return;
    }

    $url = $_SERVER['REQUEST_URI'];

    // Extract the path without query parameters  
    $url_path = parse_url($url, PHP_URL_PATH);

    // Only track requests without file extensions (modern clean URLs)
    // This excludes images, CSS, JS, fonts, and other static assets automatically
    $path_info = pathinfo($url_path);
    if (isset($path_info['extension'])) {
        return;
    }

    if ($url_filter !== null) {
        // TODO: Implement url whitelist
    }

    $referer = $_SERVER['HTTP_REFERER'] ?? "";
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? "";
    $now = DateTime::createFromFormat('U.u', microtime(true));
    $dt = time(); // unix timestamp in seconds
    $status = http_response_code();
    
    // anonymize ip address by dropping last octet
    $ip_octets = explode(".", $_SERVER['REMOTE_ADDR']);
    $ip_octets[3] = "0";
    $ip = implode(".", $ip_octets);

    // $key = 123321, $maxAcquire = 1, $permissions =0666, $autoRelease = 1
    $semaphore = sem_get(123321, 1, 0666, 1);
    sem_acquire($semaphore);  //blocking

    try {
        $db = new SQLite3($db_path);
    } catch (Exception $e) {
        error_log("Error opening database: " . $e->getMessage());
        return;
    }

    // Store data in database (without location data)
    $stmt = $db->prepare('
        INSERT OR IGNORE INTO "analytics"
        (ip, dt, url, referer, ua, status) 
        VALUES (:ip, :dt, :url, :referer, :ua, :status)
    ');
       
    if ($stmt === FALSE) {
        error_log("Error preparing statement: " . $db->lastErrorMsg());
        return;
    }

    $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
    $stmt->bindValue(':dt', $dt, SQLITE3_INTEGER);
    $stmt->bindValue(':url', $url, SQLITE3_TEXT);
    $stmt->bindValue(':referer', $referer, SQLITE3_TEXT);
    $stmt->bindValue(':ua', $ua, SQLITE3_TEXT);
    $stmt->bindValue(':status', $status, SQLITE3_INTEGER);
    
    // duplicate are going to be recorded only once
    // execute() fails and returns FALSE but that's ok
    $stmt->execute();

    $stmt->close();
    $db->close();

    sem_release($semaphore);
}

function create_db($db_name = null) {
    $db_path = __DIR__ . '/' . ($db_name !== null ? $db_name : BANALYTIQ_DB);
    
    if (file_exists($db_path)) {
        error_log("Database already exists: $db_path");
        return;
    }
    
    $db = new SQLite3($db_path);

    $db->exec('PRAGMA journal_mode = WAL;');
    
    $db->exec('
        CREATE TABLE IF NOT EXISTS "analytics" (
            ip TEXT NOT NULL DEFAULT "",
            dt INTEGER NOT NULL,  -- Unix timestamp (seconds since epoch)
            url TEXT NOT NULL,
            referer TEXT DEFAULT "",
            ua TEXT DEFAULT "",
            status INT DEFAULT NULL,
            country TEXT DEFAULT NULL,
            city TEXT DEFAULT NULL,
            latitude REAL DEFAULT NULL,
            longitude REAL DEFAULT NULL,
            PRIMARY KEY(ip, dt, url)
        );
        CREATE INDEX IF NOT EXISTS idx_analytics_dt ON analytics(dt);
        CREATE INDEX IF NOT EXISTS idx_analytics_country ON analytics(country);
    ');

    $db->close();
}