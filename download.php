<?php
// This script is used to download the analytics database files from the FTP server.
// It will only work on localhost or 127.0.0.1.

// Example ftp_config.yaml file:
//
// host: example.com
// username: user
// password: pass
// remote_path: /banalytics

if (array_key_exists('HTTP_HOST', $_SERVER)) {
    http_response_code(404);
    echo "<!DOCTYPE html><html><head><title>404 Not Found</title></head><body>404 Not Found</body></html>";
    exit;
}

require_once __DIR__ . '/defines.php';

function find_timestamped_databases() {
    $timestamped_files = [];
    $base_name = pathinfo(BANALYTIQ_DB, PATHINFO_FILENAME); // "banalytiq"
    
    // Look for files matching pattern: banalytiq.{timestamp}.db
    $files = glob(__DIR__ . "/{$base_name}.*.db");
    
    foreach ($files as $file) {
        $filename = basename($file);
        // Skip the main database file and any .bak files
        if ($filename !== BANALYTIQ_DB && !str_ends_with($filename, '.bak')) {
            // Extract timestamp part
            $pattern = "/^{$base_name}\.(\d+)\.db$/";
            if (preg_match($pattern, $filename, $matches)) {
                $timestamp = (int)$matches[1];
                $timestamped_files[] = [
                    'file' => $filename,
                    'path' => $file,
                    'timestamp' => $timestamp
                ];
            }
        }
    }
    
    // Sort by timestamp (oldest first)
    usort($timestamped_files, function($a, $b) {
        return $a['timestamp'] - $b['timestamp'];
    });
    
    return $timestamped_files;
}

// Function to merge all timestamped databases with base database
function merge_all_databases() {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    
    $base_db_path = __DIR__ . '/' . BANALYTIQ_DB;
    
    // Validate base database exists
    if (!file_exists($base_db_path)) {
        die("Error: Base database file not found: $base_db_path\n");
    }
    
    // Find all timestamped database files
    $timestamped_files = find_timestamped_databases();
    
    if (empty($timestamped_files)) {
        echo "No timestamped database files found.\n";
        echo "Looking for files matching pattern: " . pathinfo(BANALYTIQ_DB, PATHINFO_FILENAME) . ".{timestamp}.db\n";
        return;
    }
    
    echo "Found " . count($timestamped_files) . " timestamped database file(s) to merge:\n";
    foreach ($timestamped_files as $file_info) {
        $date = date('Y-m-d H:i:s', $file_info['timestamp']);
        echo "  - {$file_info['file']} (timestamp: {$date})\n";
    }
    echo "\n";
    
    $total_merged = 0;
    $successfully_processed = [];
    
    foreach ($timestamped_files as $file_info) {
        echo "=== Processing {$file_info['file']} ===\n";
        
        try {
            $result = merge_single_database($file_info['file']);
            if ($result['success']) {
                $successfully_processed[] = $file_info;
                echo "✓ Successfully merged {$result['inserted_count']} new records\n";
            } else {
                echo "✗ Failed to merge {$file_info['file']}: {$result['error']}\n";
            }
        } catch (Exception $e) {
            echo "✗ Error processing {$file_info['file']}: " . $e->getMessage() . "\n";
        }
        
        echo "\n";
    }
    
    echo "=== FINAL SUMMARY ===\n";
    echo "Total files processed: " . count($timestamped_files) . "\n";
    echo "Successfully merged files: " . count($successfully_processed) . "\n";
}

