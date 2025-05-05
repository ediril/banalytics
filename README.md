# analytics.php 
Include this on pages you want to track

# ip2geo.php
Fill in city, country, longitude, latitude using IP address
```
php _code_/ip2geo.php /path/to/analytics.db
```

# visualize.php
Visualize traffic
```
php -S localhost:8000
php -S localhost:8000 -t /path/to/serve
```

# Prepare geolite2 database
Download and extract GeoLite2-City-CSV_{YYYYMMDD}.zip from [MaxMind](https://dev.maxmind.com)
```
sqlite3 geolite2_{YYYYMMDD}.db
.mode csv
.import GeoLite2-City-Blocks-IPv4.csv blocks
.import GeoLite2-City-Locations-en.csv locations
```