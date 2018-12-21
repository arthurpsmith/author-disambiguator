<?PHP

// error_reporting(E_ERROR|E_CORE_ERROR|E_COMPILE_ERROR); // E_ALL|
error_reporting(E_ALL);
ini_set('display_errors', 'On');
ini_set('memory_limit','1500M');
set_time_limit ( 60 * 10 ) ; // Seconds

require_once ( __DIR__ . '/magnustools/common.php' ) ;
require_once ( __DIR__ . '/magnustools/wikidata.php' ) ;
require_once ( __DIR__ . '/lib/article_model.php' ) ;
require_once ( __DIR__ . '/lib/cluster.php' ) ;
require_once ( __DIR__ . '/lib/clustering.php' ) ;
require_once ( __DIR__ . '/lib/qs_commands.php' ) ;
require_once ( __DIR__ . '/lib/author_data.php' ) ;

function getORCIDurl ( $s ) {
	return "https://orcid.org/orcid-search/quick-search?searchQuery=%22" . urlencode($s) . "%22" ;
}

function print_footer () {
	print "<hr/><a href='https://github.com/arthurpsmith/author-disambiguator/issues' target='_blank'>Feedback</a><br/><a href='https://github.com/arthurpsmith/author-disambiguator/'>Source and documentation (at github)</a><br/>" ;
	print get_common_footer() ;
}

$action = get_request ( 'action' , '' ) ;
$name = trim ( str_replace ( '_' , ' ' , get_request ( 'name' , '' ) ) ) ;
$fuzzy = get_request ( 'fuzzy' , 0 ) * 1 ;
$fuzzy_checked = $fuzzy ? 'checked' : '' ;

print get_common_header ( '' , 'Author Disambiguator' ) ;

print "<form method='get' class='form form-inline'>
Author name: 
<input name='name' value='" . escape_attribute($name) . "' type='text' placeholder='First Last' />
<label><input type='checkbox' name='fuzzy' value='1' $fuzzy_checked /> Fuzzy match</label>
<input type='submit' class='btn btn-primary' name='doit' value='Look for author' />
</form>" ;

if ( $name == '' ) {
	print_footer() ;
	exit ( 0 ) ;
}


// Publications
$names = array ( $name ) ;
if ( $fuzzy ) {
	$names[] = preg_replace ( '/^([A-Z])\S+.*\s(\S+)$/' , '$1 $2' , $name ) ;
	$names[] = preg_replace ( '/^([A-Z][a-z]+).*\s(\S+)$/' , '$1 $2' , $name ) ;
}
$names_strings = '"' . implode ( '" "' , $names ) . '"' ;
#print "$names_strings" ;
$sparql = "SELECT ?q { VALUES ?name { $names_strings } . ?q wdt:P2093 ?name } LIMIT 900" ;
$items_papers = getSPARQLitems ( $sparql ) ;

// Potential authors
$no_middle = preg_replace('/\s*[A-Z]\. /',' ',$name) ;
#print "<pre>$no_middle</pre>" ;
$url = "https://www.wikidata.org/w/api.php?action=query&list=search&srlimit=500&format=json&srsearch=" . urlencode($no_middle) ;
#print "<pre>$url</pre>" ;
$j = json_decode ( file_get_contents ( $url ) ) ;
#print "<pre>" ; print_r ( $j ) ; print "</pre>" ;
$items_authors = array() ;
foreach ( $j->query->search AS $a ) $items_authors[] = "wd:" . $a->title ;
if (count($items_authors) == 500) {
	print "<div><b>Warning:</b> common name - some Wikidata items may be missing from the potential authors list below</div>";
} ;
$sparql = "SELECT ?q { VALUES ?q { " . implode ( ' ' , $items_authors ) . " } . ?q wdt:P31 wd:Q5 }" ;
$items_individual_authors = getSPARQLitems ( $sparql ) ;
$sparql = "SELECT ?q { VALUES ?q { " . implode ( ' ' , $items_authors ) . " } . ?q wdt:P31/wdt:P279* wd:Q16334295 }" ;
$items_collective_authors = getSPARQLitems ( $sparql ) ;

$items_authors = array_merge( $items_individual_authors, $items_collective_authors ) ;

