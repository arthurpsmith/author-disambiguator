<?PHP

require_once ( __DIR__ . '/lib/initialize.php' ) ;
require_once ( __DIR__ . '/lib/wikidata_oauth.php' );

$dbtools = new DatabaseTools($db_passwd_file);
$db_conn = $dbtools->openToolDB('authors');
$oauth = new WD_OAuth('author-disambiguator', $oauth_ini_file, $db_conn);
$oauth->interactive = true;

$action = get_request ( 'action' , '' ) ;
$author_qid = get_request( 'id', '' ) ;
$article_limit = get_request ( 'limit', '' ) ;
if ($article_limit == '' ) $article_limit = 5000 ;
$merge = get_request ( 'merge' , 0 ) ;
$merge_checked = $merge ? 'checked' : '' ;
$filter = get_request ( 'filter', '' ) ;

if ($action == 'authorize') {
	$oauth->doAuthorizationRedirect($oauth_url_prefix . 'author_item_oauth.php');
	$db_conn->close();
	exit(0);
}
$prefs = new Preferences;
$use_scholarly_subgraph = $prefs->use_scholarly_subgraph;
$db_conn->close();

print disambig_header( True );

if ($oauth->isAuthOK()) {
        print oauth_user_header($oauth, $use_scholarly_subgraph);
} else {
	print "You haven't authorized this application yet: click <a href='?action=authorize'>here</a> to do that, then reload this page.";
	print_footer() ;
	exit ( 0 ) ;
}
print "<hr>";

print "<form method='get' class='form form-inline'>
Author Wikidata ID: 
<input name='id' value='" . escape_attribute($author_qid) . "' type='text' placeholder='Qxxxxx' />
<label title='search for multiple matching authors with same ordinal'><input type='checkbox' name='merge' value='1' $merge_checked />Find duplicates to merge</label>
<div style='margin:10px'><input type='submit' class='btn btn-primary' name='doit' value='Get author data' /></div>
<div style='font-size:9pt'>Additional SPARQL filters separated by semicolons (eg. for papers on Zika virus, enter wdt:$topic_prop_id wd:Q202864):
<input style='font-size:9pt' size='40' name='filter' value='" . escape_attribute($filter) . "' type='text' placeholder='wdt:PXXX wd:QYYYYY; wdt:PXX2 wd:QYY2 '/></div>
</form>" ;

if ( $author_qid == '' ) {
	print_auth_example() ;
	print_footer() ;
	exit ( 0 ) ;
}

$eg_string = edit_groups_string() ;
if ( $action == 'remove' ) {
	$batch_id = Batch::generate_batch_id() ;

	$author_match = trim ( get_request ( 'author_match' , '' ) ) ;
	$new_author_q = trim ( get_request ( 'new_author_q' , '' ) ) ;
	$papers = get_request ( 'papers' , array() ) ;

	$dbtools = new DatabaseTools($db_passwd_file);
	$db_conn = $dbtools->openToolDB('authors');
	$dbquery = "INSERT INTO batches VALUES('$batch_id', '" . $db_conn->real_escape_string($oauth->userinfo->name) . "',  NULL, NULL, 1)";
	if (! $db_conn->query($dbquery) ) {
		print("Database update failed - quitting!");
		print_footer() ;
		exit ( 0 ) ;
	}

	$add_command = $db_conn->prepare("INSERT INTO commands VALUES(?, '$batch_id', 'move_author', ?, 'READY', NULL, NULL)");
	if ( $author_match == 'none' ) {
		$add_command = $db_conn->prepare("INSERT INTO commands VALUES(?, '$batch_id', 'revert_author', ?, 'READY', NULL, NULL)");
	}

	$seq = 0;
	foreach ( $papers AS $work_qid ) {
		$seq += 1;
		$data = "$author_qid:$work_qid:$new_author_q";
		if ( $author_match == 'none' ) {
			$data = "$author_qid:$work_qid";
		}
		$add_command->bind_param('is', $seq, $data);
		$add_command->execute();
	}
	$add_command->close();

	$batch = new Batch($batch_id);
	$batch->load($db_conn);

	$db_conn->close();

	if ($seq > 0) { // Don't bother to start if no commands to run
		$batch->start($oauth);
		sleep(1);
	}
	header("Location: batches_oauth.php?id=$batch_id");
	exit ( 0 ) ;
}

