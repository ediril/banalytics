<?php
// Set error reporting to show all errors
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (array_key_exists('HTTP_HOST', $_SERVER)) {
    http_response_code(404);
    echo "<!DOCTYPE html><html><head><title>404 Not Found</title></head><body>404 Not Found</body></html>";
    exit;
}

// Path to databases
$geo_db_path = __DIR__ . '/../GeoLite2-City-CSV_20250422/geolite2_20250422.db';

// Function to convert IP to integer
function ip2long_v4($ip) {
    return sprintf('%u', ip2long($ip));
}

// Function to check if IP is in CIDR range
function ip_in_range($ip, $cidr) {
    list($subnet, $mask) = explode('/', $cidr);
    
    $ip_decimal = ip2long($ip);
    $subnet_decimal = ip2long($subnet);
    $mask_decimal = ~((1 << (32 - $mask)) - 1);
    
    return ($ip_decimal & $mask_decimal) == ($subnet_decimal & $mask_decimal);
}

// Default path, can be changed in the arguments
$analytics_db_path = __DIR__ . '/banalytics.db'; 

// Check if an alternative analytics DB path was provided
if ($argc > 1) {
    $analytics_db_path = $argv[1];
}

echo "Using GeoLite2 database: $geo_db_path\n";
echo "Using analytics database: $analytics_db_path\n";

// Connect to the GeoLite2 database
try {
    $geo_db = new SQLite3($geo_db_path);
    $geo_db->enableExceptions(true);
    echo "Connected to GeoLite2 database.\n";
} catch (Exception $e) {
    die("Error connecting to GeoLite2 database: " . $e->getMessage() . "\n");
}

// Connect to the analytics database
try {
    $analytics_db = new SQLite3($analytics_db_path);
    $analytics_db->enableExceptions(true);
    echo "Connected to analytics database.\n";
} catch (Exception $e) {
    die("Error connecting to analytics database: " . $e->getMessage() . "\n");
}

// Prepare a query to get IPs that need geo data
$get_ips_stmt = $analytics_db->prepare('
    SELECT DISTINCT ip 
    FROM analytics 
    WHERE (latitude IS NULL OR longitude IS NULL) 
    AND ip != ""
');

// Prepare update statement for analytics database
$update_stmt = $analytics_db->prepare('
    UPDATE analytics 
    SET latitude = :latitude, longitude = :longitude, country = :country, city = :city
    WHERE ip = :ip
');

// Prepare a statement to find a block for an IP address
// We'll search for networks where this IP might be in range
$find_block_stmt = $geo_db->prepare('
    SELECT * FROM blocks
    WHERE network LIKE :ip_prefix
');

// Prepare for location lookups
$loc_stmt = $geo_db->prepare('
    SELECT 
        country_name,
        city_name
    FROM locations
    WHERE geoname_id = :geoname_id
    LIMIT 1
');

// Start updating the database
$result = $get_ips_stmt->execute();

$updated_count = 0;
$failed_count = 0;
$skipped_count = 0;

echo "Starting to update IP geolocation data...\n";

// Process each IP
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $ip = $row['ip'];
    
    // Skip invalid or private IPs
    if (empty($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false || 
        filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        $skipped_count++;
        continue;
    }
    
    // Extract the first two octets of the IP address to reduce the search space
    $ip_parts = explode('.', $ip);
    if (count($ip_parts) !== 4) {
        echo "Invalid IPv4 address format: $ip\n";
        $failed_count++;
        continue;
    }
    
    $ip_prefix = $ip_parts[0] . '.%';
    $find_block_stmt->bindValue(':ip_prefix', $ip_prefix, SQLITE3_TEXT);
    $blocks_result = $find_block_stmt->execute();
    
    $matching_block = null;
    
    // Check each potential block to see if our IP is in its range
    while ($block = $blocks_result->fetchArray(SQLITE3_ASSOC)) {
        if (ip_in_range($ip, $block['network'])) {
            $matching_block = $block;
            break;
        }
    }
    
    if ($matching_block) {
        $geo_info = [
            'latitude' => (float)$matching_block['latitude'],
            'longitude' => (float)$matching_block['longitude'],
            'country' => null,
            'city' => null
        ];
        
        // If we have a geoname_id, get location info
        $geoname_id = $matching_block['geoname_id'];
        if (!$geoname_id) {
            $geoname_id = $matching_block['registered_country_geoname_id'];
        }
        if (!$geoname_id) {
            $geoname_id = $matching_block['represented_country_geoname_id'];
        }
        
        if ($geoname_id && !empty($geoname_id)) {
            $loc_stmt->bindValue(':geoname_id', $geoname_id, SQLITE3_TEXT);
            $loc_result = $loc_stmt->execute();
            $loc_row = $loc_result->fetchArray(SQLITE3_ASSOC);
            
            if ($loc_row) {
                $geo_info['country'] = $loc_row['country_name'];
                $geo_info['city'] = $loc_row['city_name'];
            }
        }
        
        // Update the analytics database
        $update_stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
        $update_stmt->bindValue(':latitude', $geo_info['latitude'], SQLITE3_FLOAT);
        $update_stmt->bindValue(':longitude', $geo_info['longitude'], SQLITE3_FLOAT);
        $update_stmt->bindValue(':country', $geo_info['country'], SQLITE3_TEXT);
        $update_stmt->bindValue(':city', $geo_info['city'], SQLITE3_TEXT);
        
        try {
            $update_stmt->execute();
            $updated_count++;
            echo "Updated IP: $ip with lat: {$geo_info['latitude']}, long: {$geo_info['longitude']}, country: {$geo_info['country']}, city: {$geo_info['city']}\n";
        } catch (Exception $e) {
            echo "Failed to update IP $ip: " . $e->getMessage() . "\n";
            $failed_count++;
        }
    } else {
        echo "Could not find geolocation data for IP: $ip\n";
        $failed_count++;
    }
}

echo "Update completed.\n";
echo "Updated: $updated_count\n";
echo "Failed: $failed_count\n";
echo "Skipped: $skipped_count\n";

$analytics_db->close();
$geo_db->close();

echo "Database connections closed.\n";
?>