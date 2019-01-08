<?PHP

// Various display handlers
function wikidata_link ( $q, $text, $color ) {
	$style = '';
	if ( $color != '' ) {
		$style = "style='color:$color'" ;
	}
	return "<a href='//www.wikidata.org/wiki/$q' target='_blank' $style'>$text</a>" ;
}

function getORCIDurl ( $s ) {
	return "https://orcid.org/orcid-search/quick-search?searchQuery=%22" . urlencode($s) . "%22" ;
}

function print_footer () {
	print "<hr/><a href='https://github.com/arthurpsmith/author-disambiguator/issues' target='_blank'>Feedback</a><br/><a href='https://github.com/arthurpsmith/author-disambiguator/'>Source and documentation (at github)</a><br/>" ;
	print get_common_footer() ;
}

?>
