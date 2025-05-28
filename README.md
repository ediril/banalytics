![Screenshot](screenshot.jpg)

# How to use
First, add it as a submodule to your project:
```
$ git submodule add https://github.com/ediril/banalytiq.git
```

Then do following in the banalytiq folder:
```
$ cd banalytiq
```

## Create a blank database
```
$ php -r "require 'banalytiq.php'; create_db();"
```

## Include banalytiq.php 
```php
<?php
    require_once __DIR__ . '/banalytiq/banalytiq.php';
    record_visit();
?>
```

## Download the database file and fill in city, country, longitude, latitude for the collected IP addresses 
```
$ php -r "require 'geo.php'; download(); ip2geo();"
```

## Now you can visualize web traffic
```
$ php -S localhost:8000
```

# How to prepare geolite database
Download and extract GeoLite2-City-CSV_{YYYYMMDD}.zip from [MaxMind](https://dev.maxmind.com)
```
> sqlite3 geolite2.db
.mode csv
.import GeoLite2-City-Blocks-IPv4.csv blocks
.import GeoLite2-City-Locations-en.csv locations
```