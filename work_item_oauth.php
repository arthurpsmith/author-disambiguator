<?PHP

require_once ( __DIR__ . '/lib/initialize.php' ) ;
require_once ( __DIR__ . '/lib/wikidata_oauth.php' );

$oauth = new WD_OAuth('author-disambiguator', $oauth_ini_file);
$oauth->interactive = true;

$batch_id = get_request ( 'batch_id' , '' ) ;
$action = get_request ( 'action' , '' ) ;
$work_qid = get_request( 'id', '' ) ;
$author_list_id = get_request ( 'author_list_id' , '' );
$renumber = get_request ( 'renumber' , 0 ) ;
$match  = get_request ( 'match' , 0 ) ;
$use_stated_as = get_request ( 'use_stated_as', 0 );
$renumber = $match ? 0: $renumber ; # Supercede renumbering if match selected
$renumber_checked = $renumber ? 'checked' : '' ;
$match_checked = $match ? 'checked' : '' ;
$use_stated_as_checked = $use_stated_as ? 'checked' : '' ;

if ($action == 'authorize') {
	$oauth->doAuthorizationRedirect($oauth_url_prefix . 'work_item_oauth.php');
	exit(0);
}

if ($action) { # reset checkboxes after action
	$renumber = 0;
	$match = 0;
	$use_stated_as = 0;
	$renumber_checked = '';
	$match_checked = '';
	$use_stated_as_checked = '';
}

print disambig_header( True );

if ($oauth->isAuthOK()) {
	print "Wikimedia user account: " . $oauth->userinfo->name ;
	print " <span style='font-size:small'>(<a href='logout_oauth.php'>log out</a>)</a>";
} else {
	print "You haven't authorized this application yet: click <a href='?action=authorize'>here</a> to do that, then reload this page.";
	print_footer() ;
	exit ( 0 ) ;
}

$wil = new WikidataItemList ;
$dbtools = new DatabaseTools($db_passwd_file);

$batch_actions = ['merge', 'renumber', 'match'];

if ($action != '' && in_array($action, $batch_actions)) {
    $db_conn = $dbtools->openToolDB('authors');

    if ($batch_id != '') {
	$batch_id_str = $db_conn->real_escape_string($batch_id);
	$dbquery = "SELECT COUNT(*) from batches WHERE batch_id = '$batch_id_str'";
	$results = $db_conn->query($dbquery);
	$row = $results->fetch_row();
	if ($row[0] <= 0) { # No db entry for this batch id, create a new one
		$batch_id = '';
	}
    }

    if ($batch_id == '') {
		$batch_id = Batch::generate_batch_id() ;
		$dbquery = "INSERT INTO batches VALUES('$batch_id', '" . $db_conn->real_escape_string($oauth->userinfo->name) . "',  NULL, NULL, 1)";
		$db_conn->query($dbquery);
    }
    $seq_query = "SELECT max(ordinal) from commands where batch_id = '$batch_id'";
    $results = $db_conn->query($seq_query);
    $row = $results->fetch_row();
    $seq = 1;
    if ($row != NULL) {
		$seq = $row[0] + 1;
    }

    if ( $action == 'merge' ) {
	$add_command = $db_conn->prepare("INSERT INTO commands VALUES(?, '$batch_id', 'merge_authors', ?, 'READY', NULL, NULL)");

	$author_numbers = get_request ( 'merges' , array() ) ;
	$remove_claims = get_request ( 'remove_claims' , array() ) ;

	$data = $work_qid . ':' . implode('|', $author_numbers) . ':' . implode('|', $remove_claims);
	$add_command->bind_param('is', $seq, $data);
	$add_command->execute();
	$add_command->close();
    }

    if ($action == 'renumber') {
	$add_command = $db_conn->prepare("INSERT INTO commands VALUES(?, '$batch_id', 'renumber_authors', ?, 'READY', NULL, NULL)");

	$renumbering = get_request ( 'ordinals' , array() ) ;
	$remove_claims = get_request ( 'remove_claims' , array() ) ;

	$renumbering_pairs = array();
	foreach ($renumbering AS $claim => $num) {
		$renumbering_pairs[] = "$claim,$num";
	}

	$data = $work_qid . ':' . implode('|', $renumbering_pairs) . ':' . implode('|', $remove_claims);
	$add_command->bind_param('is', $seq, $data);
	$add_command->execute();
	$add_command->close();
    }

    if ( $action == 'match' ) {
	$add_command = $db_conn->prepare("INSERT INTO commands VALUES(?, '$batch_id', 'match_authors', ?, 'READY', NULL, NULL)");

	$matches = get_request ( 'match_author' , array() ) ;

	$data = $work_qid . ':' . implode('|', $matches);
	$add_command->bind_param('is', $seq, $data);
	$add_command->execute();
	$add_command->close();
    }

    $batch = new Batch($batch_id);
    $batch->load($db_conn);
    if (! $batch->queued) {
	$batch->add_to_queue($db_conn);
    }

    $db_conn->close();
    if (! $batch->is_running()) {
	print("Starting batch!\n");
	$batch->start($oauth);
    }
}

