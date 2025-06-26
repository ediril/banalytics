<?php
// This script is used to update the geolocation data for IP addresses in the analytics database.
// Run it from the command line with the path to the analytics database as an argument.
// Use --merge <timestamped_db_file> to merge a timestamped database with the base banalytiq.db file.

if (array_key_exists('HTTP_HOST', $_SERVER)) {
    http_response_code(404);
    echo "<!DOCTYPE html><html><head><title>404 Not Found</title></head><body>404 Not Found</body></html>";
    exit;
}

require_once __DIR__ . '/defines.php';

// Simple function to check if IP is in CIDR range
function ip_in_range($ip, $cidr) {
    if (empty($ip) || empty($cidr)) return false;
    
    $cidr_parts = explode('/', $cidr);
    if (count($cidr_parts) !== 2) return false;
    
    list($subnet, $mask) = $cidr_parts;
    $ip_decimal = ip2long($ip);
    $subnet_decimal = ip2long($subnet);
    
    if ($ip_decimal === false || $subnet_decimal === false) return false;
    
    $mask = (int)$mask;
    if ($mask < 0 || $mask > 32) return false;
    
    $mask_decimal = ~((1 << (32 - $mask)) - 1);
    return ($ip_decimal & $mask_decimal) == ($subnet_decimal & $mask_decimal);
}

// Function to find all timestamped database files
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

