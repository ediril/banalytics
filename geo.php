<?php
// This script is used to update the geolocation data for IP addresses in the analytics database.
// Run it from the command line with the path to the analytics database as an argument.

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
    
    // Debug: Check database structure and sample data
    echo "DEBUG: Checking GeoLite2 database structure...\n";
    $sample_blocks = $geo_db->query('SELECT network, latitude, longitude FROM blocks LIMIT 3');
    while ($row = $sample_blocks->fetchArray(SQLITE3_ASSOC)) {
        echo "DEBUG: Sample block - network: {$row['network']}, lat: {$row['latitude']}, lng: {$row['longitude']}\n";
    }

    // Check if we should also look for empty strings instead of just NULLs
    $empty_check_stmt = $analytics_db->prepare('
        SELECT COUNT(DISTINCT ip) as count FROM analytics 
        WHERE (latitude = "" OR longitude = "" OR latitude IS NULL OR longitude IS NULL) 
        AND ip != ""
    ');
    $empty_result = $empty_check_stmt->execute();
    $empty_row = $empty_result->fetchArray(SQLITE3_ASSOC);
    echo "DEBUG: IPs with NULL or empty lat/lng (including empty strings): {$empty_row['count']}\n";
    
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

    // Debug: Let's see what the query is actually finding
    echo "DEBUG: Using query: SELECT DISTINCT ip FROM analytics WHERE (latitude IS NULL OR longitude IS NULL) AND ip != \"\"\n";
    
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
    echo "DEBUG: Total records needing geo data: {$debug_row['total_nulls']}, Distinct IPs: {$debug_row['distinct_ips_with_nulls']}\n";
    
        // Get all IPs to process (only query once)
    $result = $get_ips_stmt->execute();
    $ips_to_process = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $ips_to_process[] = $row['ip'];
    }
    $total_ips = count($ips_to_process);
    echo "Found $total_ips distinct IPs to process.\n";
    
    // Debug: Test the ip_in_range function with your example
    echo "DEBUG: Testing ip_in_range function...\n";
    $test_ip = "98.149.172.0";
    $test_networks = ["98.149.168.0/21", "98.149.176.0/20"];
    foreach ($test_networks as $network) {
        $result = ip_in_range($test_ip, $network) ? "MATCH" : "NO MATCH";
        echo "DEBUG: $test_ip vs $network = $result\n";
    }
    
    // Debug: Test your specific failing case
    echo "DEBUG: Testing failing case...\n";
    $failing_ip = "86.104.252.0";
    $expected_network = "86.104.252.0/23";
    $result = ip_in_range($failing_ip, $expected_network) ? "MATCH" : "NO MATCH";
    echo "DEBUG: $failing_ip vs $expected_network = $result\n";
    
    // Debug: Comprehensive ip_in_range testing
    echo "DEBUG: Comprehensive ip_in_range testing...\n";
    $test_cases = [
        ["86.104.252.0", "86.104.252.0/23", true],   // Exact match
        ["86.104.252.1", "86.104.252.0/23", true],   // Within range  
        ["86.104.253.255", "86.104.252.0/23", true], // End of range (/23 = 86.104.252.0-86.104.253.255)
        ["86.104.254.0", "86.104.252.0/23", false],  // Outside range
        ["98.149.172.0", "98.149.168.0/21", true],   // Your other example
        ["192.168.1.1", "192.168.1.0/24", true],     // Common case
    ];
    
    foreach ($test_cases as $test) {
        $ip = $test[0];
        $network = $test[1]; 
        $expected = $test[2];
        $actual = ip_in_range($ip, $network);
        $status = ($actual === $expected) ? "✓ PASS" : "✗ FAIL";
        $result_text = $actual ? "MATCH" : "NO MATCH";
        echo "DEBUG: $status - $ip vs $network = $result_text (expected: " . ($expected ? "MATCH" : "NO MATCH") . ")\n";
        
        if ($actual !== $expected) {
            // Show detailed calculation for failed cases
            $parts = explode('/', $network);
            $subnet = $parts[0];
            $mask_bits = (int)$parts[1];
            $ip_long = ip2long($ip);
            $subnet_long = ip2long($subnet);
            $mask_long = ~((1 << (32 - $mask_bits)) - 1);
            
            echo "DEBUG: DETAILED - IP: $ip_long, Subnet: $subnet_long, Mask: $mask_long, IP&Mask: " . ($ip_long & $mask_long) . ", Subnet&Mask: " . ($subnet_long & $mask_long) . "\n";
        }
    }
    
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

// Run if called directly
if ($argc > 1) {
    ip2geo($argv[1]);
} else {
    ip2geo();
}
?>