print "<hr>";

$reload_url = "?id=$work_qid&batch_id=$batch_id&author_list_id=$author_list_id";

print('<script type="text/javascript">
var timeout;

function reloadPageWithTimeout () {
	timeout = setTimeout(function() { window.location.replace("' . $reload_url . '") }, 10000);
}

function stopReloads () {
    clearTimeout(timeout);
}
</script>');

print "<form method='get' class='form form-inline'>
Work Wikidata ID: 
<input name='id' value='" . escape_attribute($work_qid) . "' type='text' placeholder='Qxxxxx' oninput='stopReloads()' />
<input type='hidden' name='batch_id' value='$batch_id'>
<input type='hidden' name='author_list_id' value='$author_list_id'>
<label style='margin:10px'><input type='checkbox' name='renumber' value='1' $renumber_checked />Renumber authors?</label>
<label style='margin:10px'><input type='checkbox' name='match' value='1' $match_checked />Suggest matches?</label>
<label style='margin:10px'><input type='checkbox' name='use_stated_as' value='1' $use_stated_as_checked />Use \"stated as\" names (can be slow)?</label>
<input type='submit' class='btn btn-primary' name='doit' value='Get author links for work' />
</form>" ;

if ( $work_qid == '' ) {
	print_footer() ;
	exit ( 0 ) ;
}

$db_conn = $dbtools->openToolDB('authors');

if ($batch_id != '') {
	$batch = new Batch($batch_id);
	$batch->load($db_conn);
	$display_counts = array();
	foreach ($batch->counts AS $status => $count) {
		$display_counts[] = "$status($count)";
	}

	print "<div>Current batch for edits: <a href='batches_oauth.php?id=$batch_id'>$batch_id</a> - ";
	print implode($display_counts, ", ") . "</div>";

	$batch_id_str = $db_conn->real_escape_string($batch_id);
	$qid_str = $db_conn->real_escape_string($work_qid);
	$cmd_query = "SELECT ordinal FROM commands WHERE batch_id = '$batch_id_str' AND (status = 'READY' OR status = 'RUNNING') AND data like '$qid_str:%'";
	$results = $db_conn->query($cmd_query);
	$row = $results->fetch_row();
	$results->close();

	if ($row != NULL) {
		print("... waiting on update for this work ...");
		print('<script type="text/javascript">
$(document).ready (reloadPageWithTimeout());
</script>');
		print_footer() ;
		exit(0);
	}
}

$auth_list_for_match = NULL;
if ($author_list_id !== '') {
	$auth_list_for_match = new AuthorList($author_list_id);
	$auth_list_for_match->load($db_conn);
	print "<div>Current author list for matching: <a href='author_lists.php?list_id=$author_list_id'>$author_list_id</a> - ";
	print $auth_list_for_match->label;
	if ($action === '') {
		print "<form method='get' class='form form-inline'>";
		print "<input type='hidden' name='action' value='update_author_list' />";
		print "<input type='hidden' name='id' value='$work_qid' />" ;
		print "<input type='hidden' name='batch_id' value='$batch_id' />" ;
		print "<input type='hidden' name='author_list_id' value='$author_list_id' />" ;
		print "<input type='submit' name='update_author_list' value='Update with known authors from this work' class='btn btn-primary btn-sm' />";
		print "</form>\n";
	}
	print "</div>";
}

$db_conn->close();

$article_entry = generate_article_entries2( [$work_qid] ) [ $work_qid ];

if ($action === 'update_author_list') {
	$author_qid_map = array();
	foreach ($auth_list_for_match->author_qids AS $qid) {
		$author_qid_map[$qid] = 1;
	}
	foreach ( $article_entry->authors AS $auth_list ) {
		foreach ( $auth_list AS $qid ) {
			$author_qid_map[$qid] = 1;
		}
	}
	$auth_list_for_match->author_qids = array_keys($author_qid_map);
	$db_conn = $dbtools->openToolDB('authors');
	$auth_list_for_match->save($db_conn);
	$db_conn->close();
}

// Load items
$to_load = array() ;
$to_load[] = $work_qid ;

foreach ( $article_entry->authors AS $auth_list ) {
	foreach ( $auth_list AS $auth ) {
		$to_load[] = $auth ;
	}
}
foreach ( $article_entry->published_in AS $pub ) $to_load[] = $pub ;
foreach ( $article_entry->topics AS $topic ) $to_load[] = $topic ;

$to_load = array_unique( $to_load );
$wil->loadItems ( $to_load ) ;

$work_item = $wil->getItem ( $work_qid ) ;

if ( !isset($work_item) )  {
	print "<h2>Warning: $work_qid not found!</h2>" ;
	print_footer() ;
	exit ( 0 ) ;
}

# Regenerate article entry directly from item:
$article_entry = new WikidataArticleEntry2( $work_item );

$author_stats = $article_entry->author_statistics();

print "<h2>" . $work_item->getLabel() . "</h2>" ;
print "<div>" ;
print wikidata_link($work_qid, "Wikidata Item", '') ;
print ' | ' ;
print "<a target='_blank' href='https://scholia.toolforge.org/work/$work_qid'>Scholia Work Page</a>&nbsp;[<a href='https://scholia.toolforge.org/work/$work_qid/missing' target='_blank'>missing</a>]";
print ' | ' ;
print "<a target='_blank' href='https://tools.wmflabs.org/reasonator/?q=$work_qid'>Reasonator</a>" ;
print ' | ' ;
print "<a target='_blank' href='https://tools.wmflabs.org/sqid/#/view?id=$work_qid'>SQID</a>" ;
print '</div><div>' ;
print "Published: " .  $article_entry->formattedPublicationDate () . "; " ;
if ( $article_entry->doi != '' ) {
	print "DOI: <a target='_blank' href='https://doi.org/$article_entry->doi'>$article_entry->doi</a>; " ;
}
if ($article_entry->pmid != '' ) {
	print "PubMed: <a target='_blank' href='https://www.ncbi.nlm.nih.gov/pubmed/?term=$article_entry->pmid'>$article_entry->pmid</a>" ;
}
print '</div><div>' ;

$published_in = array() ;
foreach ( $article_entry->published_in AS $qt ) {
	$i2 = $wil->getItem ( $qt ) ;
	if ( isset($i2) ) $published_in[] = wikidata_link($i2->getQ(), $i2->getLabel(), 'black') . "&nbsp;[<a href='https://scholia.toolforge.org/venue/" . $i2->getQ() . "/missing' target='_blank'>missing</a>]" ;
}
$published_in_list = implode ( ', ', $published_in ) ;
print "Journal(s): $published_in_list" ;
if ( count($article_entry->topics) > 0 ) {
	print '</div><div>' ;
	print "Main subject(s): ";
	$topics = [] ;
	foreach ( $article_entry->topics AS $qt ) {
		$i2 = $wil->getItem($qt) ;
		if ( !isset($i2) ) continue ;
		$topics[] = wikidata_link($i2->getQ(), $i2->getLabel(), 'brown') . "&nbsp;[<a href='https://scholia.toolforge.org/topic/" . $i2->getQ() . "/missing' target='_blank'>missing</a>]" ;
	}
	print implode ( '; ' , $topics ) ;
}
print "</div>" ;

print "<div><b>Author Statistics:</b> ";
$idenfied_pct = '';
$name_pct = '';
$id_count = $author_stats['identified_count'];
$name_count = $author_stats['name_count'];
$max_num = $author_stats['max_num'];
if ($max_num > 0) {
	$identified_pct = $id_count*100.0/$max_num;
	$name_pct = $name_count*100.0/$max_num;
}
printf("%d/%d (%.2f%%) identified,", $id_count, $max_num, $identified_pct);
printf(" with %d/%d (%.2f%%) names remaining to match", $name_count, $max_num, $name_pct);
print "</div>";

# Fetch 'stated as' values for all identified authors:
$author_qid_map = array();
foreach ( $article_entry->authors as $author_qid_list ) {
	foreach ($author_qid_list as $qid) {
		$author_qid_map[$qid] = 1;
	}
}
$author_qids = array_keys($author_qid_map);
if ($match) {
	$related_authors = NULL;
	if ($author_list_id != '') {
		$related_authors = $auth_list_for_match->author_qids;

	} else {
		$related_authors = fetch_related_authors($work_qid, $author_qids);
	}
	$related_authors = array_diff($related_authors, $author_qids); # Only fetch stated-as etc. for new qids
	$wil->loadItems ( $related_authors ) ;
	$stated_as_names = array();
	if ($use_stated_as) {
		$stated_as_names = fetch_stated_as_for_authors($related_authors);
	}
	$match_candidates = $article_entry->match_candidates($wil, $related_authors, $stated_as_names);
	$items_authors = $author_qids;
	foreach ($match_candidates AS $author_qids) {
		$items_authors = array_merge($items_authors, $author_qids);
	}
	$match_candidate_data = AuthorData::authorDataFromItems( $items_authors, $wil, false ) ;
	$to_load = array();
	foreach ($match_candidate_data AS $author_data) {
		foreach ($author_data->employer_qids as $q) $to_load[] = $q ;
	}
	$to_load = array_unique($to_load);
	$wil->loadItems ( $to_load ) ;
} else {
	$stated_as_names = fetch_stated_as_for_authors($author_qids);
	$merge_candidates = $article_entry->merge_candidates($wil, $stated_as_names);
}
$repeated_ids = $article_entry->repeated_ids();

# Reload author items in case some missed from SPARQL:
$to_load = [];
foreach ( $article_entry->authors AS $num => $qt_list ) {
	foreach ( $qt_list as $qt ) {
		$to_load[] = $qt;
	}
}
$wil->loadItems ( $to_load ) ;

// Author list
print "<h2>Authors</h2>" ;
print "<form method='post' class='form'>" ;
if ($renumber) {
    print "<input type='hidden' name='action' value='renumber' />";
} else if ($match) {
    print "<input type='hidden' name='action' value='match' />";
} else {
    print "<input type='hidden' name='action' value='merge' />";
}
print "<input type='hidden' name='id' value='$work_qid' />" ;
print "<input type='hidden' name='batch_id' value='$batch_id' />" ;
print "<input type='hidden' name='author_list_id' value='$author_list_id' />" ;

?>

<div>
<a href='#' onclick='$($(this).parents("form")).find("input[type=checkbox]").prop("checked",true);return false'>Check all</a> | 
<a href='#' onclick='$($(this).parents("form")).find("input[type=checkbox]").prop("checked",false);return false'>Uncheck all</a>
</div>

<?PHP

$formatted_authors = array();
foreach ( $article_entry->author_names AS $num => $a_list ) {
	$formatted_authors[$num] = [];
	foreach ( $a_list AS $id => $a ) {
		$formatted_authors[$num][$id] = "<a href='names_oauth.php?limit=50&name=" . urlencode($a) . "'>$a</a>" ;
	}
}
foreach ( $article_entry->authors AS $num => $qt_list ) {
	if (! isset($formatted_authors[$num])) {
		$formatted_authors[$num] = [];
	}
	foreach ( $qt_list AS $id => $qt ) {
		$i2 = $wil->getItem ( $qt ) ;
		if (! isset($i2) ) {
			print "Warning: item not found for $qt; author $num";
			continue;
		}
		$label = $i2->getLabel() ;
		$formatted_authors[$num][$id] = "<a href='author_item_oauth.php?limit=50&id=" . $i2->getQ() . "' style='color:green'>$label</a>&nbsp;[<a href='https://scholia.toolforge.org/author/" . $i2->getQ() . "/missing' target='_blank'>missing</a>]" ;
		if (isset($repeated_ids[$qt])) {
			$rpt_nums = $repeated_ids[$qt];
			$formatted_authors[$num][$id] .= " also ";
			foreach ($rpt_nums as $rptnum ) {
				if ($rptnum != $num) {
					$formatted_authors[$num][$id] .= "#" . $rptnum ;
				}
			}
		}
	}
}

ksort($formatted_authors);

print('<table class="table table-striped table-condensed">');
if ($match) {
	print('<tr><th>Row</th><th>Author</th><th>Potential match?</th><th>Description</th><th>Works</th><th>Employer(s)</th></tr>');
}
$merge_count = 0;
$row_count = 0;
$match_count = 0;
foreach ( $formatted_authors AS $num => $display_list ) {
	if ($num == 'unordered') {
		continue ;
	}
        $row_count ++;
	if ($match) {
		$rows_for_matches = 1;
		if (isset($match_candidates[$num])) {
			$rows_for_matches = count($match_candidates[$num]);
		}
		print "<tr><td rowspan='$rows_for_matches'>$row_count</td><td rowspan='$rows_for_matches'>";
        } else {
		print "<tr><td>$row_count</td><td>";
		if ( $merge_candidates[$num] ) {
			$merge_count += 1;
			if (! $renumber) {
				print "<input type='checkbox' name='merges[$num]' value='$num' checked/>" ;
			}
		} else if (count($display_list) > 1) {
			print "<span style='color:red'>Name/id mismatch:</span>";
		}
	}
	if ($renumber) {
		foreach ( $display_list AS $cid => $display_name ) {
			print "<input size='1' name='ordinals[$cid]' value='$num'/>$display_name";
		}
	} else {
		print "[$num] ";
		print implode ( '|', $display_list) . '</td><td>';
		if ($match) {
			$qid_list = array();
			$match_rows = array() ;
			$has_match = 0;
			if (isset($match_candidates[$num])) {
				$has_match = 1;
				$match_count += 1;
				$qid_list = $match_candidates[$num];
			} else if (isset($article_entry->authors[$num])) {
				$qid_list = $article_entry->authors[$num];
			}
			if (count($qid_list) > 0) {
				foreach ($qid_list AS $match_qid) {
					$i = $wil->getItem ( $match_qid ) ;
					if ( !isset($i) ) continue ;
					$author_data = NULL;
					if (isset($match_candidate_data[$match_qid])) {
						$author_data = $match_candidate_data[$match_qid];
					}
					$row_data = array();
					if ($has_match == 1) {
						$row_data[] = "<input type='checkbox' name='match_author[$match_qid:$num]' value='$match_qid:$num' /><a href='author_item_oauth.php?id=" . $i->getQ() . "' target='_blank' style='color:green'>" . $i->getLabel() . "</a>" ;
					} else {
						$row_data[] = '';
					}
					$row_data[] = $i->getDesc();
					if ($author_data != NULL) {
						$row_data[] = $author_data->article_count ;
						$employers = array();
						foreach ( $author_data->employer_qids AS $emp_qid ) {
							$emp_item = $wil->getItem ( $emp_qid ) ;
							if ( !isset($emp_item) ) continue ;
							$employers[] = wikidata_link($emp_qid, $emp_item->getLabel(), '') ;
						}
						$row_data[] = implode(" | ", $employers) ;
					} else {
						$row_data[] = '';
						$row_data[] = '';
					}
					$match_rows[] = '' . implode('</td><td>', $row_data);
				}
			} else {
				$match_rows[] = '</td><td></td><td></td><td>';
			}
			print implode( '</td></tr><tr><td>', $match_rows);
		}
	}
	print "</td></tr>\n";
}
print "</table>\n" ;

if (isset($formatted_authors['unordered']) ) {
	print "<h3>Unordered authors - set author number or check to remove</h3>" ;
	$merge_count += 1;
	print "<ul>";
	foreach ( $formatted_authors['unordered'] AS $cid => $formatted_auth ) {
		print "<li> ";
		print "<input type='checkbox' name='remove_claims[$cid]' value='$cid' />";
		if ($renumber) {
			print "<input size='1' name='ordinals[$cid]' value=''/>";
		}
		print "$formatted_auth</li>";
	}
	print "</ul>";
}

if ($renumber) {
	print "<div style='margin:20px'><input type='submit' name='renumber' value='Renumber authors' class='btn btn-primary' /> </div>";
} else if ($merge_count > 0) {
	print "<div style='margin:20px'><input type='submit' name='doit' value='Merge these author records' class='btn btn-primary' /></div>" ;
}
if ($match) {
	if ($match_count > 0) {
		print "<div style='margin:20px'><input type='submit' name='match' value='Match selected authors' class='btn btn-primary' /> </div>";
	} else {
		print "<div>No matches found</div>";
	}
}
print "</form>" ;

print_footer() ;

?>
