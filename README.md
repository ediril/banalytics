# How to use
1. Create a blank database
```
> php -r "require 'banalytiq.php'; create_db();"
```

2. Include banalytiq.php 
```php
<?php
    require_once 'banalytiq.php'
    record_visit();
?>
```

3. Download the database file and fill in city, country, longitude, latitude using collected IP addresses
```
> php -r "require 'geo.php'; ip2geo();"
```

4. Visualize web traffic on localhost
```
> cd banalytiq
> php -S localhost:8000
```

# Prepare geolite database
Download and extract GeoLite2-City-CSV_{YYYYMMDD}.zip from [MaxMind](https://dev.maxmind.com)
```
> sqlite3 geolite2_{YYYYMMDD}.db
.mode csv
.import GeoLite2-City-Blocks-IPv4.csv blocks
.import GeoLite2-City-Locations-en.csv locations
```