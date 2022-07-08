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
		$stmt = $this->dbConnection->prepare("SELECT data FROM $this->dbTable where id = ?");
		$stmt->bind_param("s", $id);
		$stmt->execute();
		if ($results = $stmt->get_result()) {
			$row = $results->fetch_row();
			return (string) $row[0];
		} else {
			return false;
		}
	}

	public function write($id, $data)
	{
		$stmt = $this->dbConnection->prepare("REPLACE INTO $this->dbTable (id, data, timestamp) VALUES(?, ?, ?)");
		$timestamp = time();
		$stmt->bind_param("ssi", $id, $data, $timestamp);
		return $stmt->execute();
	}

	public function destroy($id)
	{
		$stmt = $this->dbConnection->prepare("DELETE FROM $this->dbTable WHERE id = ?");
		$stmt->bind_param("s", $id);
		return $stmt->execute();
	}

	public function gc($maxlifetime)
	{
		$limit = time() - intval($maxlifetime);
		$stmt = $this->dbConnection->prepare("DELETE from $this->dbTable WHERE timestamp < ?");
		$stmt->bind_param("i", $limit);
		return $stmt->execute();
	}
}