// Load items
$wil = new WikidataItemList ;
$to_load = array() ;
foreach ( $items_papers AS $q ) $to_load[] = $q ;
foreach ( $items_authors AS $q ) $to_load[] = $q ;
$wil->loadItems ( $to_load ) ;

$potential_author_data = AuthorData::authorDataFromItems( $items_authors, $wil ) ;
$to_load = array() ;
foreach ($potential_author_data AS $author_data) {
	foreach ($author_data->employer_qids as $q) $to_load[] = $q ;
}
$wil->loadItems ( $to_load ) ;

$delete_statements = array() ;
if ( $action == 'add' ) {
	print "<form method='post' class='form' action='https://tools.wmflabs.org/quickstatements/api.php'>" ;
	print "<input type='hidden' name='action' value='import' />" ;
	print "<input type='hidden' name='temporary' value='1' />" ;
	print "<input type='hidden' name='openpage' value='1' />" ;
	$orcid_author = trim ( get_request ( 'orcid_author' , '' ) ) ;
	$author_match = trim ( get_request ( 'author_match' , '' ) ) ;
	$author_q = trim ( get_request ( 'q_author' , '' ) ) ;
	if ( $author_q == '' ) $author_q = $author_match ;
	$papers = get_request ( 'papers' , array() ) ;

	if ( $author_match == 'new' ) {
		print "<br/>Quickstatements V1 commands for creating new author item:" ;
		$commands = new_author_qs_commands ( $name, $orcid_author ) ;
		print "<textarea name='data' rows=5>" . implode("\n",$commands) . "</textarea>" ;
		print "<input type='submit' class='btn btn-primary' name='qs' value='Send to Quickstatements' /><br/>" ;
		print "Run these and then use the resulting author item ID (Qxx) in further work." ;
		print "</form>" ;
		exit ( 0 ) ;
	}
	if ( $author_q == '' ) {
		print "Sorry, can't find author" ;
		exit ( 0 ) ;
	}

	$commands = replace_authors_qs_commands ( $wil, $papers, $names, $author_q ) ;

	print "Quickstatements V1 commands for replacing author name strings with author item:" ;
	print "<textarea name='data' rows=20>" . implode("\n",$commands) . "</textarea>" ;
	print "<input type='submit' class='btn btn-primary' name='qs' value='Send to Quickstatements' />" ;
	print "</form>" ;
	
	exit ( 0 ) ;
}


print "<form method='post' class='form' target='_blank' action='?'>
<input type='hidden' name='action' value='add' />
<input type='hidden' name='fuzzy' value='$fuzzy' />
<input type='hidden' name='name' value='" . escape_attribute($name) . "' />" ;

$to_load = array() ;
$article_items = array();
foreach ( $items_papers AS $q ) {
	$i = $wil->getItem ( $q ) ;
	if ( !isset($i) ) continue ;

	$article = new WikidataArticleEntry( $i ) ;
	$article_items[] = $article ;

	foreach ( $article->authors AS $auth ) $to_load[] = $auth ;
	foreach ( $article->published_in AS $pub ) $to_load[] = $pub ;
	foreach ( $article->topics AS $topic ) $to_load[] = $topic ;
}
$wil->loadItems ( $to_load ) ;

$clusters = cluster_articles ( $article_items, $names ) ;

$potential_authors_by_cluster_label = array();
foreach ($clusters AS $label => $cluster ) {
	$potential_authors_by_cluster_label[$label]  = array();
	foreach ( $potential_author_data AS $author_data ) {
		if (author_matches_cluster( $cluster, $author_data, $names )) {
			$potential_authors_by_cluster_label[$label][$author_data->qid] = 1 ;
		}
	}
}

#print "<pre>" ; print_r ( $clusters ) ; print "</pre>" ;
// Publications
$name_counter = array() ;
print "<h2>Potential publications</h2>" ;
print "<p>" . count($article_items) . " publications found</p>" ;
if (count($article_items) == 900) {
	print "<div><b>Warning:</b> limit reached; process these papers and then reload to see if there are more for this author</div>" ;
}

