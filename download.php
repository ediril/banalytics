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
        $timestamp = filemtime($local_file);
        $new_file = $local_file . '.' . $timestamp . '.bak';
        rename($local_file, $new_file);
        echo "Renamed existing file `$local_file` to `$new_file`\n";
    }
    // if $config['remote_path'] doesn't end with a slash, add it
    if ($config['remote_path'][-1] !== '/') {
        $config['remote_path'] .= '/';
    }
    
    $remote_file = $config['remote_path'] . 'banalytiq.db';
        
    // Download file
    if (ftp_get($conn, $local_file, $remote_file, FTP_BINARY)) {
        echo "Successfully downloaded to $local_file\n";        
    } else {
        echo "ERROR:Failed to download\n";
    }
    
    // Close FTP connection
    @ftp_close($conn);
}

// Example usage when script is called directly
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    $config_path = isset($argv[1]) ? $argv[1] : 'ftp_config.yaml';
    download_analytics_files($config_path);
}