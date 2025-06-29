<?php
// This script visualizes website visitor data on a map using Leaflet.js and SQLite3.
// It will only work on localhost or 127.0.0.1.

require_once __DIR__ . '/defines.php';

// Load configuration
$config = [];
$configFile = __DIR__ . '/config.yaml';
if (file_exists($configFile)) {
    if (function_exists('yaml_parse_file')) {
        $config = yaml_parse_file($configFile);
    } else {
        // Fallback: simple YAML parsing for our basic config
        $lines = file($configFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if (strpos($line, '#') === 0) continue; // Skip comments
            if (strpos($line, ':') !== false) {
                list($key, $value) = explode(':', $line, 2);
                $config[trim($key)] = trim($value, ' "\'');
            }
        }
    }
}

if (!isset($config['domain'])) {
    throw new Exception('Domain not found in config.yaml file. Please set the domain configuration.');
}
$domain = $config['domain'];

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
    
    // Get the oldest timestamp to determine which time filters to enable
    $oldestTimestamp = $db->querySingle("SELECT MIN(dt) FROM analytics");
    $dataAgeInSeconds = $oldestTimestamp ? (time() - $oldestTimestamp) : 0;
    
    // Determine which time windows should be disabled (exclude 'ALL' - it should always be enabled)
    $disabledTimeWindows = [];
    foreach ($timeWindows as $label => $seconds) {
        if ($seconds > 0 && $dataAgeInSeconds < $seconds && $label !== 'ALL') {
            $disabledTimeWindows[] = $label;
        }
    }
    
    // If current time window is disabled, fall back to the largest available window
    if (in_array($timeWindow, $disabledTimeWindows)) {
        // Find the largest available time window
        $availableWindows = array_diff(array_keys($timeWindows), $disabledTimeWindows);
        if (!empty($availableWindows)) {
            // Get the window with the largest time span
            $maxSeconds = 0;
            $fallbackWindow = '1D';
            foreach ($availableWindows as $window) {
                if ($timeWindows[$window] > $maxSeconds) {
                    $maxSeconds = $timeWindows[$window];
                    $fallbackWindow = $window;
                }
            }
            $timeWindow = $fallbackWindow;
        }
    }
    
    $timeFilter = $timeWindows[$timeWindow];
    
    // Function to convert seconds to human readable time
    function secondsToHumanReadable($seconds) {
        if ($seconds < 60) {
            return $seconds . ' second' . ($seconds != 1 ? 's' : '') . ' ago';
        } elseif ($seconds < 3600) {
            $minutes = floor($seconds / 60);
            return $minutes . ' minute' . ($minutes != 1 ? 's' : '') . ' ago';
        } elseif ($seconds < 86400) {
            $hours = floor($seconds / 3600);
            return $hours . ' hour' . ($hours != 1 ? 's' : '') . ' ago';
        } elseif ($seconds < 604800) {
            $days = floor($seconds / 86400);
            return $days . ' day' . ($days != 1 ? 's' : '') . ' ago';
        } elseif ($seconds < 2629746) { // ~30.44 days (average month)
            $weeks = floor($seconds / 604800);
            return $weeks . ' week' . ($weeks != 1 ? 's' : '') . ' ago';
        } elseif ($seconds < 31556952) { // ~365.25 days (average year)
            $months = floor($seconds / 2629746);
            return $months . ' month' . ($months != 1 ? 's' : '') . ' ago';
        } else {
            $years = floor($seconds / 31556952);
            return $years . ' year' . ($years != 1 ? 's' : '') . ' ago';
        }
    }
    
    // Function to detect if a user agent is a bot
    function isBot($userAgent) {
        if (empty($userAgent)) return false;
        
        $userAgent = strtolower($userAgent);
        
        // Common bot patterns
        $botPatterns = [
            'bot', 'crawler', 'spider', 'scraper',
            'googlebot', 'bingbot', 'yandexbot', 'baiduspider',
            'facebookexternalhit', 'twitterbot', 'linkedinbot', 'meta-externalagent',
            'ahrefsbot', 'semrushbot', 'mj12bot', 'majesticbot', 'dotbot',
            'uptimerobot', 'pingdom', 'statuscake', 'monitor',
            'curl', 'wget', 'python-requests', 'got',
            'shields.io', 'marginalia', 'archive.org',
            'slurp', 'duckduckbot', 'applebot', 'teoma',
            '360spider', 'sogou', 'exabot', 'facebot'
        ];
        
        foreach ($botPatterns as $pattern) {
            if (strpos($userAgent, $pattern) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
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

        // Get all records to separate bots from humans
        $allRecords = [];
        $result = $db->query("SELECT ua, ip, country FROM analytics WHERE status BETWEEN 200 AND 299 $whereTimeClause");
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $allRecords[] = $row;
        }
        
        // Separate human and bot traffic
        $humanRecords = [];
        $botRecords = [];
        foreach ($allRecords as $record) {
            if (isBot($record['ua'])) {
                $botRecords[] = $record;
            } else {
                $humanRecords[] = $record;
            }
        }
        
        // Calculate human visitor stats
        $totalVisits = count($humanRecords);
        $uniqueVisitors = count(array_unique(array_column($humanRecords, 'ip')));
        $countriesCount = count(array_unique(array_filter(array_column($humanRecords, 'country'))));
        
        // Calculate bot visitor stats
        $totalBotVisits = count($botRecords);
        $uniqueBotVisitors = count(array_unique(array_column($botRecords, 'ip')));
        $botCountriesCount = count(array_unique(array_filter(array_column($botRecords, 'country'))));

        // Format data for JavaScript
        $mapDataJSON = json_encode($mapData);

        // Get top countries with time filter - separate human and bot
        $topCountries = [];
        $topBotCountries = [];
        $result = $db->query("
            SELECT country, ua, COUNT(*) as count 
            FROM analytics 
            WHERE country IS NOT NULL AND status BETWEEN 200 AND 299 $whereTimeClause
            GROUP BY country, ua
            ORDER BY count DESC
        ");

        $countryData = [];
        $botCountryData = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $country = $row['country'];
            $count = $row['count'];
            
            if (isBot($row['ua'])) {
                $botCountryData[$country] = ($botCountryData[$country] ?? 0) + $count;
            } else {
                $countryData[$country] = ($countryData[$country] ?? 0) + $count;
            }
        }
        
        // Sort and limit human countries
        arsort($countryData);
        foreach (array_slice($countryData, 0, 10, true) as $country => $count) {
            $topCountries[] = ['country' => $country, 'count' => $count];
        }
        
        // Sort and limit bot countries
        arsort($botCountryData);
        foreach (array_slice($botCountryData, 0, 10, true) as $country => $count) {
            $topBotCountries[] = ['country' => $country, 'count' => $count];
        }
        // Get top visited pages with time filter - filter out bots
        $topPages = [];
        $result = $db->query("
            SELECT REPLACE(url, '://www.', '://') AS url, ua, ip
            FROM analytics 
            WHERE url IS NOT NULL AND status BETWEEN 200 AND 299 AND REPLACE(url, '://www.', '://') NOT LIKE '%/' $whereTimeClause
        ");

        $pageData = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if (!isBot($row['ua'])) { // Filter out bots
                $url = $row['url'];
                $ip = $row['ip'];
                
                if (!isset($pageData[$url])) {
                    $pageData[$url] = ['total_visits' => 0, 'unique_ips' => []];
                }
                $pageData[$url]['total_visits']++;
                $pageData[$url]['unique_ips'][$ip] = true;
            }
        }
        
        // Calculate unique visits and sort
        foreach ($pageData as $url => $data) {
            $topPages[] = [
                'url' => $url,
                'total_visits' => $data['total_visits'],
                'unique_visits' => count($data['unique_ips'])
            ];
        }
        
        // Sort by total visits and limit
        usort($topPages, function($a, $b) { return $b['total_visits'] - $a['total_visits']; });
        $topPages = array_slice($topPages, 0, 10);

        // Build data for charts ----------------------------------- (filter out bots)
        // DAILY (within selected time window)
        $dailyVisits = [];
        $result = $db->query("SELECT strftime('%Y-%m-%d', dt, 'unixepoch') AS period, ua FROM analytics WHERE status BETWEEN 200 AND 299 $whereTimeClause ORDER BY period ASC");
        $dailyData = [];
        while($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if (!isBot($row['ua'])) {
                $period = $row['period'];
                $dailyData[$period] = ($dailyData[$period] ?? 0) + 1;
            }
        }
        foreach ($dailyData as $period => $cnt) {
            $dailyVisits[] = ['period' => $period, 'cnt' => $cnt];
        }

        // WEEKLY (ISO week) within selected time window - get the Monday of each week
        $weeklyVisits = [];
        $result = $db->query("
            SELECT 
                strftime('%Y-%m-%d', dt, 'unixepoch', 'weekday 0', '-6 days') AS period,
                ua
            FROM analytics 
            WHERE status BETWEEN 200 AND 299 $whereTimeClause 
            ORDER BY period ASC
        ");
        $weeklyData = [];
        while($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if (!isBot($row['ua'])) {
                $period = $row['period'];
                $weeklyData[$period] = ($weeklyData[$period] ?? 0) + 1;
            }
        }
        foreach ($weeklyData as $period => $cnt) {
            $weeklyVisits[] = ['period' => $period, 'cnt' => $cnt];
        }

        // MONTHLY within selected time window
        $monthlyVisits = [];
        $result = $db->query("SELECT strftime('%Y-%m', dt, 'unixepoch') AS period, ua FROM analytics WHERE status BETWEEN 200 AND 299 $whereTimeClause ORDER BY period ASC");
        $monthlyData = [];
        while($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if (!isBot($row['ua'])) {
                $period = $row['period'];
                $monthlyData[$period] = ($monthlyData[$period] ?? 0) + 1;
            }
        }
        foreach ($monthlyData as $period => $cnt) {
            $monthlyVisits[] = ['period' => $period, 'cnt' => $cnt];
        }

        // Get top referrers with time filter (exclude empty strings) - filter out bots
        $httpsPattern = "https://{$domain}/%";
        $httpPattern = "http://{$domain}/%";
        $result = $db->query("
            SELECT 
                CASE 
                    WHEN REPLACE(referer, '://www.', '://') LIKE '{$httpsPattern}' 
                        OR REPLACE(referer, '://www.', '://') LIKE '{$httpPattern}' 
                    THEN '[Self Referral]'
                    ELSE REPLACE(referer, '://www.', '://')
                END AS referer,
                ua
            FROM analytics
            WHERE referer != '' AND status BETWEEN 200 AND 299 $whereTimeClause
        ");

        $refererData = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if (!isBot($row['ua'])) { // Filter out bots
                $referer = $row['referer'];
                $refererData[$referer] = ($refererData[$referer] ?? 0) + 1;
            }
        }
        
        // Sort and limit referrers
        arsort($refererData);
        foreach (array_slice($refererData, 0, 10, true) as $referer => $count) {
            $topReferers[] = ['referer' => $referer, 'count' => $count];
        }

        // Get recently visited pages with time filter - filter out bots
        $recentPages = [];
        $result = $db->query("
            SELECT REPLACE(url, '://www.', '://') AS url, dt, ua, ip
            FROM analytics 
            WHERE url IS NOT NULL AND status BETWEEN 200 AND 299 AND REPLACE(url, '://www.', '://') NOT LIKE '%/' $whereTimeClause
            ORDER BY dt DESC
        ");

        $recentPageData = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if (!isBot($row['ua'])) { // Filter out bots
                $url = $row['url'];
                $dt = $row['dt'];
                $ip = $row['ip'];
                
                if (!isset($recentPageData[$url])) {
                    $recentPageData[$url] = [
                        'last_visit' => $dt,
                        'total_visits' => 0,
                        'unique_ips' => []
                    ];
                }
                
                $recentPageData[$url]['total_visits']++;
                $recentPageData[$url]['unique_ips'][$ip] = true;
                if ($dt > $recentPageData[$url]['last_visit']) {
                    $recentPageData[$url]['last_visit'] = $dt;
                }
            }
        }
        
        // Convert to array and calculate unique visits
        foreach ($recentPageData as $url => $data) {
            $recentPages[] = [
                'url' => $url,
                'last_visit' => $data['last_visit'],
                'total_visits' => $data['total_visits'],
                'unique_visits' => count($data['unique_ips'])
            ];
        }
        
        // Sort by last visit and limit
        usort($recentPages, function($a, $b) { return $b['last_visit'] - $a['last_visit']; });
        $recentPages = array_slice($recentPages, 0, 10);

        // Get recent referrers with time filter (exclude empty strings) - filter out bots
        $recentReferers = [];
        $result = $db->query("
            SELECT 
                CASE 
                    WHEN REPLACE(referer, '://www.', '://') LIKE '{$httpsPattern}' 
                        OR REPLACE(referer, '://www.', '://') LIKE '{$httpPattern}' 
                    THEN '[Self Referral]'
                    ELSE REPLACE(referer, '://www.', '://')
                END AS referer,
                ua,
                dt
            FROM analytics
            WHERE referer != '' AND status BETWEEN 200 AND 299 $whereTimeClause
            ORDER BY dt DESC
        ");

        $recentRefererData = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if (!isBot($row['ua'])) { // Filter out bots
                $referer = $row['referer'];
                $dt = $row['dt'];
                
                if (!isset($recentRefererData[$referer])) {
                    $recentRefererData[$referer] = [
                        'referer' => $referer,
                        'last_referral' => $dt,
                        'count' => 0
                    ];
                }
                
                $recentRefererData[$referer]['count']++;
                if ($dt > $recentRefererData[$referer]['last_referral']) {
                    $recentRefererData[$referer]['last_referral'] = $dt;
                }
            }
        }
        
        // Sort by last referral and limit
        uasort($recentRefererData, function($a, $b) {
            return $b['last_referral'] - $a['last_referral'];
        });
        $recentReferers = array_slice($recentRefererData, 0, 10);

        // Keep the most recent period in all datasets. It may be incomplete, and
        // will be rendered with a striped fill on the chart to indicate that it
        // is still "in progress".

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
        /* Disabled time buttons */
        .button.is-disabled {
            opacity: 0.4;
            cursor: not-allowed;
            pointer-events: none;
        }
        /* Table column widths for better readability */
        /* First column (Page/Referrer/Country) - flexible with wrapping */
        .data-tables table th:nth-child(1),
        .data-tables table td:nth-child(1) {
            width: auto;
            min-width: 200px;
            word-break: break-word;
            overflow-wrap: anywhere;
        }
        
        /* Numeric columns - fixed width, no wrapping */
        .data-tables table th:nth-child(2),
        .data-tables table td:nth-child(2) {
            width: 120px;
            min-width: 120px;
            white-space: nowrap;
        }
        
        .data-tables table th:nth-child(3),
        .data-tables table td:nth-child(3) {
            width: 120px;
            min-width: 120px;
            white-space: nowrap;
        }
        
        /* For tables with only 2 columns (Top Referrers, Top Countries) */
        .data-tables table th:nth-child(2):last-child,
        .data-tables table td:nth-child(2):last-child {
            width: 100px;
            min-width: 100px;
        }
        /* Table titles styling */
        .data-tables h2 {
            font-size: 1.5rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
            color: #333;
        }
        /* Table titles styling for dark mode */
        [data-theme="dark"] .data-tables h2 {
            color: #e0e0e0;
        }
        
        /* Time filter buttons dark mode styling */
        [data-theme="dark"] .time-btn.is-light {
            background-color: #4a5568;
            color: #e0e0e0;
            border-color: #4a5568;
        }
        
        [data-theme="dark"] .time-btn.is-dark {
            background-color: #2d3748;
            color: #a0aec0;
            border-color: #2d3748;
        }
        
        [data-theme="dark"] .time-btn.is-primary {
            background-color: #10b981;
            color: white;
            border-color: #10b981;
        }
        
        /* Disabled buttons in dark mode - much darker */
        [data-theme="dark"] .time-btn.is-disabled {
            background-color: #1a202c !important;
            color: #4a5568 !important;
            border-color: #1a202c !important;
        }
        
        /* Hover effects for enabled buttons in dark mode */
        [data-theme="dark"] .time-btn.is-light:hover:not(.is-disabled) {
            background-color: #5a6578;
        }
        
        [data-theme="dark"] .time-btn.is-dark:hover:not(.is-disabled) {
            background-color: #3a4556;
        }
        
        [data-theme="dark"] .time-btn.is-primary:hover {
            background-color: #0ea572;
        }
        
        /* Hover effects for enabled buttons in light mode */
        .time-btn.is-light:hover:not(.is-disabled) {
            background-color: #d0d7de;
        }
        
        .time-btn.is-dark:hover:not(.is-disabled) {
            background-color: #2c3e50;
        }
        
        .time-btn.is-primary:hover {
            background-color: #0ea572;
        }
        
        /* Chart view buttons dark mode styling */
        [data-theme="dark"] .view-btn.is-light {
            background-color: #4a5568;
            color: #e0e0e0;
            border-color: #4a5568;
        }
        
        [data-theme="dark"] .view-btn.is-primary {
            background-color: #10b981;
            color: white;
            border-color: #10b981;
        }
        
        /* Chart view buttons hover effects for dark mode */
        [data-theme="dark"] .view-btn.is-light:hover {
            background-color: #5a6578;
        }
        
        [data-theme="dark"] .view-btn.is-primary:hover {
            background-color: #0ea572;
        }
        
        /* Chart view buttons hover effects for light mode */
        .view-btn.is-light:hover {
            background-color: #d0d7de;
        }
        
        .view-btn.is-primary:hover {
            background-color: #0ea572;
        }
    </style>
</head>
<body>
    <div class="container my-5">
        <div class="content" style="display: grid; grid-template-columns: 1fr auto 1fr; align-items: baseline;">
            <h1 class="title mb-0" style="font-variant: small-caps;">Banalytiq</h1>
            <h1 class="title mb-0" style="justify-self: center;"><?php echo htmlspecialchars($domain); ?></h1>
            <div style="justify-self: end; align-self: end;">
                <button id="theme-toggle" class="button is-small" style="width: 2rem; height: 2rem; min-width: 2rem;">
                    🌙
                </button>
            </div>
        </div>

        <div class="buttons has-addons is-centered mb-3" id="time-filter-bar">
            <?php foreach ($timeWindows as $label => $seconds): 
                $isDisabled = in_array($label, $disabledTimeWindows);
                $isActive = $timeWindow === $label;
            ?>
                <?php if ($isDisabled): ?>
                    <span class="button time-btn is-light is-disabled" data-time="<?php echo $label; ?>" title="Not enough data for this time period">
                        <?php echo $label; ?>
                    </span>
                <?php else: ?>
                    <a href="?time=<?php echo $label; ?><?php echo !empty($custom_name) ? '&db=' . urlencode($custom_name) : ''; ?>#time-filter-bar" 
                       class="button time-btn <?php echo $isActive ? 'is-primary' : 'is-light'; ?>" data-time="<?php echo $label; ?>">
                        <?php echo $label; ?>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <?php if ($oldestTimestamp): ?>
        <div class="has-text-centered mb-4">
            <p class="has-text-grey">
                <small>Oldest timestamp: <span title="<?php echo date('Y-m-d', $oldestTimestamp); ?>" style="text-decoration: underline dotted; cursor: help;"><?php echo secondsToHumanReadable($dataAgeInSeconds); ?></span></small>
            </p>
        </div>
        <?php endif; ?>

        <div class="box">
            <div class="mb-4">
                <canvas id="visits-chart" style="height: 300px;"></canvas>
            </div>
            <div class="buttons has-addons is-centered" id="chart-view-toggle">
                <button class="button is-small view-btn is-primary" data-view="daily">Daily</button>
                <button class="button is-small view-btn" data-view="weekly">Weekly</button>
                <button class="button is-small view-btn" data-view="monthly">Monthly</button>
            </div>
        </div>

        <div class="columns is-variable is-3 has-text-centered">
            <div class="column is-three-fifths">
                <div class="columns is-variable is-2">
                    <div class="column">
                        <div class="box stats-box mb-0 has-background-grey-lighter has-text-weight-bold is-flex is-flex-direction-column is-justify-content-center is-align-items-center">
                            <h3>👥 Total Visits</h3>
                            <p><?php echo number_format($totalVisits); ?></p>
                        </div>
                    </div>
                    <div class="column">
                        <div class="box stats-box mb-0 has-background-grey-lighter has-text-weight-bold is-flex is-flex-direction-column is-justify-content-center is-align-items-center">
                            <h3>👤 Unique Visits</h3>
                            <p><?php echo number_format($uniqueVisitors); ?></p>
                        </div>
                    </div>
                    <div class="column">
                        <div class="box stats-box mb-0 has-background-grey-lighter has-text-weight-bold is-flex is-flex-direction-column is-justify-content-center is-align-items-center">
                            <h3>🌍 Countries</h3>
                            <p><?php echo number_format($countriesCount); ?></p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="column is-two-fifths">
                <div class="columns is-variable is-2">
                    <div class="column">
                        <div class="box stats-box mb-0 has-background-grey-lighter has-text-weight-bold is-flex is-flex-direction-column is-justify-content-center is-align-items-center">
                            <h3>🤖 Bot Visits</h3>
                            <p><?php echo number_format($totalBotVisits); ?></p>
                        </div>
                    </div>
                    <div class="column">
                        <div class="box stats-box mb-0 has-background-grey-lighter has-text-weight-bold is-flex is-flex-direction-column is-justify-content-center is-align-items-center">
                            <h3>🤖 Bot Countries</h3>
                            <p><?php echo number_format($botCountriesCount); ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="columns is-variable is-3 data-tables mt-3">
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
        <div class="columns is-variable is-3 data-tables mt-3">
            <div class="column">
                <h2>Recently Visited Pages</h2>
                <table class="table is-striped is-fullwidth is-bordered is-hoverable">
                    <thead>
                        <tr>
                            <th>Page</th>
                            <th class="has-text-right">Last Visit</th>
                            <th class="has-text-right">Total Visits</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentPages as $page): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($page['url']); ?></td>
                            <td class="has-text-right"><?php echo secondsToHumanReadable(time() - $page['last_visit']); ?></td>
                            <td class="has-text-right"><?php echo number_format($page['total_visits']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="column">
                <h2>Recent Referrers</h2>
                <table class="table is-striped is-fullwidth is-bordered is-hoverable">
                    <thead>
                        <tr>
                            <th>Referrer</th>
                            <th class="has-text-right">Last Referral</th>
                            <th class="has-text-right">Total Count</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentReferers as $ref): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($ref['referer']); ?></td>
                            <td class="has-text-right"><?php echo secondsToHumanReadable(time() - $ref['last_referral']); ?></td>
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

        <?php if ($ipsNeedingGeocodingCount > 0): ?>
        <div class="notification is-warning has-text-centered">
            <p><strong>Warning:</strong> There are <?php echo $ipsNeedingGeocodingCount; ?> IP address(es) that need geocoding. 
            Some visitors may not appear on the map.</p>
            <p>Run <code>php ip2geo.php</code> to add geolocation data to these IPs.</p>
        </div>
        <?php endif; ?>
        <div class="map-container box" id="visitor-map"></div>

        <div class="content has-text-centered" style="position: relative;">
            <h3>Database file: <?php echo htmlspecialchars(basename($db_path)); ?></h3>
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
                maxBoundsViscosity: 1.0,
                scrollWheelZoom: false,
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

            // helper to generate diagonal stripe pattern for a given color
            function makeStripePattern(ctx, color){
                const canvas = document.createElement('canvas');
                canvas.width = canvas.height = 8;
                const pctx = canvas.getContext('2d');
                pctx.strokeStyle = color;
                pctx.lineWidth = 2;
                pctx.beginPath();
                pctx.moveTo(0, 8);
                pctx.lineTo(8, 0);
                pctx.stroke();
                return ctx.createPattern(canvas, 'repeat');
            }

            function toDataset(arr, label, color){
                let cachedPattern; // lazy-create per dataset
                return {
                    label: label,
                    data: arr.map(r=>({x: r.period, y: r.cnt})),
                    borderColor: color,
                    backgroundColor: (ctx) => {
                        const idx = ctx.dataIndex;
                        const total = ctx.dataset.data.length;
                        if(idx === total - 1){ // last datapoint -> pattern
                            if(!cachedPattern){
                                cachedPattern = makeStripePattern(ctx.chart.ctx, color);
                            }
                            return cachedPattern;
                        }
                        return color;
                    },
                    tension: 0.2
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
                    weekly: p => p.slice(5), // MM-DD (beginning of week)
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
                    if(btn.classList.contains('is-disabled')) return; // disabled buttons keep their styling
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