$wil = new WikidataItemList ;

if ($action == 'merge') {
	$batch_id = Batch::generate_batch_id() ;

	$papers = get_request ( 'papers' , array() ) ;

	$dbtools = new DatabaseTools($db_passwd_file);
	$db_conn = $dbtools->openToolDB('authors');
	$dbquery = "INSERT INTO batches VALUES('$batch_id', '" . $db_conn->real_escape_string($oauth->userinfo->name) . "',  NULL, NULL, 1)";
	if (! $db_conn->query($dbquery) ) {
		print("Database update failed - quitting!");
		print_footer() ;
		exit ( 0 ) ;
	}

	$add_command = $db_conn->prepare("INSERT INTO commands VALUES(?, '$batch_id', 'merge_work', ?, 'READY', NULL, NULL)");

	$seq = 0;
	foreach ( $papers AS $work_qid ) {
		$seq += 1;
		$add_command->bind_param('is', $seq, $work_qid);
		$add_command->execute();
	}
	$add_command->close();

	$batch = new Batch($batch_id);
	$batch->load($db_conn);

	$db_conn->close();

	if ($seq > 0) { // Don't bother to start if no commands to run
		$batch->start($oauth);
		sleep(1);
	}
	header("Location: batches_oauth.php?id=$batch_id");
	exit ( 0 ) ;
}

$filter_in_context = ((! isset($filter)) || ($filter == '')) ? '.' : "; $filter . ";
$sparql = "SELECT ?q { ?q wdt:P50 wd:$author_qid $filter_in_context } LIMIT $article_limit" ;
$items_papers = getSPARQLitems ( $sparql, $use_scholarly_subgraph ) ;
$limit_reached = (count($items_papers) == $article_limit) ;


// Load items
$to_load = array() ;
$to_load[] = $author_qid ;
$wil->loadItems ( $to_load ) ;

$article_items = generate_article_entries2( $items_papers, $use_scholarly_subgraph );

# Just need labels for the following:
$qids_to_label = array();
foreach ( $article_items AS $article ) {
	foreach ( $article->authors AS $auth_list ) {
		foreach ( $auth_list AS $auth ) {
			$qids_to_label[$auth] = 1 ;
		}
	}
	foreach ( $article->published_in AS $pub ) $qids_to_label[$pub] = 1 ;
	foreach ( $article->topics AS $topic ) $qids_to_label[$topic] = 1 ;
}
$qid_labels = AuthorData::labelsForItems(array_keys($qids_to_label));

usort( $article_items, 'WikidataArticleEntry2::dateCompare' ) ;

$author_item = $wil->getItem ( $author_qid ) ;
if ( !isset($author_item) )  {
	print "<h2>Warning: $author_qid not found!</h2>" ;
	print_footer() ;
	exit ( 0 ) ;
}

$author_data = new AuthorData($author_item);
$author_alias_names = $author_item->getAliases ( 'en' );
$stated_as_list = fetch_stated_as_for_authors( [ $author_qid ], $use_scholarly_subgraph );
$author_stated_as = [];
if ( isset( $stated_as_list[ $author_qid ] ) ) {
	$author_stated_as = $stated_as_list[ $author_qid ];
}

