<?PHP

require_once ( __DIR__ . '/lib/initialize.php' ) ;

$action = get_request ( 'action' , '' ) ;
$work_qid = get_request( 'id', '' ) ;

print get_common_header ( '' , 'Author Disambiguator' ) ;

print "<form method='get' class='form form-inline'>
Work Wikidata ID: 
<input name='id' value='" . escape_attribute($work_qid) . "' type='text' placeholder='Qxxxxx' />
<input type='submit' class='btn btn-primary' name='doit' value='Get author links for work' />
</form>" ;

if ( $work_qid == '' ) {
	print_footer() ;
	exit ( 0 ) ;
}

$wil = new WikidataItemList ;

$sparql = "SELECT ?q { wd:$work_qid wdt:P50 ?q }" ;
$items_authors = getSPARQLitems ( $sparql ) ;

// Load items
$to_load = array() ;
$to_load[] = $work_qid ;

foreach ( $items_authors AS $auth ) {
	$to_load[] = $auth ;
}
$to_load = array_unique( $to_load );
$wil->loadItems ( $to_load ) ;

$work_item = $wil->getItem ( $work_qid ) ;
if ( !isset($work_item) )  {
	print "<h2>Warning: $work_qid not found!</h2>" ;
	print_footer() ;
	exit ( 0 ) ;
}

$article_entry = generate_article_entries( [$work_qid] ) [ $work_qid ];

print "<h2>" . $work_item->getLabel() . "</h2>" ;
print "<div>" ;
print wikidata_link($work_qid, "Wikidata Item", '') ;
print ' | ' ;
print "<a target='_blank' href='https://tools.wmflabs.org/scholia/work/$work_qid'>Scholia Work Page</a>" ;
print ' | ' ;
print "<a target='_blank' href='https://tools.wmflabs.org/reasonator/?q=$work_qid'>Reasonator</a>" ;
print '</div>' ;
if ( $article_entry->doi != '' ) {
	print "DOI: <a target='_blank' href='https://doi.org/$article_entry->doi'>$article_entry->doi</a><br/>" ;
}
if ($article_entry->pmid != '' ) {
	print "PubMed: <a target='_blank' href='https://www.ncbi.nlm.nih.gov/pubmed/?term=$article_entry->pmid'>$article_entry->pmid</a>" ;
}

// Author list
$name_counter = array() ;
$author_qid_counter = array() ;
print "<h2>Authors</h2>" ;

print('<ul>');
$formatted_authors = array();
foreach ( $article_entry->author_names AS $num => $a ) {
	$formatted_authors[$num] = "[$num]<a href='index.php?limit=50&name=" . urlencode($a) . "'>$a</a>" ;
}
foreach ( $article_entry->authors AS $num => $qt ) {
	$i2 = $wil->getItem ( $qt ) ;
	$label = $i2->getLabel() ;
	$display_num = $num ;
	if (isset($formatted_authors[$num])) {
		$display_num = "$num-$qt";
	}
	$formatted_authors[$display_num] = "[$display_num]<a href='author_item.php?limit=50&id=" . $i2->getQ() . "' style='color:green'>$label</a>" ;
}

ksort($formatted_authors);

foreach ( $formatted_authors AS $num => $display_line ) {
	print "<li>$display_line</li>";
}
print "</ul>" ;

print_footer() ;

?>
