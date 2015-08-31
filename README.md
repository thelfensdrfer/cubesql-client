# CubeSql Client

PHP client for [CubeSQL](http://www.sqlabs.com/cubesql.php). Based on the original PHP client bundled in the download.

## Usage example

```php
<?php

require (__DIR__ . '/vendor/autoload.php');
require (__DIR__ . '/config/dev.php');

$db = new \CubeSql\CubeSql(CUBESQL_HOST, CUBESQL_PORT, CUBESQL_USERNAME, CUBESQL_PASSWORD);
if ($db->isConnected()) {
	$dbInfo = $db->select("SHOW INFO;");
	var_dump($dbInfo);
} else {
	die(sprintf('Could not connect to host %s!', CUBESQL_HOST));
}

if ($db->useDatabase(CUBESQL_DATABASE)) {
	echo('Connected to database');
	var_dump($db->select("SELECT * FROM articles"));
} else {
	die(sprintf('Could not connect to database %s!', CUBESQL_DATABASE));
}
