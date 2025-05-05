<?php
// Handle file upload if needed
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['database_file'])) {
    // Set response header
    header('Content-Type: application/json');
    
    // Create a temporary uploads folder if it doesn't exist
    $upload_dir = __DIR__ . '/temp_uploads/';
    if (!file_exists($upload_dir)) {
        if (!mkdir($upload_dir, 0755, true)) {
            echo json_encode([
                'success' => false,
                'message' => 'Failed to create upload directory.'
            ]);
            exit;
        }
    }
    
    // Get the uploaded file
    $uploaded_file = $_FILES['database_file'];
    
    // Validate file type
    $allowed_extensions = ['db', 'sqlite', 'sqlite3'];
    $extension = strtolower(pathinfo($uploaded_file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($extension, $allowed_extensions)) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid file type. Only SQLite database files (.db, .sqlite, .sqlite3) are allowed.'
        ]);
        exit;
    }
    
    // Generate a unique filename to avoid conflicts
    $new_filename = uniqid('db_') . '.' . $extension;
    $destination = $upload_dir . $new_filename;
    
    // Move the uploaded file to our directory
    if (move_uploaded_file($uploaded_file['tmp_name'], $destination)) {
        // Validate that the file is actually a SQLite database
        try {
            $validate_db = new SQLite3($destination);
            
            // Check if it has the expected tables
            $table_check = $validate_db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='analytics'");
            $has_analytics_table = false;
            
            while ($table = $table_check->fetchArray()) {
                if ($table['name'] === 'analytics') {
                    $has_analytics_table = true;
                    break;
                }
            }
            
            $validate_db->close();
            
            if (!$has_analytics_table) {
                // Not a valid analytics database
                unlink($destination); // Delete the file
                echo json_encode([
                    'success' => false,
                    'message' => 'The uploaded file does not appear to be a valid analytics database.'
                ]);
                exit;
            }
            
            // Success
            echo json_encode([
                'success' => true,
                'path' => $destination,
                'filename' => $new_filename
            ]);
            exit;
            
        } catch (Exception $e) {
            // Not a valid SQLite database
            unlink($destination); // Delete the file
            echo json_encode([
                'success' => false,
                'message' => 'The uploaded file is not a valid SQLite database.'
            ]);
            exit;
        }
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to save the uploaded file.'
        ]);
        exit;
    }
}

// Configuration
$db_path = 'analytics.db';  // Default path

// Get all available database files from common locations
$available_dbs = [];

// Look in _db_ directory
$db_dir = __DIR__ . '/../_db_/';
if (is_dir($db_dir)) {
    foreach (glob($db_dir . '*.db') as $file) {
        $basename = basename($file);
        $available_dbs[$basename] = $file;
    }
}

// Look in _queue_ directory
$queue_dir = __DIR__ . '/../_queue_/';
if (is_dir($queue_dir)) {
    foreach (glob($queue_dir . '*.db') as $file) {
        $basename = basename($file);
        $available_dbs[$basename] = $file;
    }
}

// Look in _attic_ directory
$attic_dir = __DIR__ . '/../_attic_/';
if (is_dir($attic_dir)) {
    foreach (glob($attic_dir . '*.db') as $file) {
        $basename = basename($file);
        $available_dbs[$basename] = $file;
    }
}

// Look in current directory
foreach (glob(__DIR__ . '/*.db') as $file) {
    $basename = basename($file);
    $available_dbs[$basename] = $file;
}

// Look in temp_uploads directory if it exists
$upload_dir = __DIR__ . '/temp_uploads/';
if (is_dir($upload_dir)) {
    foreach (glob($upload_dir . '*.db') as $file) {
        $basename = basename($file);
        $available_dbs[$basename] = $file;
    }
}

// Check if a database path is specified in the query string
$selected_db_name = '';
if (isset($_GET['db']) && !empty($_GET['db'])) {
    $requested_db = $_GET['db'];
    
    if (array_key_exists($requested_db, $available_dbs)) {
        // Use the database from our discovered list
        $db_path = $available_dbs[$requested_db];
        $selected_db_name = $requested_db;
    } else {
        // Otherwise use the path as is, but validate it exists
        if (file_exists($requested_db) && is_readable($requested_db)) {
            $db_path = $requested_db;
            $selected_db_name = basename($requested_db);
        } else {
            die('Invalid database path requested or file not found');
        }
    }
} else {
    // No database specified, use the first available one
    if (!empty($available_dbs)) {
        reset($available_dbs);
        $selected_db_name = key($available_dbs);
        $db_path = $available_dbs[$selected_db_name];
    }
}

