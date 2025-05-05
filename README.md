# banalytics.php 
Include this on pages you want to track and call `record_visit()`

# ip2geo.php
Fills in city, country, longitude, latitude using IP address
```
php _code_/ip2geo.php [/path/to/analytics.db]
```

# index.php
Visualizes traffic when run on localhost
```
cd banalytics
php -S localhost:8000
```

# Prepare geolite2 database
Download and extract GeoLite2-City-CSV_{YYYYMMDD}.zip from [MaxMind](https://dev.maxmind.com)
```
sqlite3 geolite2_{YYYYMMDD}.db
.mode csv
.import GeoLite2-City-Blocks-IPv4.csv blocks
.import GeoLite2-City-Locations-en.csv locations
```