$is_first_group = true ;
foreach ( $clusters AS $cluster_name => $cluster ) {
	print "<div class='group'>" ;
	print "<h3>$cluster_name</h3>" ;
	$potential_authors = array_keys($potential_authors_by_cluster_label[$cluster_name]);
	foreach ( $potential_authors AS $potential_qid ) {
		$author_data = $potential_author_data[$potential_qid] ;
		$potential_item = $wil->getItem ( $potential_qid ) ;
		print "Matched potential author: <a href='author_item.php?id=" . $potential_item->getQ() . "' target='_blank' style='color:green'>" . $potential_item->getLabel() . "</a>" ;
		print " - author of $author_data->article_count items" ;
	}
	if (count($potential_authors) > 1) {
		print "<div><b>Warning:</b> Multiple potential authors match this cluster!</div>" ;
	}
?>
<div>
<a href='#' onclick='$($(this).parents("div.group")).find("input[type=checkbox]").prop("checked",true);return false'>Check all</a> | 
<a href='#' onclick='$($(this).parents("div.group")).find("input[type=checkbox]").prop("checked",false);return false'>Uncheck all</a>
</div>
<?PHP
	print "<table class='table table-striped table-condensed'>" ;
	print "<tbody>" ;
	print "<tr><th></th><th>Title</th>" ;
	print "<th>Author Name Strings</th><th>Identified Authors</th>" ;
	print "<th>Published In</th><th>Identifier(s)</th>" ;
	print "<th>Topic</th><th>Published Date</th></tr>" ;
	foreach ( $cluster->article_list AS $article ) {
		$q = $article->q ;

		$out = array() ;
		foreach ( $article->author_names AS $num => $a ) {
			if ( in_array ( $a , $names ) ) $out[] = "[$num]<b>$a</b>" ;
			else {
				$out[] = "[$num]<a href='?fuzzy=$fuzzy&name=" . urlencode($a) . "'>$a</a>" ;
				$name_counter[$a] = isset($name_counter[$a]) ? $name_counter[$a]+1 : 1 ;
			}
		}
		$author_string_list = implode ( ', ' , $out ) ;
		
		$q_authors = array() ;
		foreach ( $article->authors AS $num => $qt ) {
			$i2 = $wil->getItem ( $qt ) ;
			$q_authors[] = "[$num]<a href='author_item.php?id=" . $i2->getQ() . "' target='_blank' style='color:green'>" . $i2->getLabel() . "</a>" ;
		}
		$author_entity_list = implode ( ', ' , $q_authors ) ;

		$published_in = array() ;
		foreach ( $article->published_in AS $qt ) {
			$i2 = $wil->getItem ( $qt ) ;
			if ( isset($i2) ) $published_in[] = $i2->getLabel() ;
		}
		$published_in_list = implode ( ', ', $published_in ) ;
	
		print "<tr>" ;
		print "<td><input type='checkbox' name='papers[$q]' value='$q' " . ($is_first_group?'checked':'') . " /></td>" ;
		print "<td style='width:20%;font-size:10pt'><a href='//www.wikidata.org/wiki/$q' target='_blank'>" . $article->title . "</a></td>" ;
		print "<td style='width:30%;font-size:9pt'>$author_string_list</td>" ;
		print "<td style='width:30%;font-size:9pt'>$author_entity_list</td>" ;
		print "<td style='font-size:9pt'>$published_in_list</td>" ;
                print "<td style='font-size:9pt'>" ;
		if ( $article->doi != '' ) {
			print "DOI: <a target='_blank' href='https://doi.org//$article->doi'>$article->doi</a>" ;
			print "&nbsp;[<a href='" . getORCIDurl ( $article->doi ) . "'>ORCID</a>]<br/>" ;
		}
		if ( $article->pmid != '' ) {
			print "PubMed: <a target='_blank' href='https://www.ncbi.nlm.nih.gov/pubmed/?term=$article->pmid'>$article->pmid</a>" ;
			print "&nbsp;[<a href='" . getORCIDurl ( $article->pmid ) . "'>ORCID</a>]<br/>" ;
		}
		print "</td>" ;
                print "<td style='font-size:9pt'>" ;
		if ( count($article->topics) > 0 ) {
			$topics = [] ;
			foreach ( $article->topics AS $qt ) {
				$i2 = $wil->getItem($qt) ;
				if ( !isset($i2) ) continue ;
				$topics[] = "<a href='https://www.wikidata.org/wiki/" . $i2->getQ() . "' target='_blank' style='color:red'>" . $i2->getLabel() . "</a>" ;
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
	$is_first_group = false ;
}

// Potential authors
#print "<pre>" ; print_r ( $items_authors ) ; print "</pre>" ;
print "<h2>Potential author items</h2>" ;
print "<table class='table table-striped table-condensed'>" ;
print "<tbody>" ;
print "<tr><th></th><th>Name</th><th>Description</th><th>Authored items</th>" ;
print "<th>Identifiers</th><th>Employer(s)</th></tr>" ;
foreach ( $potential_author_data AS $q => $author_data ) {
	$i = $wil->getItem ( $q ) ;
	if ( !isset($i) ) continue ;
	print "<tr>" ;
	print "<td><input type='radio' name='author_match' value='$q' /></td>" ;
	print "<td><a href='author_item.php?id=" . $i->getQ() . "' target='_blank' style='color:green'>" . $i->getLabel() . "</a></td>" ;
	print "<td>" . $i->getDesc() . "</td>" ;
	print "<td>$author_data->article_count</td>" ;
	print "<td>" ;
	if ( $author_data->orcid != '' ) {
		print "ORCID: <a target='_blank' href='https://orcid.org/$author_data->orcid'>$author_data->orcid</a><br/>" ;
	}
	if ( $author_data->isni != '' ) {
		$isni = preg_replace('/\s+/', '', $author_data->isni) ;
		print "ISNI: <a target='_blank' href='http://isni.org/$isni'>$author_data->isni</a><br/>" ;
	}
	if ( $author_data->rsrchrid != '' ) {
		print "Researcher ID: <a target='_blank' href='https://www.researcherid.com/rid/$author_data->rsrchrid'>$author_data->rsrchrid</a><br/>" ;
	}
	print "</td><td>" ;
	foreach ( $author_data->employer_qids AS $emp_qid ) {
		$emp_item = $wil->getItem ( $emp_qid ) ;
		if ( !isset($emp_item) ) continue ;
		print "<a target='_blank' href='https://wikidata.org/wiki/$emp_qid'>" . $emp_item->getLabel() . "</a><br/>" ;
	}
	print "</td></tr>" ;
}
print "<tr><td><input type='radio' name='author_match' value='manual' checked /></td><td><input type='text' name='q_author' placeholder='Qxxx' /></td><td colspan='4'>Other Q number of this author</td></tr>" ;
print "</tbody></table>" ;

print "<div style='margin:20px'><input type='submit' name='doit' value='Quickstatements to link works to author' class='btn btn-primary' /></div>" ;
print "</form>" ;

print "<h2>New Author Item</h2>" ;
print '(if the author you are looking for is not listed above and otherwise not yet in Wikidata)';

print "<form method='post' class='form form-inline' target='_blank' action='?'>
<input type='hidden' name='action' value='add' />
<input type='hidden' name='author_match' value='new'/>
<div>Author name: <input name='name' value='" . escape_attribute($name) . "' type='text' placeholder='First Last' /></div>";
print "<div><a href='" . getORCIDurl($name) . "' target='_blank'>Check ORCID for $name</a> | Author has ORCID ID: <input type='text' name='orcid_author' placeholder='xxxx-xxxx-xxxx-xxxx' /></div>" ;
print "<div style='margin:20px'><input type='submit' name='doit' value='Quickstatements to create author item' class='btn btn-primary' /></div>" ;
print "</form>" ;
print '<div>After creating the new author item, enter the Wikidata ID in the "Other Q number of this author" field above to link to their works.</div>' ;

arsort ( $name_counter , SORT_NUMERIC ) ;
print "<h2>Common names in these papers</h2>" ;
print "<ul>" ;
foreach ( $name_counter AS $a => $cnt ) {
	if ( $cnt == 1 ) break ;
	print "<li><a href='?fuzzy=$fuzzy&name=" . urlencode($a) . "'>$a</a> ($cnt&times;)</li>" ;
}
print "</ul>" ;

print_footer() ;

?>
