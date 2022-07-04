<?PHP

// Quickstatements V1 commands for creating new author item:
function new_author_qs_commands ( $name, $orcid_author, $viaf_author, $researchgate_author ) {
	global $human_qid;
	global $instance_prop_id;
	$commands = array() ;
	$commands[] = "CREATE" ;
	$commands[] = "LAST\tLen\t\"$name\""  ;
	$commands[] = "LAST\t$instance_prop_id\t$human_qid"  ;
	$commands[] = "LAST\t$occupation_prop_id\t$researcher_qid"  ;
	if ( $orcid_author != '' ) $commands[] = "LAST\tP496\t\"$orcid_author\"" ;
	if ( $viaf_author != '' ) $commands[] = "LAST\tP214\t\"$viaf_author\"" ;
	if ( $researchgate_author != '' ) $commands[] = "LAST\tP2038\t\"$researchgate_author\"" ;
	return $commands ;
}

function get_author_statement_guid($paperq, $author_num, $names) {
	global $wikibase_endpoint;
	$sparql = "SELECT ?author_name ?statement WHERE { wd:$paperq p:P2093 ?statement . ?statement ps:P2093 ?author_name ; pq:P1545 '$author_num' . }" ;
	$result = getSPARQL( $sparql ) ;
	$bindings = $result->results->bindings ;
	if (count($bindings) == 0 && count($names) > 0) {
		$author_names_strings = '"' . implode ( '" "' , $names ) . '"' ;
		$sparql = "SELECT ?author_name ?statement WHERE { VALUES ?author_name { $author_names_strings } . wd:$paperq p:P2093 ?statement . ?statement ps:P2093 ?author_name . }" ;
		$result = getSPARQL( $sparql ) ;
		$bindings = $result->results->bindings ;
		if (count($bindings) == 0) {
			print "WARNING: NO matching statement found for $paperq $author_num<br/>";
			return NULL;
		}
	} else if (count($bindings) == 0) {
		print "WARNING: NO matching statement found for $paperq $author_num<br/>";
		return NULL;
	}
	if (count($bindings) > 1) {
		print "WARNING: Multiple matching statements for $paperq $author_num?<br/>" ;
	}
	$name_string = $bindings[0]->author_name->value ;
	$statement_uri = $bindings[0]->statement->value ;
	$statement_id = preg_replace ( "/http:\/\/$wikibase_endpoint\/entity\/statement\//" , '' , $statement_uri) ;
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
		if ( !isset($i) ) {
			print "Can't find $paperq";
			continue ;
		}
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

// Quickstatements V1 commands for replacing multiple matched author name strings with author items:
function match_authors_qs_commands ( $papers ) {
	$commands = array() ;
	$paperq_set = array();
	foreach ( $papers AS $author_match ) {
		$paperq = '';
		$author_num = -1;
		$author_q = '';
		$matches = array();
		if (preg_match('/^(Q\d+):(\d+):(Q\d+)/', $author_match, $matches)) {
			$paperq = $matches[1];
			$author_num = $matches[2];
			$author_q = $matches[3];
		} else {
			print("WARNING: Failed to match '$author_match'<br/>");
			continue ;
		}
		if (isset($paper_q_set["$paperq:$author_q"])) {
			print("WARNING: Author already matched for '$paperq:$author_q' - skipping author #$author_num<br/>");
			continue;
		}
		$paper_q_set["$paperq:$author_q"] = $author_num ;

		$statement_gid = get_author_statement_guid($paperq, $author_num, []);

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

# Function to merge author claims where duplicates have been entered on a single work
function merge_authors_qs_commands ($wil, $work_qid, $author_numbers) {
	$commands = array();
	$wil->loadItems ( [$work_qid] ) ;
	$work_item = $wil->getItem ( $work_qid ) ;

	$author_claims = $work_item->getClaims ( 'P50' ) ;
	$ordered_author_claims = array();

	foreach ( $author_claims AS $c ) {
		if ( isset($c->qualifiers) and isset($c->qualifiers->P1545) ) {
			$tmp = $c->qualifiers->P1545 ;
			$num = $tmp[0]->datavalue->value ;
			if ( ! isset($ordered_author_claims[$num]) ) {
				$ordered_author_claims[$num] = [] ;
			}
			$ordered_author_claims[$num][] = $c ;
		}
	}

	$author_name_claims = $work_item->getClaims ( 'P2093' ) ;
	$ordered_author_name_claims = array();
	foreach ( $author_name_claims AS $c ) {
		if ( isset($c->qualifiers) and isset($c->qualifiers->P1545) ) {
			$tmp = $c->qualifiers->P1545 ;
			$num = $tmp[0]->datavalue->value ;
			if ( ! isset($ordered_author_name_claims[$num]) ) {
				$ordered_author_name_claims[$num] = [] ;
			}
			$ordered_author_name_claims[$num][] = $c ;
		}
	}

	foreach ( $author_numbers AS $num ) {
		$author_claims = [];
		if (isset($ordered_author_claims[$num])) {
			$author_claims = $ordered_author_claims[$num];
		}
		$author_name_claims = [];
		if (isset($ordered_author_name_claims[$num])) {
			$author_name_claims = $ordered_author_name_claims[$num];
		}
		$commands = array_merge($commands,
			single_index_merge($work_item, $author_claims,
				$author_name_claims));
	}

	return $commands;
}

function single_index_merge($work_item, $author_claims, $author_name_claims) {
	$commands = array();
	$old_commands = array();
	$work_qid = $work_item->getQ();
	if (count($author_claims) > 0) {
		$save_qid = "";
		$save_quals = NULL;
		$save_refs = NULL;
		$new_quals = [];
		$new_refs = [];
		foreach ( $author_claims AS $i => $c ) {
			if ($i == 0) {
				$save_qid = $work_item->getTarget($c);
				$save_quals = $work_item->statementQualifiersToQS($c) ;
				$save_refs = $work_item->statementReferencesToQS($c) ;
			} else {
				$commands[] = "-STATEMENT\t" . $c->id ;
			}
			$new_quals = array_merge($new_quals, $work_item->statementQualifiersToQS($c) );
			$new_refs = array_merge($new_refs, $work_item->statementReferencesToQS($c) );
		}
		foreach ( $author_name_claims AS $c ) {
			$commands[] = "-STATEMENT\t" . $c->id ;
			$new_quals = array_merge($new_quals, $work_item->statementQualifiersToQS($c) );
			$new_refs = array_merge($new_refs, $work_item->statementReferencesToQS($c) );
			$author_name = $c->mainsnak->datavalue->value ;
			$new_quals[] = "P1932\t\"$author_name\"" ;
		}
		$qualifiers = array_unique($new_quals);
		$references = array_unique($new_refs, SORT_REGULAR);
		$add = "$work_qid\tP50\t$save_qid" ;
		if (count($qualifiers) > 0) {
			$add = $add . "\t" . implode("\t", $qualifiers) ;
		}
		if ( count($references) > 0 ) {
			foreach ( $references AS $ref ) {
				$commands[] = $add . "\t" . implode("\t", $ref) ;
			}
		} else {
			$commands[] = $add ;
		}
		$add_save = "$work_qid\tP50\t$save_qid" ;
		if (count($save_quals) > 0) {
			$add_save = $add_save . "\t" . implode("\t", $save_quals) ;
		}
		if ( count($save_refs) > 0 ) {
			foreach ( $save_refs AS $ref ) {
				$old_commands[] = $add_save . "\t" . implode("\t", $ref) ;
			}
		} else {
			$old_commands[] = $add_save ;
		}
		$commands = array_diff($commands, $old_commands);
	} else {
		$max_len = 0;
		$max_claim = NULL;
		foreach ( $author_name_claims AS $claim ) {
			$author_name = $claim->mainsnak->datavalue->value ;
			$len = strlen($author_name);
			if ($len > $max_len) {
				$max_len = $len;
				$max_claim = $claim;
			}
		}
		$save_name = "";
		$save_quals = NULL;
		$save_refs = NULL;
		$new_quals = [];
		$new_refs = [];
		foreach ( $author_name_claims AS $c ) {
			if ($c->id == $max_claim->id) {
				$save_name = $c->mainsnak->datavalue->value ;
				$save_quals = $work_item->statementQualifiersToQS($c);
				$save_refs = $work_item->statementReferencesToQS($c);
			} else {
				$commands[] = "-STATEMENT\t" . $c->id ;
			}
			$new_quals = array_merge($new_quals, $work_item->statementQualifiersToQS($c) );
                        $new_refs = array_merge($new_refs, $work_item->statementReferencesToQS($c) );

		}
		$qualifiers = array_unique($new_quals);
		$references = array_unique($new_refs, SORT_REGULAR);
		$add = "$work_qid\tP2093\t\"$save_name\"" ;
		if (count($qualifiers) > 0) {
			$add = $add . "\t" . implode("\t", $qualifiers) ;
		}
		if ( count($references) > 0 ) {
			foreach ( $references AS $ref ) {
				$commands[] = $add . "\t" . implode("\t", $ref) ;
			}
		} else {
			$commands[] = $add ;
		}
		$add_save = "$work_qid\tP2093\t\"$save_name\"" ;
		if (count($save_quals) > 0) {
			$add_save = $add_save . "\t" . implode("\t", $save_quals) ;
		}
		if ( count($save_refs) > 0 ) {
			foreach ( $save_refs AS $ref ) {
				$old_commands[] = $add_save . "\t" . implode("\t", $ref) ;
			}
		} else {
			$old_commands[] = $add_save ;
		}
		$commands = array_diff($commands, $old_commands);
	}
	return $commands;
}

?>
