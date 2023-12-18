<?PHP

require_once ( __DIR__ . '/lib/initialize.php' ) ;
require_once ( __DIR__ . '/lib/wikidata_oauth.php' );

$dbtools = new DatabaseTools($db_passwd_file);
$db_conn = $dbtools->openToolDB('authors');
$oauth = new WD_OAuth('author-disambiguator', $oauth_ini_file, $db_conn);
$oauth->interactive = false;

$action = get_request ( 'action' , '' ) ;

$batch_id = get_request ( 'id' , '' ) ;
$page = intval(get_request ( 'page', '1' ));
$limit = intval(get_request ( 'limit', '100' ));
$filter = get_request ('filter', 'All') ;

if ($action == 'authorize') {
	$oauth->doAuthorizationRedirect($oauth_url_prefix . 'batches_oauth.php');
	$db_conn->close();
	exit(0);
}

if ($action == 'stop') {
	$stop_id = get_request( 'batch_id', '' ) ;
	$batch = new Batch($stop_id);
	$batch->load($db_conn);
	if ($batch->is_running()) {
		$batch->stop();
		sleep(1);
	}
	header("Location: ?id=$batch_id");
	exit(0);
}

if ($action == 'restart') {
	$restart_id = get_request( 'batch_id', '' ) ;
	$batch = new Batch($restart_id);
	$batch->load($db_conn);
	if (! $batch->queued) {
		$batch->add_to_queue($db_conn);
	}
	if (! $batch->is_running()) {
		if ($oauth->isAuthOK()) {
			$batch->start($oauth);
			sleep(1);
		}
	}
	header("Location: ?id=$batch_id");
	$db_conn->close();
	exit(0);
}

if ($action == 'reset') {
	$reset_id = get_request( 'batch_id', '' ) ;
	$batch = new Batch($reset_id);
	$batch->load($db_conn);
	$batch->reset($db_conn);
	$db_conn->close();

	header("Location: ?id=$batch_id");
	exit(0);
}

if ($action == 'remove_from_queue') {
	$batch_id = get_request( 'batch_id', '' ) ;
	$batch = new Batch($batch_id);
	$batch->load($db_conn);
	$batch->remove_from_queue($db_conn);
	$db_conn->close();

	header("Location: ?id=$batch_id");
	exit(0);
}