// Function to merge a single timestamped database with base database
function merge_single_database($timestamped_db_file) {
    $base_db_path = __DIR__ . '/' . BANALYTIQ_DB;
    $timestamped_db_path = __DIR__ . '/' . $timestamped_db_file;
    
    // Validate input files
    if (!file_exists($timestamped_db_path)) {
        return ['success' => false, 'error' => "Timestamped database file not found: $timestamped_db_path"];
    }
    
    if (!file_exists($base_db_path)) {
        return ['success' => false, 'error' => "Base database file not found: $base_db_path"];
    }
    
    $base_db = null;
    $timestamped_db = null;
    $transaction_active = false;
    
    try {
        // Open both databases
        $base_db = new SQLite3($base_db_path);
        $base_db->enableExceptions(true);
        $timestamped_db = new SQLite3($timestamped_db_path);
        $timestamped_db->enableExceptions(true);
        
        // Set WAL mode and optimize base database
        $base_db->exec('PRAGMA journal_mode = WAL');
        $base_db->exec('PRAGMA synchronous = NORMAL');
        $base_db->exec('PRAGMA cache_size = 50000');
        
        // Get all records from timestamped database (geo fields will be NULL)
        $timestamped_records = [];
        $result = $timestamped_db->query('SELECT ip, dt, url, referer, ua, status FROM analytics');
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $key = $row['ip'] . '|' . $row['dt'] . '|' . $row['url'];
            $timestamped_records[$key] = $row;
        }
        
        // Get all existing keys from base database
        $existing_keys = [];
        $result = $base_db->query('SELECT ip, dt, url FROM analytics');
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $key = $row['ip'] . '|' . $row['dt'] . '|' . $row['url'];
            $existing_keys[$key] = true;
        }
        
        // Find new records
        $new_records = [];
        foreach ($timestamped_records as $key => $record) {
            if (!isset($existing_keys[$key])) {
                $new_records[] = $record;
            }
        }
        
        $new_count = count($new_records);
        
        if ($new_count > 0) {
            echo "Found $new_count new records to insert.\n";
        } else {
            echo "No new records to merge. Database is already up to date.\n";
            $timestamped_db->close();
            $base_db->close();
            return ['success' => true, 'inserted_count' => 0];
        }
        
        // Insert new records into base database in batches
        $base_db->exec('BEGIN TRANSACTION');
        $transaction_active = true;
        
        $insert_stmt = $base_db->prepare('
            INSERT INTO analytics (ip, dt, url, referer, ua, status, country, city, latitude, longitude)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        
        $batch_size = 1000;
        $inserted_count = 0;
        
        foreach ($new_records as $i => $record) {
            $insert_stmt->bindValue(1, $record['ip'], SQLITE3_TEXT);
            $insert_stmt->bindValue(2, $record['dt'], SQLITE3_INTEGER);
            $insert_stmt->bindValue(3, $record['url'], SQLITE3_TEXT);
            $insert_stmt->bindValue(4, $record['referer'], SQLITE3_TEXT);
            $insert_stmt->bindValue(5, $record['ua'], SQLITE3_TEXT);
            $insert_stmt->bindValue(6, $record['status'], SQLITE3_INTEGER);
            $insert_stmt->bindValue(7, null, SQLITE3_TEXT);  // country - always NULL in new downloads
            $insert_stmt->bindValue(8, null, SQLITE3_TEXT);  // city - always NULL in new downloads
            $insert_stmt->bindValue(9, null, SQLITE3_NUM);   // latitude - always NULL in new downloads
            $insert_stmt->bindValue(10, null, SQLITE3_NUM);  // longitude - always NULL in new downloads
            
            $insert_stmt->execute();
            $insert_stmt->reset();
            $inserted_count++;
            
            // Commit in batches and show progress
            if (($i + 1) % $batch_size == 0) {
                $base_db->exec('COMMIT; BEGIN TRANSACTION');
                echo "Inserted $inserted_count/$new_count records...\n";
            }
        }
        
        $base_db->exec('COMMIT');
        $transaction_active = false;
        
        // Close databases before file operations
        $timestamped_db->close();
        $base_db->close();
        
        // Rename timestamped database to .bak
        $backup_path = $timestamped_db_path . '.bak';
        if (rename($timestamped_db_path, $backup_path)) {
            echo "Timestamped database renamed to: " . basename($backup_path) . "\n";
        } else {
            echo "Warning: Could not rename timestamped database file\n";
        }
        
        return ['success' => true, 'inserted_count' => $inserted_count];        
    } catch (Exception $e) {
        if ($base_db && $transaction_active) {
            try {
                $base_db->exec('ROLLBACK');
            } catch (Exception $rollback_e) {
                // Ignore rollback errors if transaction wasn't active
            }
        }
        if ($base_db) {
            $base_db->close();
        }
        if ($timestamped_db) {
            $timestamped_db->close();
        }
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function download_analytics_files($config_path = 'ftp_config.yaml') {
    // Check if YAML extension is available
    if (!extension_loaded('yaml')) {
        die('YAML extension is required. Please install php-yaml extension.');
    }
    
    // Load YAML configuration
    if (!file_exists($config_path)) {
        die("ERROR: Config file not found: $config_path. Please create it.\n");
    }
    
    $config = yaml_parse_file($config_path);
    if (!$config) {
        die("Failed to parse config file: $config_path");
    }
    
    // Required config parameters
    $required = ['host', 'username', 'password', 'remote_path'];
    foreach ($required as $param) {
        if (!isset($config[$param])) {
            die("Missing required configuration parameter: $param");
        }
    }
    
    // Connect to FTP server
    // If a custom port is provided in the YAML config, pass it to ftp_ssl_connect; otherwise, rely on the default port (21).
    if (isset($config['port']) && $config['port'] !== '') {
        $conn = ftp_ssl_connect($config['host'], (int)$config['port']);
    } else {
        $conn = ftp_ssl_connect($config['host']);
    }
    if (!$conn) {
        die("Failed to connect to FTP server: {$config['host']}");
    }
    
    // Login to FTP
    if (!ftp_login($conn, $config['username'], $config['password'])) {
        ftp_close($conn);
        die("Failed to login to FTP server with provided credentials");
    }
    
    // Set passive mode
    ftp_pasv($conn, true);

    $local_file = __DIR__ . '/banalytiq.db';
    if (file_exists($local_file)) {
        $local_file = __DIR__ . '/banalytiq.' . time() . '.db';
        echo "banalytiq.db already exists, downloading to `$local_file`\n";
    }

    // if $config['remote_path'] doesn't end with a slash, add it
    if ($config['remote_path'][-1] !== '/') {
        $config['remote_path'] .= '/';
    }
    
    $remote_file = $config['remote_path'] . 'banalytiq.db';
        
    // Download file
    if (ftp_get($conn, $local_file, $remote_file, FTP_BINARY)) {
        echo "Successfully downloaded to $local_file\n";        
        return true;
    } else {
        echo "ERROR: Failed to download\n";
        return false;
    }
    
    // Close FTP connection
    @ftp_close($conn);
}

// Show usage information
function show_usage() {
    echo "Usage:\n";
    echo "  php download.php [config_file]          - Download and merge\n";
    echo "  php download.php --no-merge [config_file] - Download only, skip merging\n";
    echo "  php download.php --merge-only            - Merge existing timestamped databases only\n";
    echo "  php download.php --help                  - Show this help\n";
    echo "\n";
    echo "Examples:\n";
    echo "  php download.php                         - Download using ftp_config.yaml and merge\n";
    echo "  php download.php custom_config.yaml     - Download using custom config and merge\n";
    echo "  php download.php --no-merge              - Download using ftp_config.yaml, no merge\n";
    echo "  php download.php --merge-only            - Only merge existing timestamped databases\n";
    echo "\n";
}

// Parse command line arguments and run
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    $config_path = 'ftp_config.yaml';
    $should_merge = true;
    $download_only = false;
    $merge_only = false;
    
    // Parse arguments
    if ($argc > 1) {
        if ($argv[1] === '--help' || $argv[1] === '-h') {
            show_usage();
            exit(0);
        } elseif ($argv[1] === '--no-merge') {
            $should_merge = false;
            $download_only = true;
            // Check if config file provided after --no-merge
            if (isset($argv[2])) {
                $config_path = $argv[2];
            }
        } elseif ($argv[1] === '--merge-only') {
            $merge_only = true;
            $download_only = false;
            $should_merge = true;
        } else {
            // Assume it's a config file path
            $config_path = $argv[1];
        }
    }
    
    if ($merge_only) {
        echo "=== Merging existing timestamped databases ===\n";
        merge_all_databases();
    } else {
        // Download files
        echo "=== Downloading database files ===\n";
        $download_success = download_analytics_files($config_path);
        
        if ($download_success && $should_merge) {
            echo "\n=== Merging timestamped databases ===\n";
            merge_all_databases();
        } elseif (!$should_merge) {
            echo "Skipping merge step (--no-merge specified)\n";
        }
    }
}
?>