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

print disambig_header( True );

if ($oauth->isAuthOK()) {
	print "Wikimedia user account: " . $oauth->userinfo->name ;
	print " <span style='font-size:small'>(<a href='logout_oauth.php'>log out</a>)</a>";
} else {
	print "You haven't authorized this application yet: click <a href='?action=authorize'>here</a> to do that, then reload this page.";
}
print "<hr>";

$owner = $oauth->userinfo->name;
$dbtools = new DatabaseTools();
$db_conn = $dbtools->openToolDB('authors');

// Test exec:
// $pid = exec("ls -lR > /dev/null 2>&1 & echo $!");

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

	print "<table class='table table-striped table-condensed'><tr><th>Batch ID</th><th>Start time</th><th>Counts</th><th>Still processing?</th></tr>";
	foreach ($batch_list AS $batch_data) {
		$id = $batch_data['id'];
		print "<tr><td><a href='?id=$id'>$id</a></td>";
		print "<td>" . $batch_data['date'] . "</td>";
		$display_counts = array();
		foreach ($counts[$id] AS $status => $count) {
			$display_counts[] = "$status($count)";
		}
		print "<td>" . implode($display_counts, ", ") . "</td>";

		$pid = $batch_data['pid'];
		if (($pid != NULL) && (posix_getpgid($pid))) {
			print "<td>Yes</td>";
		} else {
			print "<td>No</td>";
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
		print("Still processing...");
		print('<script type="text/javascript">
$(document).ready ( function () {
	setTimeout(function() { window.location.reload() }, 3000);
} ) ;
</script>');
	} else {
		print("Batch run ended");
	}

	$dbquery = "SELECT ordinal, action, status, message, run from commands where batch_id = '$batch_id' order by ordinal";
	$results = $db_conn->query($dbquery);

	print "<table class='table table-striped table-condensed'><tr><th>#</th><th>Timestamp</th><th>Action</th><th>Status</th><th>Message</th></tr>";
	while ($row = $results->fetch_row()) {
		$ordinal = $row[0];
		$action = $row[1];
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