if ($action == 'delete') {
	$delete_id = get_request( 'batch_id', '' ) ;
	$delete_list = [$delete_id];
	if ($delete_id == '') {
		$delete_list = get_request ( 'deletions' , array() ) ;
	}
	if ($oauth->isAuthOK()) {
		foreach ($delete_list AS $delete_id) {
			$batch = new Batch($delete_id);
			$batch->load($db_conn);
			$batch->delete($oauth->userinfo->name, $db_conn);
		}
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

$viewall = get_request ('viewall', '');

if ( $batch_id  == '') {
	print '<div>';
	if ($viewall == '') {
		print '<b>My batches</b> - <a href="?viewall=true">View batches from all users</a>';
	} else {
		print '<b>All batches</b> - <a href="?viewall=">View just my batches</a>';
	}
	print '</div>';
	$filter_options = ['All', 'Queued', 'Running', 'Stopped', 'Errors', 'Done'];
	print "<form method='get' class='form form-inline'>";
	print "<input type='hidden' name='page' value='$page' />";
	print "<input type='hidden' name='limit' value='$limit' />";
	print "<input type='hidden' name='viewall' value='$viewall' />";
	print "<select name='filter'>" ;
	foreach ($filter_options AS $filter_option) {
		print "<option value='$filter_option'" ;
		if ($filter == $filter_option) print ' selected' ;
		print ">$filter_option</option>" ;
	}
	print "</select>&nbsp; <input type='submit' class='btn btn-primary' name='doit' value='Filter batches' /></form>";

	$batch_list = [];
	if ($viewall == '') {
		$batch_count = Batch::batches_count($db_conn, $owner);
		$max_page = ($batch_count - 1)/$limit + 1;
		print "<div>$batch_count total batches</div>";
		print "<div>";
		print "<a href='?page=1'>latest</a> | <a href='?page=$max_page'>earliest</a>";
		$prev_page = $page - 1;
		$next_page = $page + 1;
		if ($prev_page > 0) {
			print " | <a href='?page=$prev_page'>newer</a>";
		} else {
			print "| newer";
		}
		if ($next_page <= $max_page) {
			print " | <a href='?page=$next_page'>older</a>";
		} else {
			print "| older";
		}
		print "</div>";
		print "<form method='post' class='form'>" ;
		print "<input type='hidden' name='action' value='delete' />";
		print '<div>
<a href="#" onclick=\'$($(this).parents("form")).find("input[type=checkbox]").prop("checked",true);return false\'>Check all</a> | 
<a href="#" onclick=\'$($(this).parents("form")).find("input[type=checkbox]").prop("checked",false);return false\'>Uncheck all</a>
</div>
';
		$batch_list = Batch::batches_for_owner($db_conn, $owner, $limit, $page);
		$delete_count = 0;
	} else {
		$batch_list = Batch::all_batches($db_conn, $limit, $page);
	}

	print "<table class='table table-striped table-condensed'>";
	print "<tr><th>";
	if ($viewall != '') print "Owner";
	print "</th><th>Batch ID</th><th>Start time</th><th>Counts</th><th>Still processing?</th><th></th></tr>";
	foreach ($batch_list AS $batch) {
		$id = $batch->batch_id;
		$display_counts = array();
		$is_running = $batch->is_running();
		$is_queued = $batch->queued;
		$has_ready = $batch->has_ready();
		$has_error = $batch->has_error();
		if ($filter != 'All') {
			if ($is_running) {
				if ($filter != 'Running') continue;
			} else if ($is_queued) {
				if ($filter != 'Queued') continue;
			} else {
				if ($has_ready) {
					if ($filter != 'Stopped') continue;
				} else if ($has_error) {
					if ($filter != 'Errors') continue;
				} else {
					if ($filter != 'Done') continue;
				}
			}
		}
		foreach ($batch->counts AS $status => $count) {
			$display_counts[] = "$status($count)";
		}
		print "<tr><td>";
		if ($viewall == '') {
			if (!$is_running ) {
				$delete_count += 1;
				print "<input type='checkbox' name='deletions[$id]' value='$id'/></td>" ;
			}
		} else {
			print $batch->owner;
		}
		print "</td>";
		print "<td><a href='?id=$id'>$id</a></td>";
		print "<td>" . $batch->start_date . "</td>";
		print "<td>" . implode(", ", $display_counts) . "</td>";

		if ( $is_running ) {
			print "<td>Yes</td>";
			if ($viewall == '') {
				print "<td><a href='?action=stop&batch_id=$id'>Stop batch?</a></td>";
			}
		} else if ($batch->queued) {
			print "<td>Queued</td>";
			if ($viewall == '') {
				print "<td><a href='?action=remove_from_queue&batch_id=$id'>Remove from queue?</a></td>";
			}
		} else {
			print "<td>No</td>";
			if ($viewall == '') {
				if ($has_ready) {
					print "<td><a href='?action=restart&batch_id=$id'>Restart batch?</a></td>";
				} else if ($has_error) {
					print "<td><a href='?action=reset&batch_id=$id'>Reset errors?</a></td>";
				} else {
					print "<td><a href='?action=delete&batch_id=$id'>Delete batch?</a></td>";
				}
			}
		}
		
		print "</tr>";
	}
	print "</table>";
	if ($viewall == '') {
		if ($delete_count > 0) {
			print "<div style='margin:20px'><input type='submit' name='doit' value='Delete selected batches' class='btn btn-primary' /> </div>";
		}
		print "</form>" ;
	}
} else {
	$batch = new Batch($batch_id);
	$batch->load($db_conn);
	print "<h3>Batch $batch_id for " . $batch->owner . " started " . $batch->start_date . "</h3>\n";
	$reload_js = '<script type="text/javascript">
$(document).ready ( function () {
	setTimeout(function() { window.location.reload() }, 10000);
} ) ;
</script>';

	if ($batch->queued) {
		print("Queued (will run after earlier batches complete) ");
		if ($owner == $batch->owner) {
			print "<a href='?id=$batch_id&action=remove_from_queue&batch_id=$batch_id'>Remove from queue?</a>";
		}
		print($reload_js);
	} else if ($batch->has_ready()) {
		print("Still processing... ");
		if ($owner == $batch->owner) {
			print("<a href='?id=$batch_id&action=stop&batch_id=$batch_id'>Stop batch?</a>");
		}
		print($reload_js);
	} else {
		print("Batch run ended");
		if ($owner == $batch->owner) {
			if ($batch->has_error()) {
				print " <a href='?id=$batch_id&action=reset&batch_id=$batch_id'>Reset errors?</a>";
			} else {
				print " <a href='?action=delete&batch_id=$batch_id'>Delete batch?</a>";
			}
		}
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
			$label = $qid_labels[$qid];
			$qid_links[] = wikidata_link($qid, $label, ''); 
		}
		$action = $action . " for " . implode(", ", $qid_links);
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
