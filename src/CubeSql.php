<?php namespace CubeSql;

require __DIR__ . '/../vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\NullHandler;

class CubeSql
{
	/**
	 * Request timeout of the socket.
	 *
	 * @var int
	 */
	private $_timeout;

	/**
	 * Socket to the database.
	 *
	 * @var socket resource
	 */
	private $_socket;

	/**
	 * If the connection was established.
	 *
	 * @var boolean
	 */
	private $_isConnected = false;

	/**
	 * Current logging instance
	 *
	 * @var \Monolog\Logger
	 */
	private $_logger;

	/**
	 * Create a new client instance.
	 *
	 * @param string $host
	 * @param int $port
	 * @param string $username
	 * @param string $password Default: ''
	 * @param \Monolog\Logger $logger Default: null (new NullHandler)
	 * @param integer $timeout Default: 10
	 */
	public function __construct($host, $port, $username, $password = '', $logger = null, $timeout = 10)
	{
		// Set settings
		$this->_timeout = $timeout;

		if ($logger === null) {
			$logger = new Logger('default');
			$logger->pushHandler(new NullHandler(Logger::WARNING));
		}
		$this->_logger = $logger;

		// Create socket
		$this->_socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		if ($this->_socket === false)
			throw new \Exception($this->_addLastSocketError());

		// Get ipv4 address for host
		$ip = gethostbyname($host);

		// Connect to host
		$result = @socket_connect($this->_socket, $ip, $port);
		if ($result === false)
			throw new \Exception($this->_addLastSocketError());

		// Generate randpool
		$randpool = '';
		while (strlen($randpool) <= 10)
			$randpool .= mt_rand();

		// Compute sha1 (randpool;username) in hex mode
		// On the server password is stored as BASE64(SHA1(SHA1(password)))
		$hashedUsername = sha1($randpool . $username);
		$hashedPassword = sha1($randpool . base64_encode(sha1(sha1($password, true), true)));

		// Connect to database
		if ($this->_sendRequest(array(
			'command' => 'CONNECT',
			'username' => $hashedUsername,
			'password' => $hashedPassword,
			'randpool' => $randpool
		)) === false) {
			throw new \Exception('Could not connect to database!');
		}


		$this->_isConnected = true;
	}

	/**
	 * Set a new logging instance.
	 *
	 * @param \Monolog\Logger $logger
	 */
	public function setLogger(\Monolog\Logger $logger)
	{
		$this->_logger = $logger;
	}

	/**
	 * Get the current logging instance.
	 *
	 * @return \Monolog\Logger
	 */
	public function logger()
	{
		return $this->_logger;
	}

	/**
	 * Set new request timeout.
	 *
	 * @param int $timeout
	 */
	public function setTimeout($timeout)
	{
		$this->_timeout = $timeout;
	}

	/**
	 * Get the current request timeout.
	 *
	 * @return int
	 */
	public function timeout()
	{
		return $this->_timeout;
	}

	/**
	 * Connect to a database
	 * @param string $database
	 *
	 * @return boolean
	 */
	public function useDatabase($database)
	{
		$data = $this->execute(sprintf("USE DATABASE %s", $database));

		if ($data === false)
			return false;
		else
			return true;
	}

	/**
	 * Check if a connection was established.
	 *
	 * @return boolean
	 */
	public function isConnected()
	{
		return $this->_isConnected;
	}

	/**
	 * Execute an sql statement.
	 *
	 * @param string $sql
	 *
	 * @return
	 */
	public function execute($sql)
	{
		$this->_logger->addDebug(sprintf('Execute SQL: %s', $sql));

		$data = $this->_sendRequest(array(
			'command' => 'EXECUTE',
			'sql' => $sql
		));

		if ($data['errorCode'] == 0)
			return true;

		if (isset($data['errorCode'])) {
			$message = isset($data['errorMsg']) ? $data['errorMsg'] : 'Unknown';
			$this->_logger->addError(sprintf('Database error %d: %s', $data['errorCode'], $message));

			return false;
		}

		return true;
	}

	/**
	 * Executes a select statement and returns the rows
	 *
	 * @param string $sql
	 *
	 * @return array|boolean
	 */
	public function select($sql)
	{
		$this->_logger->addDebug(sprintf('Select SQL: %s', $sql));

		$data = $this->_sendRequest(array(
			'command' => 'SELECT',
			'sql' => $sql
		));

		// check if an error occurs
		if (isset($data['errorCode'])) {
			$message = isset($data['errorMsg']) ? $data['errorMsg'] : 'Unknown';
			$this->_logger->addError(sprintf('Database error (%d: %s) for select statement: %s', $data['errorCode'], $message, $sql));

			return false;
		}

		// return associative array
		return $data;
	}

	/**
	 * Disconnects the current connection.
	 *
	 * @return void
	 */
	public function disconnect()
	{
		$this->_logger->addDebug('Disconnect from current database');

		$data = $this->_sendRequest(array(
			'command' => 'DISCONNECT'
		));

		socket_close($this->_socket);

		$this->_isConnected = false;
	}

	/**
	 * Creates a nwe log message with the last json decoding error and returns it.
	 *
	 * @return string
	 */
	private function _addLastJsonError()
	{
		$code = json_last_error();

		switch($this->code) {
			case JSON_ERROR_DEPTH:
				$message = 'Maximum stack depth exceeded';
				break;
			case JSON_ERROR_CTRL_CHAR:
				$message = 'Unexpected control character found';
				break;
			case JSON_ERROR_SYNTAX:
				$message = 'Syntax error, malformed JSON';
				break;
			case JSON_ERROR_STATE_MISMATCH:
				$message = 'Invalid or malformed JSON';
				break;
		}

		$this->_logger->addError($message);
		return $message;
	}

	/**
	 * Creates a new log message with the last socket error and returns it.
	 *
	 * @return string
	 */
	private function _addLastSocketError()
	{
		$code = socket_last_error();
		$message = sprintf('Socket error %d: %s', $code, socket_strerror($code));

		$this->_logger->addError($message);
		return $message;
	}

	/**
	 * Send request to host.
	 *
	 * @param array $payload
	 *
	 * @return mixed False on failure, otherwise decoded json object/array
	 */
	private function _sendRequest(array $payload)
	{
		$payload = json_encode($payload);

		// Write request
		$bytes = @socket_write($this->_socket, $payload);
		if ($bytes === false) {
			$this->_addLastSocketError();
			return false;
		}

		// Read reply with a specified timeout
		$is_timeout = false;
		$reply = '';
		$buf = '';
		$start = microtime(true);
		while (1) {
			$bytes = @socket_recv($this->_socket, $buf, 8192, MSG_DONTWAIT);

			if ($bytes === false) {
				$end = microtime(true);
				$wait_time = ($end - $start) * 1000000;
				if ($wait_time >= ($this->_timeout * 1000000)) {
					$is_timeout = true;
					break;
				}

				continue;
			}

			$reply .= $buf;

			// Response is an empty result set
			if (($bytes == 2) && (strcmp($reply, "[]") == 0))
				return array();

			// TODO: Since there is no way to check when a JOSN packet is finished
			// the only way is to try to decode it
			$r = json_decode($reply, true);
			if ($r != NULL)
				break;
		}

		// check for possible errors on exit
		if ($is_timeout == true) {
			$this->_addLastSocketError();
			return false;
		} else if ($r == NULL) {
			$this->_addLastJsonError();
			return false;
		}

		return $r;
	}
}
