<?php namespace CubeSql;

class CubeSql
{
	public $errorCode;
	public $errorMessage;
	public $socketTimeout;

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

	public function __construct($host, $port, $username, $password, $database = null, $timeout = 10)
	{
		$this->_resetError();
		$this->socketTimeout = $timeout;

		// create socket
		$this->_socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		if ($this->_socket === false) {
			$this->_setSocketError();
			return;
		}

		// connect socket
		$ip = gethostbyname($host);
		$result = @socket_connect($this->_socket, $ip, $port);
		if ($result === false) {
			$this->_setSocketError();
			return;
		}

		// generate randpool
		$randpool = '';
		while (strlen($randpool) <= 10) $randpool .= mt_rand();

		// compute sha1 (randpool;username) in hex mode
		// on the server password is stored as BASE64(SHA1(SHA1(password)))
		$sha1_username = sha1($randpool.$username);
		$sha1_password = sha1($randpool.base64_encode(sha1(sha1($password, true), true)));

		// create and send json request
		$request = array ('command'=>'CONNECT','username'=>"$sha1_username",'password'=>"$sha1_password",'randpool'=>"$randpool");
		$json_request = json_encode($request);
		$data = $this->_sendRequest($json_request);

		// save results
		$this->errorCode = $data['errorCode'];
		$this->errorMessage = ( array_key_exists( 'errorMsg', $data ) ? $data[ 'errorMsg' ] : NULL );

		$this->_isConnected = !$this->isError();
	}

	/**
	 * Connect to a database
	 * @param string $database
	 *
	 * @return boolean
	 */
	public function useDatabase($database)
	{
		if (!$this->_isConnected)
			return false;

		$rc = $this->execute(sprintf("USE DATABASE %s;", $database));
		if ($rc === false)
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
	 * Execute an sql statement
	 *
	 * @param string $sql
	 *
	 * @return
	 */
	public function execute($sql)
	{
		$this->_resetError();
		$request = array ('command'=>'EXECUTE','sql'=>"$sql");
		$json_request = json_encode($request);
		$data = $this->_sendRequest($json_request);

		// save results
		$this->errorCode = $data['errorCode'];
		$this->errorMessage = ( array_key_exists( 'errorMsg', $data ) ? $data[ 'errorMsg' ] : NULL );
	}

	public function select($sql)
	{
		$this->_resetError();
		$request = array ('command'=>'SELECT','sql'=>"$sql");
		$json_request = json_encode($request);
		$data = $this->_sendRequest($json_request);
		if ($data === NULL) return NULL;

		// check if an error occurs
		if (array_key_exists('errorCode', $data)) {
			$this->errorCode = $data['errorCode'];
			$this->errorMessage = $data['errorMsg'];
			return NULL;
		}

		// return associative array
		return $data;
	}

	public function disconnect()
	{
		$this->_resetError();
		$request = array ('command'=>'DISCONNECT');
		$json_request = json_encode($request);
		$data = $this->_sendRequest($json_request);
		socket_close($this->socket);
	}

	public function isError()
	{
		if ($this->errorCode != 0) return true;
		return false;
	}

	private function _resetError()
	{
		$this->errorCode = 0;
		$this->errorMessage = "";
	}

	private function _setJSONError()
	{
		$this->errorCode = json_last_error();
		switch($this->errorCode)
		{
			case JSON_ERROR_DEPTH:
			$this->errorMessage = 'Maximum stack depth exceeded';
			break;

			case JSON_ERROR_CTRL_CHAR:
			$this->errorMessage = 'Unexpected control character found';
			break;

			case JSON_ERROR_SYNTAX:
			$this->errorMessage = 'Syntax error, malformed JSON';
			break;

			case JSON_ERROR_STATE_MISMATCH:
			$this->errorMessage = 'Invalid or malformed JSON';
			break;

			case JSON_ERROR_NONE:
			$this->errorMessage = 'No errors';
			break;
		}
	}

	private function _setSocketError()
	{
		$this->errorCode = socket_last_error();
		$this->errorMessage = socket_strerror($this->errorCode);
	}

	private function _sendRequest($json_request)
	{
		// write request
		$bytes = @socket_write($this->_socket, $json_request);
		if ($bytes === false) {
			$this->_setSocketError();
			return;
		}

		// read reply with a specified timeout
		$is_timeout = false;
		$reply = '';
		$buf = '';
		$start = microtime(true);
		while (1) {
			$bytes = @socket_recv($this->_socket, $buf, 8192, MSG_DONTWAIT);
			if ($bytes === false) {
				$end = microtime(true);
				$wait_time = ($end-$start) * 1000000;
				if ($wait_time >= ($this->socketTimeout * 1000000)) {$is_timeout = true; break;}
				continue;
			}
			$reply .= $buf;

			// since there is no way to check when a JOSN packet is finished
			// the only way is to try to decode it
			//$reply = utf8_encode($reply);
			if (($bytes == 2) && (strcmp($reply,"[]")==0)) return array(); // fix for empty recordset
			$r = json_decode($reply, true);
			if ($r != NULL) break;
		}

		// check for possible errors on exit
		if ($is_timeout == true) $this->_setSocketError();
		else if ($r == NULL) $this->_setJSONError();

		// uncomment these lines to add debug code
		/*
		$json_errors = array(
			JSON_ERROR_NONE => 'No error has occurred',
				JSON_ERROR_DEPTH => 'The maximum stack depth has been exceeded',
			JSON_ERROR_CTRL_CHAR => 'Control character error, possibly incorrectly encoded',
			JSON_ERROR_SYNTAX => 'Syntax error',);
			echo 'JSON Last Error : ', $json_errors[json_last_error()], PHP_EOL, PHP_EOL;
		*/

		return $r;
	}
}
