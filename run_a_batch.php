<?PHP

# Run a single batch

require_once ( __DIR__ . '/lib/initialize.php' ) ;
require_once ( __DIR__ . '/lib/wikidata_oauth.php' );

$pid = getmypid();

$batch_id = getenv('BATCH_ID');
if (! $batch_id) {
	print("$pid - BATCH_ID environment variable missing - exiting...\n");
	exit(1);
}

$token_key = getenv('TOKEN_KEY');
if (! $token_key) {
	print("$pid - TOKEN_KEY environment variable missing - exiting...\n");
	exit(1);
}

$token_secret = getenv('TOKEN_SECRET');
if (! $token_key) {
	print("$pid - TOKEN_SECRET environment variable missing - exiting...\n");
	exit(1);
}

$oauth = new WD_OAuth('author-disambiguator', $oauth_ini_file, $token_key, $token_secret);
if ($oauth->isAuthOK()) {
	print ("$pid - Authorized for " .  $oauth->userinfo->name . "\n") ;
} else {
	print ("$pid - Authorization not working - exiting!\n");
	exit(1);
}

$eg_string = edit_groups_string($batch_id) ;

$wil = new WikidataItemList ;
$dbtools = new DatabaseTools($db_passwd_file);
$db_conn = $dbtools->openToolDB('authors');
$dbquery = "UPDATE batches SET process_id = $pid WHERE batch_id = '$batch_id'";
$db_conn->query($dbquery);

$edit_claims = new EditClaims($oauth);

