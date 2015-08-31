# CubeSql Client

PHP client for [CubeSQL](http://www.sqlabs.com/cubesql.php). Based on the original PHP client bundled in the download.

## Usage example

See `examples/basic.php` for a complete example. Therefore you have to copy `examples/config_example.php` to `examples/config.php` and fill in your database settings.

```php
<?php

require (__DIR__ . '/vendor/autoload.php');

$db = new \CubeSql\CubeSql(CUBESQL_HOST, CUBESQL_PORT, CUBESQL_USERNAME, CUBESQL_PASSWORD);
if ($db->isConnected()) {
	$dbInfo = $db->select("SHOW INFO;");
	var_dump($dbInfo);
} else {
	die(sprintf('Could not connect to host %s!' . "\n", CUBESQL_HOST));
}

if ($db->useDatabase(CUBESQL_DATABASE)) {
	echo('Connected to database');
	var_dump($db->select("SELECT * FROM articles"));
} else {
	die(sprintf('Could not connect to database %s!' . "\n", CUBESQL_DATABASE));
}

## Changelog

### 1.1.0

* Added monolog for logging
* Added basic example
* Added getter/setter for timeout
* Boyscouting
