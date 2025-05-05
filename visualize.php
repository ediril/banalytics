<?php

// Initialize variables
$mapData = [];
$mapDataJSON = '[]';
$totalVisits = 0;
$uniqueVisitors = 0;
$countriesCount = 0;
$topCountries = [];
$recentVisitors = [];
$error_message = null;

$db_path = __DIR__ . '/banalytics.db';

$custom_name = isset($_GET['db']) ? $_GET['db'] : '';
if (!empty($custom_name)) {
    $db_path = __DIR__ . '/' . $custom_name;
    
    # if custom_name does not end with .db, append .db
    if (substr($db_path, -3) !== '.db') {
        $db_path .= '.db';
    }
}

if (!file_exists($db_path)) {
    echo "Database file not found: $db_path";
    return;
}

try {
    $db = new SQLite3($db_path);
    
    try {
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
    } finally {
        // Close the database connection regardless of whether an exception occurred
        $db->close();
    }
} catch (Exception $e) {
    echo 'Database connection failed: ' . $e->getMessage();
    return;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Website Visitor Analytics</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
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
        <h1>Visitor Banalytics</h1>
        <h3>Database file: <?php echo htmlspecialchars(basename($db_path)); ?></h3>

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
                    if (item.city) {
                        marker.bindPopup(
                            '<b>' + item.city + ', ' + item.country + '</b><br>' + 
                            visits + ' visit' + (visits > 1 ? 's' : '')
                        );
                    } else {
                        marker.bindPopup(
                            '<b>' + item.country + '</b><br>' + 
                            visits + ' visit' + (visits > 1 ? 's' : '')
                        );
                    }
                    marker.on('mouseover', function(e) {
                        this.openPopup();
                    });
                    marker.on('mouseout', function(e) {
                        this.closePopup();
                    });
                });
            }
            
            // Add markers to the map
            addVisitorMarkers();
        });
    </script>
</body>
</html>