while (1) {

    $cmd_query = "SELECT ordinal, action, data FROM commands WHERE batch_id = '$batch_id' AND (status = 'READY' OR status = 'RUNNING') ORDER BY ordinal";
    $results = $db_conn->query($cmd_query);

    $actions_to_run = array();
    while ($row = $results->fetch_row()) {
	$action_data = array();
	$action_data['ordinal'] = $row[0];
	$action_data['action'] = $row[1];
	$action_data['data'] = $row[2];
	$actions_to_run[] = $action_data;
    }
    $results->close();

    if (count($actions_to_run) == 0) {
	break;
    }

    foreach ($actions_to_run AS $action_data) {
	$ordinal = $action_data['ordinal'];
	$action = $action_data['action'];
	$data = $action_data['data'];
	$running_cmd = "UPDATE commands SET status = 'RUNNING', run = NOW() WHERE batch_id = '$batch_id' and ordinal = $ordinal";
	$db_conn->query($running_cmd);
	$db_conn->close();

	$error = NULL;
	if ($action == 'replace_name') {
		$cmd_parts = array();
		if (preg_match('/^(Q\d+):(Q\d+):(\d+)/', $data, $cmd_parts)) {
			$author_q = $cmd_parts[1];
			$work_qid = $cmd_parts[2];
			$author_num = $cmd_parts[3];
			print ("$batch_id/$pid - Replacing name for $work_qid - $author_num with $author_q\n");
			$result = $edit_claims->replace_name_with_author($work_qid, $author_num, $author_q, "Author Disambiguator set author [[$author_q]] $eg_string");
			if (! $result) {
				$error = $edit_claims->error;
			}
		} else {
			$error = 'Bad data';
		}
	} else if ($action == 'revert_author') {
		$cmd_parts = array();
		if (preg_match('/^(Q\d+):(Q\d+)$/', $data, $cmd_parts)) {
			$author_q = $cmd_parts[1];
			$work_qid = $cmd_parts[2];

			$wil->loadItems( [$author_q] );
			$author_item = $wil->getItem ( $author_q ) ;

			print ("$batch_id/$pid - Reverting author for $work_qid - $author_q\n");
			$result = $edit_claims->revert_author( $work_qid, $author_item, "Author Disambiguator revert author for [[$author_q]] $eg_string" ) ;
			if (! $result) {
				$error = $edit_claims->error;
			}
		} else {
			$error = 'Bad data';
		}
	} else if ($action == 'move_author') {
		$cmd_parts = array();
		if (preg_match('/^(Q\d+):(Q\d+):(Q\d+)$/', $data, $cmd_parts)) {
			$author_q = $cmd_parts[1];
			$work_qid = $cmd_parts[2];
			$new_author_q = $cmd_parts[3];

			print ("$batch_id/$pid - Moving author for $work_qid - $author_q to $new_author_q\n");
			$result = $edit_claims->move_author( $work_qid, $author_q, $new_author_q, "Author Disambiguator change author from [[$author_q]] to [[$new_author_q]] $eg_string" ) ;
			if (! $result) {
				$error = $edit_claims->error;
			}
		} else {
			$error = 'Bad data';
		}
	} else if ($action == 'merge_work') {
		if (preg_match('/^(Q\d+)$/', $data, $cmd_parts)) {
			$work_qid = $cmd_parts[1];

			print ("$batch_id/$pid - Merging redundant author entries for $work_qid\n");
			$article_item = generate_article_entries2( [$work_qid]) [ $work_qid ];
			$to_load = array() ;
			$author_qid_map = array();
			foreach ( $article_item->authors AS $auth_list ) {
				foreach ( $auth_list AS $auth ) {
					$to_load[] = $auth ;
					$author_qid_map[$auth] = 1;
				}
			}
			foreach ( $article_item->published_in AS $pub ) $to_load[] = $pub ;
			foreach ( $article_item->topics AS $topic ) $to_load[] = $topic ;
			$to_load = array_unique( $to_load );
			$wil->loadItems ( $to_load ) ;
			$author_qids = array_keys($author_qid_map);
			$stated_as_names = fetch_stated_as_for_authors($author_qids);
			$merge_candidates = $article_item->merge_candidates($wil, $stated_as_names);
			$author_numbers = array() ;
			foreach ( $merge_candidates AS $num => $do_merge ) {
				if ($do_merge) {
					$author_numbers[] = $num ;
				}
			}
			$result = $edit_claims->merge_authors( $work_qid, $author_numbers, array(), "Author Disambiguator merge authors for [[$work_qid]] $eg_string" ) ;
			if (! $result) {
				$error = $edit_claims->error;
			}
		} else {
			$error = 'Bad data';
		}
	} else if ($action == 'merge_authors') {
		if (preg_match('/^(Q\d+):(.*):(.*)$/', $data, $cmd_parts)) {
			$work_qid = $cmd_parts[1];
			$author_numbers = explode('|', $cmd_parts[2]);
			if ($author_numbers[0] == NULL) {
				$author_numbers = array();
			}
			$remove_claims = explode('|', $cmd_parts[3]);
			if ($remove_claims[0] == NULL) {
				$remove_claims = array();
			}

			print ("$batch_id/$pid - Merging author entries for $work_qid : author numbers " . implode(',', $author_numbers) . "; removing claims " . implode(',', $remove_claims) . "\n");

			$result = $edit_claims->merge_authors( $work_qid, $author_numbers, $remove_claims, "Author Disambiguator merge authors for [[$work_qid]] $eg_string" ) ;
			if (! $result) {
				$error = $edit_claims->error;
			}
		} else {
			$error = 'Bad data';
		}
	} else if ($action == 'renumber_authors') {
		if (preg_match('/^(Q\d+):(.*):(.*)$/', $data, $cmd_parts)) {
			$work_qid = $cmd_parts[1];
			$renumbering_pairs = explode('|', $cmd_parts[2]);
			if ($renumbering_pairs[0] == NULL) {
				$renumbering_pairs = array();
			}
			$remove_claims = explode('|', $cmd_parts[3]);
			if ($remove_claims[0] == NULL) {
				$remove_claims = array();
			}
			$claims = array();
			$new_nums = array();
			foreach ($renumbering_pairs AS $pair) {
				$parts = explode(',', $pair);
				$claims[] = $parts[0];
				$new_nums[] = $parts[1];
			}
			$renumbering = array_combine($claims, $new_nums);

			print ("$batch_id/$pid - Renumbering authors for $work_qid\n");
			$result = $edit_claims->renumber_authors( $work_qid, $renumbering, $remove_claims, "Author Disambiguator renumber authors for [[$work_qid]] $eg_string" ) ;
			if (! $result) {
				$error = $edit_claims->error;
			}
		} else {
			$error = 'Bad data';
		}
	} else if ($action == 'match_authors') {
		if (preg_match('/^(Q\d+):(.*)$/', $data, $cmd_parts)) {
			$work_qid = $cmd_parts[1];
			$matches = explode('|', $cmd_parts[2]);
			if ($matches[0] == NULL) {
				$matches = array();
			}
			print ("$batch_id/$pid - Matching authors for $work_qid\n");
			$result = $edit_claims->match_authors( $work_qid, $matches, "Author Disambiguator matching authors for [[$work_qid]] $eg_string" ) ;
			if (! $result) {
				$error = $edit_claims->error;
			}
		} else {
			$error = 'Bad data';
		}
	}
	$db_conn = $dbtools->openToolDB('authors');
	$finished_cmd = "UPDATE commands SET status = 'DONE' WHERE batch_id = '$batch_id' and ordinal = '$ordinal'";
	if ($error != NULL) {
		$error_str = $db_conn->real_escape_string($error);
		$finished_cmd = "UPDATE commands SET status = 'ERROR', message = '$error_str' WHERE batch_id = '$batch_id' and ordinal = '$ordinal'";
	}
	$db_conn->query($finished_cmd);
    }
}
$db_conn->close();


exit ( 0 ) ;

?>