print "<h2>" . $author_data->label. "</h2>" ;
print "<div>" . $author_data->desc  . " -- ";
$identifier_display = [];
foreach ( $author_data->identifiers AS $id_prop => $id_value ) {
	$id_prop_details = $identifier_details[$id_prop];
	$prop_label = $id_prop_details['label'];
	$prop_url = $id_prop_details['url_prefix'] . $id_value;
	if ( $prop_label == 'ISNI' ) {
		$prop_url = $id_prop_details['url_prefix'] .
			preg_replace( '/\s+/', '', $id_value );
	}
	$identifier_display[] = "$prop_label: <a target='_blank' href='$prop_url'>$id_value</a>" ;
}
print implode("|", $identifier_display); 
print "</div>" ;
print wikidata_link($author_qid, "Wikidata Item", '') ;
print ' | ' ;
print "<a target='_blank' href='https://scholia.toolforge.org/author/$author_qid'>Scholia Profile</a>" ;
print " [<a target='_blank' href='https://scholia.toolforge.org/author/$author_qid/curation'>curation</a>]" ;
print ' | ' ;
print "<a target='_blank' href='$reasonator_prefix$author_qid'>Reasonator</a>" ;
print ' | ' ;
print "<a target='_blank' href='$sqid_prefix$author_qid'>SQID</a>" ;

if (count($author_alias_names) > 0) {
	$alias_links = [];
        foreach ($author_alias_names AS $name) {
		$alias_links[] = "<a href='names_oauth.php?limit=50&name=" . urlencode($name) . "'>$name</a>";
        }
	print "<div>Aliases: " . implode(", ", $alias_links) . "</div>";
}
if (count($author_stated_as) > 0) {
	$stated_as_links = [];
        foreach ($author_stated_as AS $name) {
		$stated_as_links[] = "<a href='?id=$author_qid&filter=" . urlencode("p:P50 [ps:P50 wd:$author_qid; pq:P1932 '$name']") . "'>$name</a>";
        }
	print "<div>Stated as: " . implode(", ", $stated_as_links) . "</div>";
}

$names_to_query[$author_data->label] = 1;
foreach ($author_alias_names AS $name) {
    $names_to_query[$name] = 1;
}
foreach ($author_stated_as AS $name) {
    $names_to_query[$name] = 1;
}
print "<form action='names_oauth.php' method='post' class='form form-inline'>";
print "<input type='hidden' name='name' value='" . $author_data->label . "'>";
print "<input type='hidden' name='use_name_strings' value='1'>";
print "<input type='hidden' name='name_strings' value='" . implode("\n", array_keys($names_to_query)) . "'>";
print "Find unmatched papers using these name variants as author strings: ";
print "<div style='margin:10px'><input type='submit' class='btn btn-primary' name='doit' value='Search' /></div></form>";
print '</div>' ;

print "<form method='post' class='form' target='_blank' action='?'>" ;
if ($merge) {
	print "<input type='hidden' name='action' value='merge' />" ;
} else {
	print "<input type='hidden' name='action' value='remove' />" ;
}
print "<input type='hidden' name='id' value='$author_qid' />" ;

// Publications
$name_counter = array() ;
$author_qid_counter = array() ;
$venue_counter = array() ;
$topic_counter = array() ;
print "<h2>Listed Publications</h2>" ;
if ( $limit_reached ) {
	print "<div><b>Warning:</b> limit reached; query again or adjust the limit parameter if you need to see more papers from this author.</div>" ;
}
print "<p>" . count($article_items) . " publications found</p>" ;

# Fetch 'stated as' values for all identified authors on all articles:
$author_qid_map = array();
foreach ($article_items AS $article) {
	foreach ( $article->authors as $author_qid_list ) {
		foreach ($author_qid_list as $qid) {
			$author_qid_map[$qid] = 1;
		}
	}
}
$author_qids = array_keys($author_qid_map);
$stated_as_names = fetch_stated_as_for_authors($author_qids, $use_scholarly_subgraph);
if ($merge) {
	$wil->loadItems ( $author_qids ) ;
}

