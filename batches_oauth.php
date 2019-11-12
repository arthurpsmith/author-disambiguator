<?PHP

require_once ( __DIR__ . '/lib/initialize.php' ) ;
require_once ( __DIR__ . '/lib/wikidata_oauth.php' );

$oauth = new WD_OAuth('author-disambiguator', $oauth_ini_file);

$action = get_request ( 'action' , '' ) ;

$batch_id = get_request ( 'id' , '' ) ;

if ($action == 'authorize') {
	$oauth->doAuthorizationRedirect($oauth_url_prefix . 'batches_oauth.php');
	exit(0);
}

if ($action == 'stop') {
	$pid = get_request ( 'pid', '' ) ;
	$pidval = intval($pid);
	if ($pidval > 0) {
		posix_kill($pidval, 15);
		sleep(1);
		header("Location: ?id=$batch_id");
		exit(0);
	}
}

$dbtools = new DatabaseTools($db_passwd_file);
$db_conn = $dbtools->openToolDB('authors');

if ($action == 'restart') {
	$restart_id = get_request( 'batch_id', '' ) ;
	$query_id = $db_conn->real_escape_string($restart_id);
	$dbquery = "SELECT b.process_id, b.batch_id from batches b WHERE b.batch_id = '$query_id'";
	$results = $db_conn->query($dbquery);
	$row = $results->fetch_row();
	$pid = $row[0];
	$restart_id = $row[1]; # Confirm it from the db...
	if (($pid != NULL) && (posix_getpgid($pid))) {
		# Already running - do nothing
	} else if (($restart_id != '') && ($oauth->isAuthOK())) {
		$env_cmds = "BATCH_ID=$restart_id";
		$env_cmds .= " TOKEN_KEY=" . $oauth->gTokenKey;
		$env_cmds .= " TOKEN_SECRET=" . $oauth->gTokenSecret;
		exec("$env_cmds nohup /usr/bin/php run_background.php >> bg.log 2>&1 &");

		sleep(1);
	}
	header("Location: ?id=$batch_id");
	$db_conn->close();
	exit(0);
}

if ($action == 'reset') {
	$reset_id = get_request( 'batch_id', '' ) ;
	$query_id = $db_conn->real_escape_string($reset_id);
	$dbquery = "UPDATE commands SET status = 'READY', message = NULL WHERE status = 'ERROR' and batch_id = '$query_id'";
	$db_conn->query($dbquery);

	header("Location: ?id=$batch_id");
	$db_conn->close();
	exit(0);
}

if ($action == 'delete') {
	$delete_id = get_request( 'batch_id', '' ) ;
	if ($oauth->isAuthOK()) {
		$owner = $oauth->userinfo->name;
		$query_id = $db_conn->real_escape_string($delete_id);
		$query_owner = $db_conn->real_escape_string($owner);
		$dbquery = "DELETE from batches WHERE batch_id = '$query_id' AND owner = '$query_owner'";
		$db_conn->query($dbquery);
	}
}

print disambig_header( True );

if ($oauth->isAuthOK()) {
	print "Wikimedia user account: " . $oauth->userinfo->name ;
	print " <span style='font-size:small'>(<a href='logout_oauth.php'>log out</a>)</a>";
} else {
	print "You haven't authorized this application yet: click <a href='?action=authorize'>here</a> to do that, then reload this page.";
}
print "<hr>";

$owner = $oauth->userinfo->name;

