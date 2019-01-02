<?PHP

// Quickstatements V1 commands for creating new author item:
function new_author_qs_commands ( $name, $orcid_author ) {
	$commands = array() ;
	$commands[] = "CREATE" ;
	$commands[] = "LAST\tLen\t\"$name\""  ;
	$commands[] = "LAST\tP31\tQ5"  ;
	if ( $orcid_author != '' ) $commands[] = "LAST\tP496\t\"$orcid_author\"" ;
	return $commands ;
}


// Quickstatements V1 commands for replacing author name strings with author items:
function replace_authors_qs_commands ( $wil, $papers, $names, $author_q ) {
	$commands = array() ;
	foreach ( $papers AS $paperq ) {
		$i = $wil->getItem ( $paperq ) ;
		if ( !isset($i) ) continue ;
		$authors = $i->getClaims ( 'P2093' ) ;
		foreach ( $authors AS $a ) {
			if ( !isset($a->mainsnak) or !isset($a->mainsnak->datavalue) ) continue ;
			$author_name = $a->mainsnak->datavalue->value ;
			if ( !in_array ( $author_name , $names ) ) continue ;
			$num = '' ;
			if ( isset($a->qualifiers) and isset($a->qualifiers->P1545) ) {
				$tmp = $a->qualifiers->P1545 ;
				$num = $tmp[0]->datavalue->value ;
			}
			$add = "$paperq\tP50\t$author_q" ;
			if ( $num != "" ) $add .= "\tP1545\t\"$num\"" ;
			
			$add .= "\tP1932\t\"$author_name\"" ;
			$refs = $i->statementReferencesToQS( $a ) ;
			if ( count($refs) > 0 ) {
				foreach ( $refs AS $ref ) {
					$commands[] = $add . "\t" . implode("\t", $ref) ;
				}
			} else {
				$commands[] = $add ;
			}
			
			$commands[] = "-STATEMENT\t" . $a->id ;
		}
	}
	return $commands ;
}

// Commands for reverting author name to author name strings (when assigned to wrong author item):
function revert_authors_qs_commands ( $wil, $papers, $author_q ) {
	$commands = array() ;
	$author_item = $wil->getItem ( $author_q ) ;
	foreach ( $papers AS $paperq ) {
		$i = $wil->getItem ( $paperq ) ;
		if ( !isset($i) ) continue ;
		$authors = $i->getClaims ( 'P50' ) ;
		foreach ( $authors AS $a ) {
			$q = $i->getTarget ( $a ) ;
			if ($q != $author_q) continue;
			$num = "" ;
			$name = "" ;
			if ( isset($a->qualifiers) ) {
				if ( isset($a->qualifiers->P1545) ) {
					$tmp = $a->qualifiers->P1545 ;
					$num = $tmp[0]->datavalue->value ;
				}
				if ( isset($a->qualifiers->P1932) ) {
					$tmp = $a->qualifiers->P1932 ;
					$name = $tmp[0]->datavalue->value ;
				}
			}
			if ( $name == '' ) {
				$name = $author_item->getLabel() ;
			}
			if ($name == '' ) {
				print 'Warning: null author name label; skipping' ;
				continue;
			}
			$add = "$paperq\tP2093\t\"$name\"" ;
			if ( $num != "" ) $add .= "\tP1545\t\"$num\"" ;
			
			$refs = $i->statementReferencesToQS( $a ) ;
			if ( count($refs) > 0 ) {
				foreach ( $refs AS $ref ) {
					$commands[] = $add . "\t" . implode("\t", $ref) ;
				}
			} else {
				$commands[] = $add ;
			}
			
			$commands[] = "-STATEMENT\t" . $a->id ;
		}
	}
	return $commands ;
}

?>
