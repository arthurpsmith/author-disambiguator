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
	return "https://orcid.org/orcid-search/quick-search?searchQuery=" . urlencode($s) ;
}

function print_footer () {
	print "<hr/><a href='https://github.com/arthurpsmith/author-disambiguator/issues' target='_blank'>Feedback</a><br/><a href='https://github.com/arthurpsmith/author-disambiguator/'>Source and documentation (at github)</a><br/>" ;
	print get_common_footer() ;
}

function compress_display_list($list, $highlights, $total_limit, $limit_first, $limit_nbr) {
	if (count($list) <= $total_limit) return $list ;
	$compressed_list = array();

        $int_highlights = array();
	foreach ($highlights as $hl_index) {
                $hl_value = $hl_index ;
		if (! is_numeric($hl_index) ) {
		    $hl_value = preg_replace('/-.*$/', '', $hl_index);
		}
		$int_highlights[] = $hl_value ;
        }
	foreach ($list AS $index => $item) {
		if ($index <= $limit_first) {
			$compressed_list[] = $item;
			continue;
		}
		$in_highlight = 0;
		foreach ($int_highlights as $hl_index) {
			if (($index >= $hl_index - $limit_nbr) && ($index <= $hl_index + $limit_nbr)) {
				$compressed_list[] = $item ;
				$in_highlight = 1;
				break(1) ;
			}
		}
		if ($in_highlight == 0) {
			if ($compressed_list[count($compressed_list) - 1] != '...') $compressed_list[] = '...' ;
		}
	}
	return $compressed_list ;
}

?>
