<?PHP

class AuthorList {
	public $list_id;
	public $label;
	public $updated_date = '';
	public $owner;
	public $count;
	public $author_qids = array();

	public function __construct ( $list_id, $params = array() ) {
		$this->list_id = $list_id;
		if (isset($params['label'])) {
			$this->label = $params['label'];
		}
		if (isset($params['date'])) {
			$this->updated_date = $params['date'];
		}
		if (isset($params['owner'])) {
			$this->owner= $params['owner'];
		}
		if (isset($params['count'])) {
			$this->count = $params['count'];
		}
		if (isset($params['qids'])) {
			$this->author_qids = $params['qids'];
			$this->count = count($params['qids']);
		}
	}

	public function save($db_conn) {
		$authors = implode('|', $this->author_qids);
		$query_owner = $db_conn->real_escape_string($this->owner);
		$query_label = $db_conn->real_escape_string($this->label);
		$dbquery = '';
		if ($this->list_id == NULL) {
			$dbquery = "INSERT INTO author_lists(owner, label, authors, updated) VALUES('$query_owner', '$query_label', '$authors', NOW())";
		} else {
			$query_id = $db_conn->real_escape_string($this->list_id);
			$dbquery = "UPDATE author_lists SET label = '$query_label', authors='$authors', updated=NOW() WHERE list_id = '$query_id' AND owner = '$query_owner'";
		}
		if (! $db_conn->query($dbquery) ) {
			print("Database error: " . $db_conn->error);
		}
	}

	public function load($db_conn) {
		$list_id = $db_conn->real_escape_string($this->list_id);
		$dbquery = "SELECT al.label, al.updated, al.owner, al.authors from author_lists al where al.list_id = '$list_id' order by al.updated desc";
		if ($results = $db_conn->query($dbquery)) {
		    $row = $results->fetch_row();
		    if ( $row != NULL ) {
			$this->label = $row[0];
			$this->updated_date = $row[1];
			$this->owner = $row[2];
			$this->author_qids = explode('|', $row[3]);
			$this->count = count($this->author_qids);
		    }
		    $results->close();
		} else {
		    print("Database error: " . $db_conn->error);
		}
	}

	public function delete($owner, $db_conn) {
		$query_id = $db_conn->real_escape_string($this->list_id);
		$query_owner = $db_conn->real_escape_string($owner);
		$dbquery = "DELETE from author_lists WHERE list_id = '$query_id' AND owner = '$query_owner'";
		$db_conn->query($dbquery);
	}

	public static function lists_count($db_conn, $owner) {
		$query_owner = $db_conn->real_escape_string($owner);
		$dbquery = "SELECT count(al.list_id) from author_lists al where al.owner = '$query_owner'";
		$lists_count = 0;
		if ($results = $db_conn->query($dbquery)) {
			$row = $results->fetch_row();
			$lists_count = $row[0];
			$results->close();
		} else {
			print("Database error: " . $db_conn->error);
		}
		return $lists_count;
	}

	public static function all_lists($db_conn, $limit = 50, $page = 1) {
		$offset = ($page - 1) * $limit;

		$dbquery = "SELECT al.list_id, al.owner, al.updated, al.label, (LENGTH(al.authors) - LENGTH(REPLACE(al.authors, '|', '')) + 1) AS count from author_lists al order by al.updated desc LIMIT $offset,$limit";
		$author_lists = array();
		if ($results = $db_conn->query($dbquery)) {
		    while ($row = $results->fetch_row()) {
			$list_id = $row[0];
			$al_data = array();
			$al_data['id'] = $list_id;
			$al_data['owner'] = $row[1];
			$al_data['date'] = $row[2];
			$al_data['label'] = $row[3];
			$al_data['count'] = $row[4];
			$author_lists[] = new AuthorList($list_id, $al_data);
		    }
		    $results->close();
		} else {
		    print("Database error: " . $db_conn->error);
		}
		return $author_lists;
	}

	public static function lists_for_owner($db_conn, $owner, $limit = 50, $page = 1) {
		$offset = ($page - 1) * $limit;
		$query_owner = $db_conn->real_escape_string($owner);

		$dbquery = "SELECT al.list_id, al.updated, al.label, al.authors from author_lists al where owner = '$query_owner' order by updated desc LIMIT $offset,$limit";
		$author_lists = array();
		if ($results = $db_conn->query($dbquery)) {
		    while ($row = $results->fetch_row()) {
			$list_id = $row[0];
			$al_data = array();
			$al_data['id'] = $list_id;
			$al_data['owner'] = $owner;
			$al_data['date'] = $row[1];
			$al_data['label'] = $row[2];
			$al_data['qids'] = explode('|', $row[3]);
			$author_lists[] = new AuthorList($list_id, $al_data);
		    }
		    $results->close();
		} else {
		    print("Database error: " . $db_conn->error);
		}
		return $author_lists;
	}

}

?>