function ip2geo($db_name = null) { 
    // Set up error reporting and memory
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    ini_set('memory_limit', '256M');
    
    // Handle interruption - exit immediately
    if (function_exists('pcntl_signal')) {
        pcntl_signal(SIGINT, function() {
            echo "\nInterruption signal received. Exiting immediately...\n";
            exit(130); // Standard exit code for SIGINT
        });
    }

    $geo_db_path = __DIR__ . '/' . GEO_DB;
    $analytics_db_path = __DIR__ . '/' . ($db_name !== null ? $db_name : BANALYTIQ_DB);

    echo "Using GeoLite2 database: $geo_db_path\n";
    echo "Using analytics database: $analytics_db_path\n";

    // Connect to databases
    try {
        $geo_db = new SQLite3($geo_db_path);
        $geo_db->enableExceptions(true);
        $analytics_db = new SQLite3($analytics_db_path);
        $analytics_db->enableExceptions(true);
        echo "Connected to databases.\n";
    } catch (Exception $e) {
        die("Database connection error: " . $e->getMessage() . "\n");
    }

    // Optimize databases
    $analytics_db->exec('PRAGMA journal_mode = WAL');
    $analytics_db->exec('PRAGMA synchronous = NORMAL');
    $analytics_db->exec('PRAGMA cache_size = 50000');
    $geo_db->exec('PRAGMA cache_size = 50000');

    // Create indexes for faster lookups
    try {
        $geo_db->exec('CREATE INDEX IF NOT EXISTS idx_blocks_network ON blocks(network)');
        $geo_db->exec('CREATE INDEX IF NOT EXISTS idx_locations_geoname ON locations(geoname_id)');
    } catch (Exception $e) {
        // Indexes may already exist
    }
    
    // Check if we should also look for empty strings instead of just NULLs
    $empty_check_stmt = $analytics_db->prepare('
        SELECT COUNT(DISTINCT ip) as count FROM analytics 
        WHERE (latitude = "" OR longitude = "" OR latitude IS NULL OR longitude IS NULL) 
        AND ip != ""
    ');
    $empty_result = $empty_check_stmt->execute();
    $empty_row = $empty_result->fetchArray(SQLITE3_ASSOC);
    
    // Prepare statements - let's include empty strings too
    $get_ips_stmt = $analytics_db->prepare('
        SELECT DISTINCT ip FROM analytics 
        WHERE (latitude IS NULL OR longitude IS NULL OR latitude = "" OR longitude = "") 
        AND ip != ""
    ');
    
    // Prepare statement for finding blocks (removed LIMIT to check all matching blocks)
    $find_all_blocks_stmt = $geo_db->prepare('
        SELECT network, latitude, longitude, geoname_id, 
               registered_country_geoname_id, represented_country_geoname_id
        FROM blocks WHERE network LIKE ?
    ');
    
    $find_location_stmt = $geo_db->prepare('
        SELECT country_name, city_name FROM locations WHERE geoname_id = ? LIMIT 1
    ');

    // Let's also try a broader query to see all possibilities
    $debug_stmt = $analytics_db->prepare('
        SELECT 
            COUNT(*) as total_nulls,
            COUNT(DISTINCT ip) as distinct_ips_with_nulls
        FROM analytics 
        WHERE (latitude IS NULL OR longitude IS NULL OR latitude = "" OR longitude = "")
        AND ip != ""
    ');
    $debug_result = $debug_stmt->execute();
    $debug_row = $debug_result->fetchArray(SQLITE3_ASSOC);
    echo "Total records needing geo data: {$debug_row['total_nulls']}, Distinct IPs: {$debug_row['distinct_ips_with_nulls']}\n";
    
        // Get all IPs to process (only query once)
    $result = $get_ips_stmt->execute();
    $ips_to_process = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $ips_to_process[] = $row['ip'];
    }
    $total_ips = count($ips_to_process);
    echo "Found $total_ips distinct IPs to process.\n";
        

    
    $updated_count = 0;
    $failed_count = 0; 
    $skipped_count = 0;
    $batch_size = 1000;
    $updates_buffer = [];
    $processed_ips = 0;
    $start_time = time();

    $analytics_db->exec('BEGIN TRANSACTION');

    try {
         foreach ($ips_to_process as $ip) {
             if (function_exists('pcntl_signal_dispatch')) {
                 pcntl_signal_dispatch();
             }
             $processed_ips++;
             
             // Show progress every 10 IPs
             if ($processed_ips % 10 == 0) {
                 $elapsed = time() - $start_time;
                 $rate = $processed_ips / max($elapsed, 1);
                 $rate_formatted = number_format($rate, 1);
                 echo "Processed $processed_ips/$total_ips IPs ($rate_formatted IPs/sec), updated: $updated_count\n";
             }
            
            // Skip invalid/private IPs
            if (empty($ip) || 
                filter_var($ip, FILTER_VALIDATE_IP) === false || 
                filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                $skipped_count++;
                continue;
            }

            // Find geo data - search all blocks with same first two octets
            $geo_info = null;
            $ip_parts = explode('.', $ip);
            if (count($ip_parts) === 4) {
                // Search for all blocks starting with first two octets (no LIMIT to ensure we check all)
                $pattern = $ip_parts[0] . '.' . $ip_parts[1] . '.%';
                $find_all_blocks_stmt->bindValue(1, $pattern, SQLITE3_TEXT);
                $blocks_result = $find_all_blocks_stmt->execute();
                
                $blocks_tested = 0;
                while ($block = $blocks_result->fetchArray(SQLITE3_ASSOC)) {
                    if ($block && isset($block['network'])) {
                        $blocks_tested++;
                        
                        if (ip_in_range($ip, $block['network'])) {
                            $geo_info = [
                                'latitude' => (float)$block['latitude'],
                                'longitude' => (float)$block['longitude'], 
                                'country' => null,
                                'city' => null
                            ];
                            
                            // Get location info
                            $geoname_id = $block['geoname_id'] ?: 
                                         $block['registered_country_geoname_id'] ?: 
                                         $block['represented_country_geoname_id'];
                            
                            if ($geoname_id) {
                                $find_location_stmt->bindValue(1, $geoname_id, SQLITE3_TEXT);
                                $loc_result = $find_location_stmt->execute();
                                $loc_row = $loc_result->fetchArray(SQLITE3_ASSOC);
                                if ($loc_row) {
                                    $geo_info['country'] = $loc_row['country_name'];
                                    $geo_info['city'] = $loc_row['city_name'];
                                }
                            }
                            break; // Found a match!
                        }
                    }
                }
                
                // If no match with first two octets, try first octet only
                if (!$geo_info) {
                    $pattern = $ip_parts[0] . '.%';
                    $find_all_blocks_stmt->bindValue(1, $pattern, SQLITE3_TEXT);
                    $blocks_result = $find_all_blocks_stmt->execute();
                    
                    $blocks_tested = 0;
                    while ($block = $blocks_result->fetchArray(SQLITE3_ASSOC)) {
                        if ($block && isset($block['network'])) {
                            $blocks_tested++;
                            
                            if (ip_in_range($ip, $block['network'])) {
                                $geo_info = [
                                    'latitude' => (float)$block['latitude'],
                                    'longitude' => (float)$block['longitude'], 
                                    'country' => null,
                                    'city' => null
                                ];
                                
                                // Get location info
                                $geoname_id = $block['geoname_id'] ?: 
                                             $block['registered_country_geoname_id'] ?: 
                                             $block['represented_country_geoname_id'];
                                
                                if ($geoname_id) {
                                    $find_location_stmt->bindValue(1, $geoname_id, SQLITE3_TEXT);
                                    $loc_result = $find_location_stmt->execute();
                                    $loc_row = $loc_result->fetchArray(SQLITE3_ASSOC);
                                    if ($loc_row) {
                                        $geo_info['country'] = $loc_row['country_name'];
                                        $geo_info['city'] = $loc_row['city_name'];
                                    }
                                }
                                break; // Found a match!
                            }
                        }
                    }
                }
            }

            if ($geo_info) {
                $updates_buffer[] = [
                    'ip' => $ip,
                    'latitude' => $geo_info['latitude'],
                    'longitude' => $geo_info['longitude'],
                    'country' => $geo_info['country'],
                    'city' => $geo_info['city']
                ];
                
                // Process batch
                if (count($updates_buffer) >= $batch_size) {
                    $updated_count += process_batch($analytics_db, $updates_buffer);
                    $updates_buffer = [];
                    $analytics_db->exec('COMMIT; BEGIN TRANSACTION');
                    echo "Updated $updated_count IPs so far...\n";
                }
            } else {
                $failed_count++;
            }
        }

        // Process remaining
        if (!empty($updates_buffer)) {
            $updated_count += process_batch($analytics_db, $updates_buffer);
        }
        
        $analytics_db->exec('COMMIT');
        
    } catch (Exception $e) {
        $analytics_db->exec('ROLLBACK');
        throw $e;
    }

    echo "\n=== RESULTS ===\n";
    echo "Distinct IPs processed: $total_ips\n";
    echo "IPs successfully geolocated: $updated_count\n";
    echo "IPs failed (no geo data): $failed_count\n";
    echo "IPs skipped (invalid/private): $skipped_count\n";
     
    // Show how many total records were updated
    $final_check_stmt = $analytics_db->prepare('
        SELECT COUNT(*) as remaining FROM analytics 
        WHERE (latitude IS NULL OR longitude IS NULL OR latitude = "" OR longitude = "") 
        AND ip != ""
    ');
    $final_result = $final_check_stmt->execute();
    $final_row = $final_result->fetchArray(SQLITE3_ASSOC);
    echo "Records still needing geo data: {$final_row['remaining']}\n";

    $analytics_db->close();
    $geo_db->close();
}

// Simple batch update function
function process_batch($db, $updates) {
    if (empty($updates)) return 0;
    
    $sql = "UPDATE analytics SET latitude = CASE ip ";
    $sql_lng = "longitude = CASE ip ";
    $sql_country = "country = CASE ip ";
    $sql_city = "city = CASE ip ";
    $ips = [];
    
    foreach ($updates as $update) {
        $ip = SQLite3::escapeString($update['ip']);
        $lat = $update['latitude'];
        $lng = $update['longitude']; 
        $country = $update['country'] ? "'" . SQLite3::escapeString($update['country']) . "'" : 'NULL';
        $city = $update['city'] ? "'" . SQLite3::escapeString($update['city']) . "'" : 'NULL';
        
        $sql .= "WHEN '$ip' THEN $lat ";
        $sql_lng .= "WHEN '$ip' THEN $lng ";
        $sql_country .= "WHEN '$ip' THEN $country ";
        $sql_city .= "WHEN '$ip' THEN $city ";
        $ips[] = "'$ip'";
    }
    
    $ip_list = implode(',', $ips);
    $final_sql = $sql . "END, " . $sql_lng . "END, " . $sql_country . "END, " . $sql_city . "END WHERE ip IN ($ip_list)";
    
    try {
        $db->exec($final_sql);
        return count($updates);
    } catch (Exception $e) {
        echo "Batch update failed: " . $e->getMessage() . "\n";
        return 0;
    }
}

// Parse command line arguments
function show_usage() {
    echo "Usage:\n";
    echo "  php geo.php                    - Update geolocation data for banalytiq.db\n";
    echo "  php geo.php --merge            - Merge ALL timestamped databases + fill geo fields\n";
    echo "  php geo.php --merge-only       - Merge ALL timestamped databases (no geo processing)\n";
    echo "\n";
    echo "Examples:\n";
    echo "  php geo.php                    - Process default database for geo data\n";
    echo "  php geo.php --merge            - Merge all banalytiq.{timestamp}.db files + process geo\n";
    echo "  php geo.php --merge-only       - Only merge files, skip geo processing\n";
    echo "\n";
    echo "Merge process:\n";
    echo "  - Finds all files matching: banalytiq.{timestamp}.db\n";
    echo "  - Merges them one by one (oldest first)\n";
    echo "  - Only adds new records (preserves existing data)\n";
    echo "  - Renames each file to .bak after successful merge\n";
    echo "  - --merge also fills in geo fields, --merge-only skips geo processing\n";
}

// Run if called directly
if ($argc > 1) {
    if ($argv[1] === '--help' || $argv[1] === '-h') {
        show_usage();
        exit(0);
    } elseif ($argv[1] === '--merge') {
        merge_all_databases();
        echo "\n=== Starting geo processing for merged data ===\n";
        ip2geo();
    } elseif ($argv[1] === '--merge-only') {
        merge_all_databases();
    } else {
        echo "Error: Unknown argument '{$argv[1]}'\n\n";
        show_usage();
        exit(1);
    }
} else {
    ip2geo();
}
?>