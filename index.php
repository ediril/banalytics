<?php
// This script visualizes website visitor data on a map using Leaflet.js and SQLite3.
// It will only work on localhost or 127.0.0.1.

require_once __DIR__ . '/defines.php';

if (!str_starts_with($_SERVER['HTTP_HOST'], 'localhost') && !str_starts_with($_SERVER['HTTP_HOST'], '127.0.0.1')) {
    http_response_code(404);
    echo "<!DOCTYPE html><html><head><title>404 Not Found</title></head><body>404 Not Found</body></html>";
    exit;
}

// Initialize variables
$mapData = [];
$mapDataJSON = '[]';
$totalVisits = 0;
$uniqueVisitors = 0;
$countriesCount = 0;
$topCountries = [];
$topPages = [];
$topReferers = [];
$error_message = null;
$ipsNeedingGeocodingCount = 0;

// Define available time windows and their labels
$timeWindows = [
    '1D' => 60 * 60 * 24,        // 1 day in seconds
    '1W' => 60 * 60 * 24 * 7,       // 1 week in seconds
    '1M' => 60 * 60 * 24 * 30,      // 1 month in seconds (30 days)
    '3M' => 60 * 60 * 24 * 90,      // 3 months in seconds (90 days)
    '6M' => 60 * 60 * 24 * 180,     // 6 months in seconds (180 days)
    'ALL' => 0            // 0 means no filter
];

// Get time window from URL parameter, default to 1M (1 month)
$timeWindow = isset($_GET['time']) && array_key_exists($_GET['time'], $timeWindows) ? $_GET['time'] : '1M';
$timeFilter = $timeWindows[$timeWindow];

$custom_name = isset($_GET['db']) ? $_GET['db'] : '';
$db_path = __DIR__ . '/' . (!empty($custom_name) ? $custom_name : BANALYTIQ_DB);
if (substr($db_path, -3) !== '.db') {
    $db_path .= '.db';
}

if (!file_exists($db_path)) {
    echo "Database file not found: $db_path";
    exit;
}

