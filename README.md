![Screenshot](screenshot.jpg)

# How to use
## Add it to your git project as a submodule:
```
$ git submodule add https://github.com/ediril/banalytiq.git
```

## Create a blank database
```
$ cd banalytiq
$ php -r "require 'banalytiq.php'; create_db();"
```

## Include banalytiq.php in your application's `index.php`
```php
<?php
    require_once __DIR__ . '/banalytiq/banalytiq.php';
    record_visit();
?>
```

## Push `/banalytiq` folder and the modified `index.php` to your webserver
```
TODO
```

## Download the database file and fill in city, country, longitude, latitude for the collected IP addresses 
```
$ cd banalytiq
$ php -r "require 'geo.php'; download(); ip2geo();"
```

## Visualize your web traffic
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