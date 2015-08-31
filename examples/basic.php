<?php

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/config.php';

// create a log channel
$log = new \Monolog\Logger('cubesql');
$log->pushHandler(new \Monolog\Handler\ErrorLogHandler());

$db = new \CubeSql\CubeSql(CUBESQL_HOST, CUBESQL_PORT, CUBESQL_USERNAME, CUBESQL_PASSWORD, $log);
if ($db->isConnected()) {
	$dbInfo = $db->select("SHOW INFO;");
} else {
	exit(1);
}

if ($db->useDatabase(CUBESQL_DATABASE)) {
	$db->select("SELECT * FROM BENUTZER");
} else {
	exit(1);
}
