<?PHP

# Rather than allow multiple batches to run simultaneously, queue them
# on a per-user basis (for now).

class BatchManager {
	public $user;
	public $pid = NULL;

	public function __construct ( $user, $params = array() ) {
		$this->user = $user;
		if (isset($params['pid'])) {
			$this->pid = $params['pid'];
		}
	}

	public function save($db_conn) {
		$user_name = $db_conn->real_escape_string($this->user);
		$pid = $this->pid;
		$dbquery = "DELETE FROM batch_manager where user = '$user_name'";
		if (! $db_conn->query($dbquery) ) {
			print("Database error: " . $db_conn->error);
		}
		$dbquery = "INSERT INTO batch_manager(user, manager_pid) VALUES('$user_name', $pid)";
		if (! $db_conn->query($dbquery) ) {
			print("Database error: " . $db_conn->error);
		}
	}

	public function load($db_conn) {
		$user_name = $db_conn->real_escape_string($this->user);
		$dbquery = "SELECT bm.manager_pid from batch_manager bm where bm.user = '$user_name'";
		if ($results = $db_conn->query($dbquery)) {
		    $row = $results->fetch_row();
		    if ($row != NULL) {
			$this->pid = $row[0];
		    }
		    $results->close();
		} else {
		    print("Database error: " . $db_conn->error);
		}
	}

	public function delete($db_conn) {
		$query_user = $db_conn->real_escape_string($this->user);
		$dbquery = "DELETE from batch_manager WHERE user = '$query_user'";
		$db_conn->query($dbquery);
	}

	public function stop() {
		$pidval = intval($this->pid);
		if ($pidval > 0) {
			posix_kill($pidval, 15);
		}
	}

	public function is_running() {
		$pid = $this->pid;
		if ($pid == NULL) return false;
		if (!  posix_getpgid($pid) ) return false;
		$proc_status_file = "/proc/$pid/status" ;
		$proc_status_data = file_get_contents($proc_status_file);
		$matches = array();
		preg_match_all('/^([^:]+):\s*(.*)$/m', $proc_status_data, $matches);
		$status_map = array_combine($matches[1], $matches[2]);
		if ( preg_match('/^Z/', $status_map['State'] ) ) return false; // Zombie state
		return true;
	}

	public function next_batch_id_in_queue($db_conn) {
		$query_user = $db_conn->real_escape_string($this->user);
		$dbquery = "SELECT batch_id, start from batches b where owner = '$query_user' and queued = 1 order by start asc";
		$batch_id = NULL;
		if ($results = $db_conn->query($dbquery)) {
			$row = $results->fetch_row();
			if ($row != NULL) {
				$batch_id = $row[0];
			}
			$results->close();
		} else {
			print("Database error: " . $db_conn->error);
		}
		return $batch_id;
	}

}

?>
