<?PHP

class MySqlSessionHandler implements SessionHandlerInterface
{
	/**
	* database MySQLi connection resource
	* @var resource
	*/
	protected $dbConnection;

	/**
	* name of session DB table
	* @var string
	*/
	protected $dbTable;

	/**
	* @param resource $dbConnect MySQLi object
	*/
	public function setDbConnection($dbConnect)
	{
		$this->dbConnection = $dbConnect;
	}

	/**
	* @param string $dbTable 
	*/
	public function setDbTable($dbTable)
	{
		$this->dbTable = $dbTable;
	}

	public function open($savePath, $sessionName)
	{
		return true;
	}

	public function close()
	{
		return true;
	}

	public function read($id)
	{
		$sql = "SELECT data FROM $this->dbTable where id = :id";
		$params = array("id" => $id);
		if ($result = $this->dbConnection->single($sql, $params)) {
			return (string)$result;
		} else {
			return false;
		}
	}

	public function write($id, $data)
	{
		$sql = "REPLACE INTO $this->dbTable (id, data, timestamp) VALUES(:id, :data, :timestamp)";
		$params = array(
			"id" => $id,
			"data" => $data,
			"timestamp" => time()
		);
		return $this->dbConnection->query($sql, $params);
	}

	public function destroy($id)
	{
		$sql = "DELETE FROM $this->dbTable WHERE id = :id";
		$params = array("id" => $id);
		return $this->dbConnection->query($sql, $params);
	}

	public function gc($maxlifetime)
	{
		$limit = time() - intval($maxlifetime);
		$sql = "DELETE from $this->dbTable WHERE timestamp < :ts";
		$params = array("ts" => $limit);
		return $this->dbConnection->query($sql, $params);
	}
}