try {
    $db = new SQLite3($db_path);
    
    // Check if there are IPs that need geocoding
    $ipsNeedingGeocodingCount = $db->querySingle("
        SELECT COUNT(DISTINCT ip) 
        FROM analytics 
        WHERE latitude IS NULL
        AND ip != 'localhost'
        AND ip != '127.0.0.1'
        AND ip != '::1.0'
    ");
    
    try {
        // Prepare time filter condition
        $whereTimeClause = '';
        if ($timeFilter > 0) {
            $cutoffTime = time() - $timeFilter;
            $whereTimeClause = "AND dt >= $cutoffTime";
        }
        
        // Get visitor data for map visualization
        $mapData = [];
        $result = $db->query("
            SELECT country, city, latitude, longitude, COUNT(*) as visit_count 
            FROM analytics 
            WHERE latitude IS NOT NULL AND longitude IS NOT NULL AND status BETWEEN 200 AND 299 $whereTimeClause
            GROUP BY country, city, latitude, longitude
            ORDER BY visit_count DESC
        ");

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $mapData[] = $row;
        }

        // Get total visitor stats with time filter
        $totalVisits = $db->querySingle("SELECT COUNT(*) FROM analytics WHERE status BETWEEN 200 AND 299 $whereTimeClause");
        $uniqueVisitors = $db->querySingle("SELECT COUNT(DISTINCT ip) FROM analytics WHERE status BETWEEN 200 AND 299 $whereTimeClause");
        $countriesCount = $db->querySingle("SELECT COUNT(DISTINCT country) FROM analytics WHERE country IS NOT NULL AND status BETWEEN 200 AND 299 $whereTimeClause");

        // Format data for JavaScript
        $mapDataJSON = json_encode($mapData);

        // Get top countries with time filter
        $topCountries = [];
        $result = $db->query("
            SELECT country, COUNT(*) as count 
            FROM analytics 
            WHERE country IS NOT NULL AND status BETWEEN 200 AND 299 $whereTimeClause
            GROUP BY country 
            ORDER BY count DESC 
            LIMIT 10
        ");

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $topCountries[] = $row;
        }
        // Get top visited pages with time filter
        $topPages = [];
        $result = $db->query("
            SELECT REPLACE(url, '://www.', '://') AS url, \n                   COUNT(*) AS total_visits, \n                   COUNT(DISTINCT ip) AS unique_visits\n            FROM analytics \n            WHERE url IS NOT NULL AND status BETWEEN 200 AND 299 $whereTimeClause\n            GROUP BY REPLACE(url, '://www.', '://')\n            ORDER BY total_visits DESC \n            LIMIT 10\n        ");

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $topPages[] = $row;
        }

        // Build data for charts -----------------------------------
        // DAILY (all time)
        $dailyVisits = [];
        $result = $db->query("SELECT strftime('%Y-%m-%d', dt, 'unixepoch') AS period, COUNT(*) AS cnt FROM analytics WHERE status BETWEEN 200 AND 299 GROUP BY period ORDER BY period ASC");
        while($row=$result->fetchArray(SQLITE3_ASSOC)){$dailyVisits[]=$row;}

        // WEEKLY (ISO week) all time
        $weeklyVisits = [];
        $result = $db->query("SELECT strftime('%Y-%W', dt, 'unixepoch') AS period, COUNT(*) AS cnt FROM analytics WHERE status BETWEEN 200 AND 299 GROUP BY period ORDER BY period ASC");
        while($row=$result->fetchArray(SQLITE3_ASSOC)){$weeklyVisits[]=$row;}

        // MONTHLY all time
        $monthlyVisits = [];
        $result = $db->query("SELECT strftime('%Y-%m', dt, 'unixepoch') AS period, COUNT(*) AS cnt FROM analytics WHERE status BETWEEN 200 AND 299 GROUP BY period ORDER BY period ASC");
        while($row=$result->fetchArray(SQLITE3_ASSOC)){$monthlyVisits[]=$row;}

        // Get top referrers with time filter (exclude empty strings)
        $result = $db->query("\n            SELECT REPLACE(referer, '://www.', '://') AS referer,\n                   COUNT(*) AS count\n            FROM analytics\n            WHERE referer != '' AND status BETWEEN 200 AND 299 $whereTimeClause\n            GROUP BY REPLACE(referer, '://www.', '://')\n            ORDER BY count DESC\n            LIMIT 10\n        ");

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $topReferers[] = $row;
        }

        // Remove the most recent (potentially incomplete) period from each dataset
        if (count($dailyVisits) > 0) array_pop($dailyVisits);
        if (count($weeklyVisits) > 0) array_pop($weeklyVisits);
        if (count($monthlyVisits) > 0) array_pop($monthlyVisits);

        // Encode for JavaScript
        $dailyVisitsJSON = json_encode($dailyVisits);
        $weeklyVisitsJSON = json_encode($weeklyVisits);
        $monthlyVisitsJSON = json_encode($monthlyVisits);
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
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light" id="meta-color-scheme" />
    <title>Banalytiq Web Analytics</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@1.0.4/css/bulma.min.css">
    <style>
        .map-container {
            height: 500px;
            border-radius: 5px;
            overflow: hidden;
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
            overflow-wrap: anywhere; /* Wrap long words/URLs */
            word-break: break-word;  /* Additional wrapping support */
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
        /* Time filter buttons styling */
        .time-filter {
            display: flex;
            justify-content: center;
            margin-bottom: 20px;
        }
        .time-filter-buttons {
            display: flex;
            border-radius: 5px;
            overflow: hidden;
            box-shadow: 0 0 5px rgba(0,0,0,0.1);
        }
        .time-button {
            padding: 8px 16px;
            background-color: #f5f5f5;
            border: none;
            border-right: 1px solid #ddd;
            cursor: pointer;
            font-weight: 500;
            transition: background-color 0.2s;
        }
        .time-button:last-child {
            border-right: none;
        }
        .time-button.active {
            background-color: #3b82f6;
            color: white;
        }
        .time-button:hover:not(.active) {
            background-color: #e0e0e0;
        }
    </style>
</head>
<body>
    <div class="container my-5">
        <div class="content has-text-centered" style="position: relative;">
            <h1>BANALYTIQ</h1>
            <h3>Database file: <?php echo htmlspecialchars(basename($db_path)); ?></h3>
            <button id="theme-toggle" class="button is-small" style="position: absolute; top: 0; right: 0;">
                🌙
            </button>
        </div>

        <!-- Chart section: View toggle buttons and canvas -->
        <div class="buttons has-addons is-centered mb-2" id="chart-view-toggle">
            <button class="button is-small view-btn is-primary" data-view="daily">Daily</button>
            <button class="button is-small view-btn" data-view="weekly">Weekly</button>
            <button class="button is-small view-btn" data-view="monthly">Monthly</button>
        </div>
        <div class="box mb-5">
            <canvas id="visits-chart" style="height: 300px;"></canvas>
        </div>

        <div class="buttons has-addons is-centered mb-4" id="time-filter-bar">
            <?php foreach ($timeWindows as $label => $seconds): ?>
                <a href="?time=<?php echo $label; ?><?php echo !empty($custom_name) ? '&db=' . urlencode($custom_name) : ''; ?>#time-filter-bar" 
                   class="button time-btn <?php echo $timeWindow === $label ? 'is-primary' : 'is-light'; ?>" data-time="<?php echo $label; ?>">
                    <?php echo $label; ?>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if ($ipsNeedingGeocodingCount > 0): ?>
        <div class="notification is-warning has-text-centered">
            <p><strong>Warning:</strong> There are <?php echo $ipsNeedingGeocodingCount; ?> IP address(es) that need geocoding. 
            Some visitors may not appear on the map.</p>
            <p>Run <code>php ip2geo.php</code> to add geolocation data to these IPs.</p>
        </div>
        <?php endif; ?>

        <div class="columns is-variable is-3 my-4 has-text-centered">
            <div class="column is-one-third">
                <div class="box stats-box mb-0 has-background-grey-lighter has-text-weight-bold is-flex is-flex-direction-column is-justify-content-center is-align-items-center">
                    <h3>Total Visits</h3>
                    <p><?php echo number_format($totalVisits); ?></p>
                </div>
            </div>
            <div class="column is-one-third">
                <div class="box stats-box mb-0 has-background-grey-lighter has-text-weight-bold is-flex is-flex-direction-column is-justify-content-center is-align-items-center">
                    <h3>Unique Visitors</h3>
                    <p><?php echo number_format($uniqueVisitors); ?></p>
                </div>
            </div>
            <div class="column is-one-third">
                <div class="box stats-box mb-0 has-background-grey-lighter has-text-weight-bold is-flex is-flex-direction-column is-justify-content-center is-align-items-center">
                    <h3>Countries</h3>
                    <p><?php echo number_format($countriesCount); ?></p>
                </div>
            </div>
        </div>
        
        <div class="map-container box" id="visitor-map"></div>

        <div class="columns is-variable is-3 data-tables">
            <div class="column">
                <h2>Top Visited Pages</h2>
                <table class="table is-striped is-fullwidth is-bordered is-hoverable">
                    <thead>
                        <tr>
                            <th>Page</th>
                            <th class="has-text-right">Total Visits</th>
                            <th class="has-text-right">Unique Visitors</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topPages as $page): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($page['url']); ?></td>
                            <td class="has-text-right"><?php echo number_format($page['total_visits']); ?></td>
                            <td class="has-text-right"><?php echo number_format($page['unique_visits']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="columns is-variable is-3 data-tables mt-5">
            <div class="column">
                <h2>Top Referrers</h2>
                <table class="table is-striped is-fullwidth is-bordered is-hoverable">
                    <thead>
                        <tr>
                            <th>Referrer</th>
                            <th class="has-text-right">Visits</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topReferers as $ref): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($ref['referer']); ?></td>
                            <td class="has-text-right"><?php echo number_format($ref['count']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="columns is-variable is-3 data-tables mt-5">
            <div class="column">
                <h2>Top Countries</h2>
                <table class="table is-striped is-fullwidth is-bordered is-hoverable">
                    <thead>
                        <tr>
                            <th>Country</th>
                            <th class="has-text-right">Visits</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topCountries as $country): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($country['country']); ?></td>
                            <td class="has-text-right"><?php echo number_format($country['count']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="content has-text-centered">
        <p style="margin: 0; padding: 0;"><a href="https://x.com/emrahdx">Emrah Diril</a> &copy; 2025</p>
        <p style="margin: 0; padding: 0;">Created with ✨ & ❤️</p>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
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

            // ---------------- Chart ------------------
            const dailyData = <?php echo $dailyVisitsJSON; ?>;
            const weeklyData = <?php echo $weeklyVisitsJSON; ?>;
            const monthlyData = <?php echo $monthlyVisitsJSON; ?>;

            function toDataset(arr, label, color){
                return {
                    label: label,
                    data: arr.map(r=>({x: r.period, y: r.cnt})),
                    borderColor: color,
                    backgroundColor: color,
                    tension:0.2
                };
            }

            const chartCtx = document.getElementById('visits-chart').getContext('2d');

            const dailyDS = toDataset(dailyData,'daily','#3b82f6');
            const weeklyDS = toDataset(weeklyData,'weekly','#10b981');
            const monthlyDS = toDataset(monthlyData,'monthly','#f59e0b');

            const visitsChart = new Chart(chartCtx, {
                type: 'bar',
                data: {
                    datasets: [dailyDS] // start with daily only
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: {
                            type: 'category',
                            title:{display:true,text:'Period'}
                        },
                        y: {
                            beginAtZero:true,
                            title:{display:true,text:'Visits'}
                        }
                    },
                    plugins:{
                        tooltip:{mode:'index',intersect:false},
                        legend:{display:false}
                    }
                }
            });

            // Toggle chart views -------------------
            function setChartView(view){
                let selectedDS;
                switch(view){
                    case 'weekly': selectedDS = weeklyDS; break;
                    case 'monthly': selectedDS = monthlyDS; break;
                    default: selectedDS = dailyDS;
                }

                visitsChart.data.datasets = [selectedDS];

                // Adjust x-axis tick formatter
                const formatters = {
                    daily: p => p.slice(5), // MM-DD
                    weekly: p => 'W' + p.split('-')[1],
                    monthly: p => p.split('-')[1] // MM
                };

                visitsChart.options.scales.x.ticks = {
                    callback: (val, idx) => {
                        const lbl = selectedDS.data[idx]?.x || '';
                        return formatters[view] ? formatters[view](lbl) : lbl;
                    }
                };
                visitsChart.update();
                document.querySelectorAll('.view-btn').forEach(btn=>{
                    if(btn.dataset.view===view){btn.classList.add('is-primary');btn.classList.remove('is-light');}
                    else {btn.classList.remove('is-primary');btn.classList.add('is-light');}
                });
                // persist selection
                localStorage.setItem('banalytiq-chart-view', view);
            }

            // initial chart view from storage
            const storedChartView = localStorage.getItem('banalytiq-chart-view') || 'daily';
            setChartView(storedChartView);

            document.getElementById('chart-view-toggle').addEventListener('click', (e)=>{
                const btn = e.target.closest('.view-btn');
                if(btn){
                    setChartView(btn.dataset.view);
                }
            });
        });

        document.addEventListener('DOMContentLoaded', function () {
            const toggleBtn = document.getElementById('theme-toggle');
            const root = document.documentElement;
            const metaColorScheme = document.getElementById('meta-color-scheme');

            // Apply stored preference
            const storedTheme = localStorage.getItem('banalytiq-theme');
            if (storedTheme) {
                root.setAttribute('data-theme', storedTheme);
                metaColorScheme.setAttribute('content', storedTheme);
                toggleBtn.textContent = storedTheme === 'dark' ? '☀️' : '🌙';
            }

            function applyThemeClasses(theme){
                // time buttons
                document.querySelectorAll('.time-btn').forEach(btn => {
                    if(btn.classList.contains('is-primary')) return; // active button keeps primary
                    if(theme==='dark'){
                        btn.classList.remove('is-light');
                        btn.classList.add('is-dark');
                    } else {
                        btn.classList.remove('is-dark');
                        btn.classList.add('is-light');
                    }
                });
                // stats boxes
                document.querySelectorAll('.stats-box').forEach(box => {
                    if(theme==='dark'){
                        box.classList.remove('has-background-grey-lighter','has-text-weight-bold');
                        box.classList.add('has-background-dark','has-text-light');
                    } else {
                        box.classList.remove('has-background-dark','has-text-light');
                        box.classList.add('has-background-grey-lighter','has-text-weight-bold');
                    }
                });
            }

            const setTheme = (next)=>{
                root.setAttribute('data-theme', next);
                metaColorScheme.setAttribute('content', next);
                localStorage.setItem('banalytiq-theme', next);
                toggleBtn.textContent = next === 'dark' ? '☀️' : '🌙';
                applyThemeClasses(next);
            };

            // initial
            applyThemeClasses(root.getAttribute('data-theme')||'light');

            toggleBtn.addEventListener('click', () => {
                const current = root.getAttribute('data-theme') || 'light';
                const next = current === 'light' ? 'dark' : 'light';
                setTheme(next);
            });
        });
    </script>
</body>
</html>