if ( $batch_id  == '') {
	$dbquery = "SELECT b.batch_id, b.start, b.process_id, cmd.status, count(*) from batches b left join commands cmd on cmd.batch_id = b.batch_id where owner = '$owner' group by b.batch_id, b.start, cmd.status order by start desc";

	$batch_list = array();
	$counts = array();
	$results = $db_conn->query($dbquery);
	while ($row = $results->fetch_row()) {
		$batch_id = $row[0];
		if (! isset($counts[$batch_id]) ) {
			$counts[$batch_id] = array();
			$batch_data = array();
			$batch_data['id'] = $batch_id;
			$batch_data['date'] = $row[1];
			$batch_data['pid'] = $row[2];
			$batch_list[] = $batch_data;
		}
		$status = $row[3];
		$counts[$batch_id][$status] = $row[4];
	}
	$results->close();

	print "<table class='table table-striped table-condensed'><tr><th>Batch ID</th><th>Start time</th><th>Counts</th><th>Still processing?</th><th></th></tr>";
	foreach ($batch_list AS $batch_data) {
		$id = $batch_data['id'];
		print "<tr><td><a href='?id=$id'>$id</a></td>";
		print "<td>" . $batch_data['date'] . "</td>";
		$display_counts = array();
		$has_ready = 0;
		$has_error = 0;
		foreach ($counts[$id] AS $status => $count) {
			$display_counts[] = "$status($count)";
			if ($status == 'READY' OR $status == 'RUNNING') {
				$has_ready = 1 ;
			} else if ($status == 'ERROR') {
				$has_error = 1;
			}
		}
		print "<td>" . implode($display_counts, ", ") . "</td>";

		$pid = $batch_data['pid'];
		if (($pid != NULL) && (posix_getpgid($pid))) {
			print "<td>Yes</td>";
			print "<td><a href='?action=stop&pid=$pid'>Stop batch?</a></td>";
		} else {
			print "<td>No</td>";
			if ($has_ready == 1) {
				print "<td><a href='?action=restart&batch_id=$id'>Restart batch?</a></td>";
			} else if ($has_error == 1) {
				print "<td><a href='?action=reset&batch_id=$id'>Reset errors?</a></td>";
			} else {
				print "<td><a href='?action=delete&batch_id=$id'>Delete batch?</a></td>";
			}
		}
		
		print "</tr>";
	}
	print "</table>";
} else {
	$dbquery = "SELECT start, process_id from batches where batch_id = '$batch_id'";
	$results = $db_conn->query($dbquery);
	$row = $results->fetch_row();
	$start_time = $row[0];
	$pid = $row[1];
	$results->close();
	print "<h3>Batch $batch_id started $start_time</h3>\n";
	if (($pid != NULL) && (posix_getpgid($pid))) {
		print("Still processing... ");
		print("<a href='?id=$batch_id&action=stop&pid=$pid'>Stop batch?</a>");
		print('<script type="text/javascript">
$(document).ready ( function () {
	setTimeout(function() { window.location.reload() }, 3000);
} ) ;
</script>');
	} else {
		print("Batch run ended");
	}

	$qids_by_ordinal = array();
	$qid_set = array();
	$dbquery = "SELECT ordinal, data from commands where batch_id = '$batch_id'";
	$results = $db_conn->query($dbquery);
	while ($row = $results->fetch_row()) {
		$ordinal = $row[0];
		$qids_by_ordinal[$ordinal] = array();
		$data = $row[1];
		$parts = preg_split('/:/', $data);
		foreach ($parts AS $data_part) {
			if (preg_match('/^Q\d+/', $data_part)) {
				$qids_by_ordinal[$ordinal][] = $data_part;
				$qid_set[$data_part] = 1;
			}
		}
	}
	$qid_labels = AuthorData::labelsForItems(array_keys($qid_set));

	$dbquery = "SELECT ordinal, action, status, message, run from commands where batch_id = '$batch_id' order by ordinal";
	$results = $db_conn->query($dbquery);

	print "<table class='table table-striped table-condensed'><tr><th>#</th><th>Timestamp</th><th>Action</th><th>Status</th><th>Message</th></tr>";
	while ($row = $results->fetch_row()) {
		$ordinal = $row[0];
		$action = $row[1];
		$qid_links = array();
		$qids = $qids_by_ordinal[$ordinal];
		foreach ($qids AS $qid) {
			$label = $qid_labels[$qid][0];
			$qid_links[] = wikidata_link($qid, $label, ''); 
		}
		$action = $action . " for " . implode($qid_links, ", ");
		$status = $row[2];
		$message = $row[3];
		$run_timestamp = $row[4];
		print "<tr><td>$ordinal</td>";
		print "<td>$run_timestamp</td>";
		print "<td>$action</td>";
		print "<td>$status</td>";
		print "<td>$message</td>";
		print "</tr>\n";
	}
	print "</table>";
	$results->close();
}
$db_conn->close();

print_footer() ;

?>
