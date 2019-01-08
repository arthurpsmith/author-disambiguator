<?PHP

// Quickstatements V1 commands for creating new author item:
function new_author_qs_commands ( $name, $orcid_author, $viaf_author ) {
	$commands = array() ;
	$commands[] = "CREATE" ;
	$commands[] = "LAST\tLen\t\"$name\""  ;
	$commands[] = "LAST\tP31\tQ5"  ;
	if ( $orcid_author != '' ) $commands[] = "LAST\tP496\t\"$orcid_author\"" ;
	if ( $viaf_author != '' ) $commands[] = "LAST\tP214\t\"$viaf_author\"" ;
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
			$add = "$paperq\tP50\t$author_q" ;

			$quals = $i->statementQualifiersToQS ( $a ) ;
			if (count($quals) > 0) {
				$add = $add . "\t" . implode("\t", $quals) ;
			}
			
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
			$quals = $i->statementQualifiersToQS ( $a ) ;
			$name = "" ;
			$name_qual_index = -1 ;
			foreach ( $quals AS $index => $qual ) {
				$matches = array();
				if (preg_match('/^P1932\t"(.*)"$/', $qual, $matches)) {
					$name = $matches[1];
					$name_qual_index = $index ;
				}
			}
			if ( $name_qual_index >= 0 ) {
				unset($quals[$name_qual_index]); // Remove stated as from qualifier list
			}
			if ( $name == '' ) {
				$name = $author_item->getLabel() ;
			}
			if ($name == '' ) {
				print 'Warning: null author name label; skipping' ;
				continue;
			}
			$add = "$paperq\tP2093\t\"$name\"" ;
			if (count($quals) > 0) {
				$add = $add . "\t" . implode("\t", $quals) ;
			}
			
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

// Quickstatements V1 commands to move author item to a different author:
function move_authors_qs_commands ( $wil, $papers, $author_q, $new_author_q ) {
	$commands = array() ;
	$author_item = $wil->getItem ( $author_q ) ;
	foreach ( $papers AS $paperq ) {
		$i = $wil->getItem ( $paperq ) ;
		if ( !isset($i) ) continue ;
		$authors = $i->getClaims ( 'P50' ) ;
		foreach ( $authors AS $a ) {
			$q = $i->getTarget ( $a ) ;
			if ($q != $author_q) continue;
			$add = "$paperq\tP50\t$new_author_q";

			$quals = $i->statementQualifiersToQS ( $a ) ;
			if (count($quals) > 0) {
				$add = $add . "\t" . implode("\t", $quals) ;
			}
			
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