// Connect to SQLite database
try {
    $db = new SQLite3($db_path);
} catch (Exception $e) {
    die('Database connection failed: ' . $e->getMessage());
}

// Get visitor data for map visualization
$mapData = [];
$result = $db->query('
    SELECT country, city, latitude, longitude, COUNT(*) as visit_count 
    FROM analytics 
    WHERE latitude IS NOT NULL AND longitude IS NOT NULL
    GROUP BY country, city, latitude, longitude
    ORDER BY visit_count DESC
');

while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $mapData[] = $row;
}

// Get total visitor stats
$totalVisits = $db->querySingle('SELECT COUNT(*) FROM analytics');
$uniqueVisitors = $db->querySingle('SELECT COUNT(DISTINCT ip) FROM analytics');
$countriesCount = $db->querySingle('SELECT COUNT(DISTINCT country) FROM analytics WHERE country IS NOT NULL');

// Format data for JavaScript
$mapDataJSON = json_encode($mapData);

// Get top countries
$topCountries = [];
$result = $db->query('
    SELECT country, COUNT(*) as count 
    FROM analytics 
    WHERE country IS NOT NULL 
    GROUP BY country 
    ORDER BY count DESC 
    LIMIT 10
');

while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $topCountries[] = $row;
}

// Get recent visitors
$recentVisitors = [];
$result = $db->query('
    SELECT ip, dt, url, country, city 
    FROM analytics 
    ORDER BY dt DESC 
    LIMIT 10
');

while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    // Convert Unix timestamp to human-readable format
    $row['dt_formatted'] = date('Y-m-d H:i:s', $row['dt']);
    $recentVisitors[] = $row;
}