print "<div class='group'>" ;
?>
<div>
<a href='#' onclick='$($(this).parents("div.group")).find("input[type=checkbox]").prop("checked",true);return false'>Check all</a> | 
<a href='#' onclick='$($(this).parents("div.group")).find("input[type=checkbox]").prop("checked",false);return false'>Uncheck all</a>
</div>
<?PHP
print "<table class='table table-striped table-condensed'>" ;
print "<tbody>" ;
print "<tr><th></th><th>Title</th>" ;
print "<th>Authors (<span style='color:green'>identified</span>)</th>" ;
print "<th>Published In</th><th>Identifier(s)</th>" ;
print "<th>Topic</th><th>Published Date</th></tr>" ;
foreach ( $article_items AS $article ) {
	if ($merge) {
		$merge_candidates = $article->merge_candidates($wil, $stated_as_names);
	}
	$q = $article->q ;

	$author_stats = $article->author_statistics();
	$id_count = $author_stats['identified_count'];
	$max_num = $author_stats['max_num'];
	$identified_pct = '';
	if (intval($max_num) > 0) {
		$identified_pct = $id_count*100.0/intval($max_num);
	}
	$author_id_pct = sprintf("%d/%d (%.2f%%) identified", $id_count, $max_num, $identified_pct);

	$formatted_authors = array();
	foreach ( $article->author_names AS $num => $a_list ) {
		$formatted_authors[$num] = [];
		foreach ( $a_list AS $id => $a ) {
			$formatted_authors[$num][$id] = "<a href='names_oauth.php?limit=50&name=" . urlencode($a) . "'>$a</a>" ;
			$name_counter[$a] = isset($name_counter[$a]) ? $name_counter[$a]+1 : 1 ;
		}
	}

	$highlighted_authors = array();
	foreach ( $article->authors AS $num => $qt_list ) {
	    if (! isset($formatted_authors[$num])) {
		$formatted_authors[$num] = [];
	    }
	    foreach ( $qt_list AS $id => $qt ) {
		$label = $qid_labels[$qt];
		if ( $qt == $author_qid ) {
			$formatted_authors[$num][$id] = "<b>$label</b>" ;
			$highlighted_authors[] = $num ;
		} else {
			$author_qid_counter[$qt] = isset($author_qid_counter[$qt]) ? $author_qid_counter[$qt]+1 : 1 ;
			$formatted_authors[$num][$id] = "<a href='?limit=50&id=$qt' style='color:green'>$label</a>" ;
		}
	    }
	}
	ksort($formatted_authors);
	$display_authors = [];
	$merge_count = 0;
	foreach ( $formatted_authors AS $num => $display_list ) {
		$display_authors[$num] = "[$num] " . implode ( '|', $display_list) ;
		if ($merge) {
			if ( $merge_candidates[$num] ) {
				$merge_count += 1;
				$highlighted_authors[] = $num ;
				$display_authors[$num] .= " <span style='color:dark green'>(merge)</span>";
			} else if (count($display_list) > 1) {
				$highlighted_authors[] = $num ;
				$display_authors[$num] .= " <span style='color:red'>(mismatch!)</span>";
			}
		}
	}
	$authors_list = implode ( ', ' , compress_display_list($display_authors, $highlighted_authors, 20, 10, 2)) ;

	$published_in = array() ;
	foreach ( $article->published_in AS $qt ) {
		$label = $qid_labels[$qt];
		$venue_counter[$qt] = isset($venue_counter[$qt]) ? $venue_counter[$qt]+1 : 1 ;
		$published_in[] = wikidata_link($qt, $label, 'black') . "&nbsp;[<a href='https://scholia.toolforge.org/venue/$qt/curation' target='_blank'>curation</a>]" ;
	}
	$published_in_list = implode ( ', ', $published_in ) ;
	
	print "<tr>" ;
	if ($merge) {
		if ($merge_count > 0) {
			print "<td><input type='checkbox' name='papers[$q]' value='$q'/></td>" ;
		} else {
			print "<td></td>" ;
		}
	} else {
		print "<td><input type='checkbox' name='papers[$q]' value='$q'/></td>" ;
	}
	print "<td style='width:20%;font-size:10pt'><a href='work_item_oauth.php?id=$q'>$article->title</a></td>" ;
	print "<td style='width:50%;font-size:9pt'>$authors_list - $author_id_pct</td>";
	print "<td style='font-size:9pt'>$published_in_list</td>" ;
	print "<td style='font-size:9pt'>" ;
	if ( $article->doi != '' ) {
		print "DOI: <a target='_blank' href='https://doi.org/$article->doi'>$article->doi</a><br/>" ;
	}
	if ( $article->pmid != '' ) {
		print "PubMed: <a target='_blank' href='https://www.ncbi.nlm.nih.gov/pubmed/?term=$article->pmid'>$article->pmid</a>" ;
	}
	print "</td>" ;
	print "<td style='font-size:9pt'>" ;
	if ( count($article->topics) > 0 ) {
		$topics = [] ;
		foreach ( $article->topics AS $qt ) {
			$label = $qid_labels[$qt];
			$topic_counter[$qt] = isset($topic_counter[$qt]) ? $topic_counter[$qt]+1 : 1 ;
			$topics[] = wikidata_link($qt, $label, 'brown') . "&nbsp;[<a href='https://scholia.toolforge.org/topic/$qt/curation' target='_blank'>curation</a>]" ;
		}
		print implode ( '; ' , $topics ) ;
	}
	print "</td>" ;
	print "<td style='font-size:9pt'>" ;
	print $article->formattedPublicationDate () ;
	print "</td>" ;
	print "</tr>" ;
}
print "</tbody></table></div>" ;

