<?PHP

require_once ( __DIR__ . '/lib/initialize.php' ) ;
require_once ( __DIR__ . '/lib/wikidata_oauth.php' );

$oauth = new WD_OAuth('author-disambiguator', '/var/www/html/oauth.ini');

$action = get_request ( 'action' , '' ) ;
$work_qid = get_request( 'id', '' ) ;
$renumber = get_request ( 'renumber' , 0 ) ;
$match  = get_request ( 'match' , 0 ) ;
$renumber = $match ? 0: $renumber ; # Supercede renumbering if match selected
$renumber_checked = $renumber ? 'checked' : '' ;
$match_checked = $match ? 'checked' : '' ;

if ($action == 'authorize') {
	$oauth->doAuthorizationRedirect('https://localhost/author-disambiguator/work_item_oauth.php');
	exit(0);
}

print get_common_header ( '' , 'Author Disambiguator' ) ;

if ($oauth->isAuthOK()) {
	print "Wikimedia user account: " . $oauth->userinfo->name ;
} else {
	print "You haven't authorized this application yet: click <a href='?action=authorize'>here</a> to do that, then reload this page.";
}
print "<hr>";

print "<form method='get' class='form form-inline'>
Work Wikidata ID: 
<input name='id' value='" . escape_attribute($work_qid) . "' type='text' placeholder='Qxxxxx' />
<label style='margin:10px'><input type='checkbox' name='renumber' value='1' $renumber_checked />Renumber authors?</label>
<label style='margin:10px'><input type='checkbox' name='match' value='1' $match_checked />Suggest matches?</label>
<input type='submit' class='btn btn-primary' name='doit' value='Get author links for work' />
</form>" ;

if ( $work_qid == '' ) {
	print_footer() ;
	exit ( 0 ) ;
}

$wil = new WikidataItemList ;

if ( $action == 'merge' ) {
	$author_numbers = get_request ( 'merges' , array() ) ;
	$remove_claims = get_request ( 'remove_claims' , array() ) ;

	$result = $oauth->merge_authors( $work_qid, $author_numbers, $remove_claims, "Author Disambiguator merge authors for $work_qid" ) ;
	if ($result) {
		print "Merging successful!";
	} else {
		print "Something went wrong? ";
		print_r($oauth->error);
	}
}

if ($action == 'renumber') {
	$renumbering = get_request ( 'ordinals' , array() ) ;
	$remove_claims = get_request ( 'remove_claims' , array() ) ;

	$result = $oauth->renumber_authors( $work_qid, $renumbering, $remove_claims, "Author Disambiguator renumber authors for $work_qid" ) ;
	if ($result) {
		print "Renumbering successful!";
	} else {
		print "Something went wrong? ";
		print_r($oauth->error);
	}
}

if ( $action == 'match' ) {
	$matches = get_request ( 'match_author' , array() ) ;

	$result = $oauth->match_authors( $work_qid, $matches, "Author Disambiguator matching authors for $work_qid" ) ;
	if ($result) {
		print "Matching successful!";
	} else {
		print "Something went wrong? ";
		print_r($oauth->error);
	}
}

$article_entry = generate_article_entries2( [$work_qid] ) [ $work_qid ];

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

# Regenerate article entry directly from item:
$article_entry = new WikidataArticleEntry2( $work_item );

if ( !isset($work_item) )  {
	print "<h2>Warning: $work_qid not found!</h2>" ;
	print_footer() ;
	exit ( 0 ) ;
}

print "<h2>" . $work_item->getLabel() . "</h2>" ;
print "<div>" ;
print wikidata_link($work_qid, "Wikidata Item", '') ;
print ' | ' ;
print "<a target='_blank' href='https://tools.wmflabs.org/scholia/work/$work_qid'>Scholia Work Page</a>" ;
print ' | ' ;
print "<a target='_blank' href='https://tools.wmflabs.org/reasonator/?q=$work_qid'>Reasonator</a>" ;
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
	if ( isset($i2) ) $published_in[] = wikidata_link($i2->getQ(), $i2->getLabel(), 'black') . "&nbsp;[<a href='https://tools.wmflabs.org/scholia/venue/" . $i2->getQ() . "/missing' target='_blank'>missing</a>]" ;
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
		$topics[] = wikidata_link($i2->getQ(), $i2->getLabel(), 'brown') . "&nbsp;[<a href='https://tools.wmflabs.org/scholia/topic/" . $i2->getQ() . "/missing' target='_blank'>missing</a>]" ;
	}
	print implode ( '; ' , $topics ) ;
}
print "</div>" ;

# Fetch 'stated as' values for all identified authors:
$author_qid_map = array();
foreach ( $article_entry->authors as $author_qid_list ) {
	foreach ($author_qid_list as $qid) {
		$author_qid_map[$qid] = 1;
	}
}
$author_qids = array_keys($author_qid_map);
if ($match) {
	$related_authors = fetch_related_authors($work_qid, $author_qids);
	$stated_as_names = fetch_stated_as_for_authors($related_authors);
	$wil->loadItems ( $related_authors ) ;
	$match_candidates = $article_entry->match_candidates($wil, $related_authors, $stated_as_names);
	$items_authors = array();
	foreach ($match_candidates AS $author_qids) {
		$items_authors = array_merge($items_authors, $author_qids);
	}
	$match_candidate_data = AuthorData::authorDataFromItems( $items_authors, $wil ) ;
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
		$formatted_authors[$num][$id] = "<a href='index.php?limit=50&name=" . urlencode($a) . "'>$a</a>" ;
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
		$formatted_authors[$num][$id] = "<a href='author_item.php?limit=50&id=" . $i2->getQ() . "' style='color:green'>$label</a>" ;
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
		print "[$num]";
		print implode ( '|', $display_list) . '</td><td>';
		if ($match) {
			$match_rows = array() ;
			if (isset($match_candidates[$num])) {
				foreach ($match_candidates[$num] AS $match_qid) {
					$i = $wil->getItem ( $match_qid ) ;
					if ( !isset($i) ) continue ;
					$author_data = $match_candidate_data[$match_qid];
					$row_data = array();
					$row_data[] = "<input type='checkbox' name='match_author[$match_qid:$num]' value='$match_qid:$num' /><a href='author_item.php?id=" . $i->getQ() . "' target='_blank' style='color:green'>" . $i->getLabel() . "</a>" ;
					$row_data[] = $i->getDesc();
					$row_data[] = $author_data->article_count ;
					$employers = array();
					foreach ( $author_data->employer_qids AS $emp_qid ) {
						$emp_item = $wil->getItem ( $emp_qid ) ;
						if ( !isset($emp_item) ) continue ;
						$employers[] = wikidata_link($emp_qid, $emp_item->getLabel(), '') ;
					}
					$row_data[] = implode("|", $employers) ;
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
	print "<div style='margin:20px'><input type='submit' name='match' value='Match selected authors' class='btn btn-primary' /> </div>";
}
print "</form>" ;

print_footer() ;

?>
