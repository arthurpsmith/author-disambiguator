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

function get_author_statement_guid($paperq, $author_num, $names) {
	$sparql = "SELECT ?author_name ?statement WHERE { wd:$paperq p:P2093 ?statement . ?statement ps:P2093 ?author_name ; pq:P1545 '$author_num' . }" ;
	$result = getSPARQL( $sparql ) ;
	$bindings = $result->results->bindings ;
	if (count($bindings) == 0) {
		$author_names_strings = '"' . implode ( '" "' , $names ) . '"' ;
		$sparql = "SELECT ?author_name ?statement WHERE { VALUES ?author_name { $author_names_strings } . wd:$paperq p:P2093 ?statement . ?statement ps:P2093 ?author_name . }" ;
		$result = getSPARQL( $sparql ) ;
		$bindings = $result->results->bindings ;
		if (count($bindings) == 0) {
			print "WARNING: NO matching statement found for $paperq $author_num<br/>";
			return NULL;
		}
	}
	if (count($bindings) > 1) {
		print "WARNING: Multiple matching statements for $paperq $author_num?<br/>" ;
	}
	$name_string = $bindings[0]->author_name->value ;
	$statement_uri = $bindings[0]->statement->value ;
	$statement_id = preg_replace ( '/http:\/\/www.wikidata.org\/entity\/statement\//' , '' , $statement_uri) ;
	$pos = strpos($statement_id, '-');
	return substr_replace($statement_id, '$', $pos, 1);
}

// Quickstatements V1 commands for replacing author name strings with author items:
function replace_authors_qs_commands ( $papers, $names, $author_q ) {
	$commands = array() ;
	$paperq_set = array();
	foreach ( $papers AS $author_match ) {
		$paperq = '';
		$author_num = -1;
		$matches = array();
		if (preg_match('/^(Q\d+):(\d+)/', $author_match, $matches)) {
			$paperq = $matches[1];
			$author_num = $matches[2];
		} else {
			print("WARNING: Failed to match '$author_match'<br/>");
			continue ;
		}
		if (isset($paper_q_set[$paperq])) {
			print("WARNING: Author already matched for '$paperq' - skipping author #$author_num<br/>");
			continue;
		}
		$paper_q_set[$paperq] = $author_num ;

		$statement_gid = get_author_statement_guid($paperq, $author_num, $names);

		$claim = new WDClaim($statement_gid);
		if ( !isset($claim)) {
			print("WARNING: claim not found for $statement_gid");
			continue;
		}

		$add = "$paperq\tP50\t$author_q" ;
		$author_name = $claim->c->mainsnak->datavalue->value ;

		$quals = $claim->statementQualifiersToQS() ;
		if (count($quals) > 0) {
			$add = $add . "\t" . implode("\t", $quals) ;
		}
			
		$add .= "\tP1932\t\"$author_name\"" ;
		$refs = $claim->statementReferencesToQS() ;
		if ( count($refs) > 0 ) {
			foreach ( $refs AS $ref ) {
				$commands[] = $add . "\t" . implode("\t", $ref) ;
			}
		} else {
			$commands[] = $add ;
		}
		$commands[] = "-STATEMENT\t" . $claim->id ;
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