if ($merge) {
	print "<div style='margin:20px'><input type='submit' name='doit' value='Merge redundant author statements on selected articles' class='btn btn-primary' /></div>" ;
} else {
	print "<div><input type='radio' name='author_match' value='manual' /><span style='display:inline-block; width:200px'><input type='text' name='new_author_q' placeholder='Qxxx' /></span>Correct Q number of author item for selected works.</div>" ;
	print "<div><input type='radio' name='author_match' value='none' checked /> NO replacement author: revert to author name strings</div>" ;
	print "<div style='margin:20px'><input type='submit' name='doit' value='REMOVE selected works from this author' class='btn btn-primary' /></div>" ;
}
print "</form>";

arsort ( $author_qid_counter, SORT_NUMERIC ) ;
print "<h2>Common author items in these papers</h2>" ;
print "<ul>" ;
foreach ( $author_qid_counter AS $qt => $cnt ) {
	$label = $qid_labels[$qt];
	print "<li><a href='?limit=50&id=$qt' style='color:green'>$label</a> (<a href='?limit=$article_limit&id=$author_qid&filter=wdt%3AP50+wd%3A$qt'>$cnt&times;</a>) - <a href='match_multi_authors.php?limit=50&id=$author_qid+$qt'>Unmatched with both names</a> - <a href='https://scholia.toolforge.org/authors/$author_qid,$qt'>Scholia comparison</a></li>" ;
}
print "</ul>" ;

arsort ( $name_counter , SORT_NUMERIC ) ;
print "<h2>Common author name strings in these papers</h2>" ;
print "<ul>" ;
foreach ( $name_counter AS $a => $cnt ) {
	if ( $cnt == 1 ) break ;
	print "<li><a href='names_oauth.php?limit=50&name=" . urlencode($a) . "'>$a</a> (<a href='names_oauth.php?limit=50&name=" . urlencode($a) . "&filter=wdt%3AP50+wd%3A$author_qid'>$cnt&times;</a>)</li>" ;
}
print "</ul>" ;

arsort ( $venue_counter , SORT_NUMERIC ) ;
print "<h2>Publishing venues for these papers</h2>" ;
print "<ul>" ;
foreach ( $venue_counter AS $qt => $cnt ) {
	$label = $qid_labels[$qt];
	print "<li>" . wikidata_link($qt, $label, 'black') . " (<a href='?limit=$article_limit&id=$author_qid&filter=wdt%3A$published_in_prop_id+wd%3A$qt'>$cnt&times;</a>)</li>" ;
}
print "</ul>" ;

arsort ( $topic_counter , SORT_NUMERIC ) ;
print "<h2>Topics for these papers</h2>" ;
print "<ul>" ;
foreach ( $topic_counter AS $qt => $cnt ) {
	$label = $qid_labels[$qt];
	print "<li>" . wikidata_link($qt, $label, 'brown') . " (<a href='?limit=$article_limit&id=$author_qid&filter=wdt%3A$topic_prop_id+wd%3A$qt'>$cnt&times;</a>)</li>" ;
}
print "</ul>" ;


print_footer() ;

?>