// Close the database connection
$db->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Website Visitor Analytics</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <!-- Replace jVectormap with Leaflet -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            padding: 20px;
        }
        h1, h2 {
            color: #333;
        }
        .map-container {
            height: 500px;
            margin-bottom: 30px;
            border-radius: 5px;
            overflow: hidden;
        }
        .stats-container {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
        }
        .stat-box {
            background-color: #f9f9f9;
            border-radius: 5px;
            padding: 15px;
            flex: 1;
            margin: 0 10px;
            text-align: center;
            box-shadow: 0 0 5px rgba(0,0,0,0.05);
        }
        .stat-box h3 {
            margin-top: 0;
            color: #666;
        }
        .stat-box p {
            font-size: 24px;
            font-weight: bold;
            margin: 10px 0 0;
            color: #333;
        }
        .data-tables {
            display: flex;
            justify-content: space-between;
        }
        .data-table {
            flex: 1;
            margin: 0 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        table th, table td {
            padding: 8px;
            border: 1px solid #ddd;
            text-align: left;
        }
        table th {
            background-color: #f2f2f2;
        }
        table tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .info-box {
            background-color: #e7f3ff;
            border-left: 4px solid #3b82f6;
            padding: 10px 15px;
            margin-bottom: 20px;
            border-radius: 3px;
        }
        /* Leaflet popup styling */
        .leaflet-popup-content {
            font-family: Arial, sans-serif;
            font-size: 14px;
            line-height: 1.4;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Website Visitor Analytics</h1>
        
        <div class="info-box">
            <form method="get" action="">
                <div style="margin-bottom: 10px;">
                    <label for="db">Select Database:</label>
                    <select name="db" id="db">
                        <option value="">-- Choose from available databases --</option>
                        <?php foreach ($available_dbs as $db_name => $db_path): ?>
                            <option value="<?php echo htmlspecialchars($db_name); ?>" <?php echo $db_name === $selected_db_name ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($db_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit">Load</button>
                </div>
                
                <div style="display: flex; align-items: center;">
                    <label for="custom_db">Or choose a file:</label>
                    <input type="file" id="custom_db" accept=".db,.sqlite,.sqlite3" style="margin: 0 10px;">
                    <button type="button" id="load_custom_db">Load Custom Database</button>
                </div>
            </form>
            
            <?php if (!empty($selected_db_name)): ?>
            <div style="margin-top: 10px;">
                <strong>Currently loaded:</strong> <?php echo htmlspecialchars($selected_db_name); ?>
            </div>
            <?php endif; ?>
        </div>
                
        <div class="stats-container">
            <div class="stat-box">
                <h3>Total Visits</h3>
                <p><?php echo number_format($totalVisits); ?></p>
            </div>
            <div class="stat-box">
                <h3>Unique Visitors</h3>
                <p><?php echo number_format($uniqueVisitors); ?></p>
            </div>
            <div class="stat-box">
                <h3>Countries</h3>
                <p><?php echo number_format($countriesCount); ?></p>
            </div>
        </div>
        
        <h2>Visitor Map</h2>
        <div class="map-container" id="visitor-map"></div>
        
        <div class="data-tables">
            <div class="data-table">
                <h2>Top Countries</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Country</th>
                            <th>Visits</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topCountries as $country): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($country['country']); ?></td>
                            <td><?php echo number_format($country['count']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="data-table">
                <h2>Recent Visitors</h2>
                <table>
                    <thead>
                        <tr>
                            <th>IP</th>
                            <th>Time</th>
                            <th>Location</th>
                            <th>Page</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentVisitors as $visitor): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($visitor['ip']); ?></td>
                            <td><?php echo htmlspecialchars($visitor['dt_formatted']); ?></td>
                            <td>
                                <?php
                                $location = [];
                                if (!empty($visitor['city'])) $location[] = htmlspecialchars($visitor['city']);
                                if (!empty($visitor['country'])) $location[] = htmlspecialchars($visitor['country']);
                                echo implode(', ', $location);
                                ?>
                            </td>
                            <td><?php echo htmlspecialchars($visitor['url']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        $(document).ready(function() {
            // Initialize Leaflet map with world bounds
            const map = L.map('visitor-map', {
                maxBounds: [[-90, -180], [90, 180]],
                minZoom: 1.4,
                maxZoom: 18,
                maxBoundsViscosity: 1.0
            }).setView([20, 0], 1.4);
            
            // Add tile layer (OpenStreetMap) with noWrap option to prevent multiple worlds
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
                noWrap: true, // Prevent tiles from wrapping around the world
                bounds: [[-90, -180], [90, 180]] // Set tile bounds to one world
            }).addTo(map);
            
            // Process map data and add markers
            function addVisitorMarkers() {
                const data = <?php echo $mapDataJSON; ?>;
                
                data.forEach(function(item) {
                    const lat = parseFloat(item.latitude);
                    const lng = parseFloat(item.longitude);
                    const visits = parseInt(item.visit_count);
                    
                    // Calculate marker size based on visit count (logarithmic scale)
                    const markerRadius = Math.min(Math.max(Math.log(visits) * 3, 5), 12);
                    
                    // Create circle marker with popup
                    const marker = L.circleMarker([lat, lng], {
                        radius: markerRadius,
                        fillColor: '#3b82f6',
                        color: '#ffffff',
                        weight: 1,
                        opacity: 1,
                        fillOpacity: 0.8
                    }).addTo(map);
                    
                    // Add popup with visitor information
                    marker.bindPopup(
                        '<b>' + item.city + ', ' + item.country + '</b><br>' + 
                        visits + ' visit' + (visits > 1 ? 's' : '')
                    );
                });
            }
            
            // Add markers to the map
            addVisitorMarkers();
            
            // Handle custom database file selection
            $('#load_custom_db').on('click', function() {
                const fileInput = document.getElementById('custom_db');
                
                if (fileInput.files.length === 0) {
                    alert('Please select a database file first.');
                    return;
                }
                
                const file = fileInput.files[0];
                
                // Create FormData to upload the file
                const formData = new FormData();
                formData.append('database_file', file);
                
                // Show loading state
                $(this).prop('disabled', true).text('Uploading...');
                
                // Upload the file to the current script
                $.ajax({
                    url: window.location.pathname, // Send to the same script
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function(response) {
                        try {
                            const result = JSON.parse(response);
                            if (result.success) {
                                // Redirect to visualize.php with the uploaded file path
                                window.location.href = window.location.pathname + '?db=' + encodeURIComponent(result.path);
                            } else {
                                alert('Error: ' + result.message);
                                $('#load_custom_db').prop('disabled', false).text('Load Custom Database');
                            }
                        } catch (e) {
                            alert('Error processing response: ' + response);
                            $('#load_custom_db').prop('disabled', false).text('Load Custom Database');
                        }
                    },
                    error: function() {
                        alert('Failed to upload file. Please try again.');
                        $('#load_custom_db').prop('disabled', false).text('Load Custom Database');
                    }
                });
            });
        });
    </script>
</body>
</html>
