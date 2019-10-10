<?PHP

// PHP class designed to run some very specific types of updates on works
// and their relationships with authors (using OAuth to do the updates)

class EditClaims {
	var $oauth = NULL ;
	var $error = NULL ;
	
	function __construct ( $oauth ) {
		$this->oauth = $oauth;
	}

	function string_value_of_claim($c) {
		return $c->mainsnak->datavalue->value ;
	}

	function create_string_qualifier($property, $string) {
		$dv = new stdClass();
		$dv->value = $string
		$dv->type = 'string';
		$snak = new stdClass();
		$snak->snaktype = 'value';
		$snak->property = $property;
		$snak->datatype = 'string';
		$snak->datavalue = $dv;
		return $snak;
	}

# Function to merge author claims where duplicates have been entered on a single work
	function merge_authors ($work_qid, $author_numbers, $remove_claims, $edit_summary) {
		$prep = $this->oauth->prepare_edit_token('merge_authors') ;
		if ($prep == NULL) {
			$this->error = $this->oauth->error ;
			return false;
		}
		$ch = $prep[0] ;
		$token = $prep[1] ;

	// Fetch latest version of work:
		$work_item = $this->oauth->fetch_item($work_qid, $ch) ;
		$baserev = $work_item->lastrevid;

	$commands = array();

	$author_claims = isset($work_item->claims->P50) ? $work_item->claims->P50 : [] ;
	$ordered_author_claims = array();

	foreach ( $author_claims AS $c ) {
		if ( isset($c->qualifiers) and isset($c->qualifiers->P1545) ) {
			$ordinals = $c->qualifiers->P1545 ;
			foreach ($ordinals AS $tmp) {
				$num = $tmp->datavalue->value ;
				if ( ! isset($ordered_author_claims[$num]) ) {
					$ordered_author_claims[$num] = [] ;
				}
				$ordered_author_claims[$num][] = $c ;
			}
		}
	}

	$author_name_claims = isset($work_item->claims->P2093) ? $work_item->claims->P2093 : [] ;
	$ordered_author_name_claims = array();
	foreach ( $author_name_claims AS $c ) {
		if ( isset($c->qualifiers) and isset($c->qualifiers->P1545) ) {
			$ordinals = $c->qualifiers->P1545 ;
			foreach ($ordinals AS $tmp) {
				$num = $tmp->datavalue->value ;
				if ( ! isset($ordered_author_name_claims[$num]) ) {
					$ordered_author_name_claims[$num] = [] ;
				}
				$ordered_author_name_claims[$num][] = $c ;
			}
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
			$this->single_index_merge($work_item, $author_claims,
				$author_name_claims));
	}
# Remove additional claims supplied in args:
	foreach ( $remove_claims AS $claim_id ) {
		$commands[] = ['id' => $claim_id, 'remove' => ''] ;
	}

		$res = $this->oauth->apply_commands_to_item($work_qid, $baserev, $edit_summary, $token, $ch, $commands) ;
		if (! $res ) {
			$this->error = $this->oauth->error;
			return false;
		}

		return true;
	}

    function single_index_merge($work_item, $author_claims, $author_name_claims) {
	$commands = array();
	$old_commands = array();
	$work_qid = $work_item->id;
	if (count($author_claims) > 0) {
		$save_claim = NULL;
		$new_quals = [];
		$new_refs = [];
		foreach ( $author_claims AS $i => $c ) {
			$quals = isset($c->qualifiers) ? (array) $c->qualifiers : [] ;
			$refs = isset($c->references) ? $c->references : [] ;
			if ($i == 0) {
				$save_claim = $c ;
				if (isset($c->qualifiers)) {
					$save_quals = (array) $c->qualifiers;
				}
				if (isset($c->references)) {
					$save_refs = $c->references;
				}
			} else {
				$commands[] = ['id' => $c->id, 'remove' => ''] ;
			}
			$new_quals = $this->merge_qualifiers($new_quals, $quals);
			$new_refs = $this->merge_references($new_refs, $refs);
		}
		foreach ( $author_name_claims AS $c ) {
			$quals = isset($c->qualifiers) ? (array) $c->qualifiers : [] ;
			$refs = isset($c->references) ? $c->references : [] ;
			$new_quals = $this->merge_qualifiers($new_quals, $quals);
			$new_refs = $this->merge_references($new_refs, $refs);
			$author_name = $this->string_value_of_claim($c);
			$new_quals = $this->merge_qualifiers($new_quals, ['P1932' => [$this->create_string_qualifier('P1932', $author_name)]]);
			$commands[] = ['id' => $c->id, 'remove' => ''] ;
		}
		
		$changed = false ;
		if (count($new_quals) > 0) {
			$changed = true;
			$save_claim->qualifiers = $new_quals;
		}
		if ( count($new_refs) > 0 ) {
			$changed = true;
			$save_claim->references = $new_refs;
		}
		if ( $changed ) {
			$commands[] = $save_claim;
		}
	} else {
		$max_len = 0;
		$save_claim = NULL;
		foreach ( $author_name_claims AS $claim ) {
			$author_name = $this->string_value_of_claim($claim);
			$len = strlen($author_name);
			if ($len > $max_len) {
				$max_len = $len;
				$save_claim = $claim;
			}
		}
		$new_quals = [];
		$new_refs = [];
		foreach ( $author_name_claims AS $c ) {
			$quals = isset($c->qualifiers) ? (array) $c->qualifiers : [] ;
			$refs = isset($c->references) ? $c->references : [] ;
			if ($c->id != $save_claim->id) {
				$commands[] = ['id' => $c->id, 'remove' => ''] ;
			}
			$new_quals = $this->merge_qualifiers($new_quals, $quals);
			$new_refs = $this->merge_references($new_refs, $refs);
		}

		$changed = false ;
		if (count($new_quals) > 0) {
			$changed = true;
			$save_claim->qualifiers = $new_quals;
		}
		if ( count($new_refs) > 0 ) {
			$changed = true;
			$save_claim->references = $new_refs;
		}
		if ( $changed ) {
			$commands[] = $save_claim;
		}
	}
	return $commands;
    }

	function merge_qualifiers($cq, $qualifiers) {
		foreach( $qualifiers AS $qual_prop => $qual_list ) {
			if (isset($cq[$qual_prop]) ) {
				$current_list = $cq[$qual_prop];
				foreach ( $qual_list AS $new_qual ) {
					$new_values = $this->value_from_qualifier($new_qual);
					$match = false;
					foreach ( $current_list AS $old_qual ) {
						$old_values = $this->value_from_qualifier($old_qual);
						$values_diff = array_diff_assoc($old_values, $new_values);
						if (count($values_diff) == 0) {
							$match = true;
							break;
						}
					}
					if (! $match) {
						$current_list[] = $new_qual;
					}
				}
				$cq[$qual_prop] = $current_list;
			} else {
				$cq[$qual_prop] = $qual_list;
			}
		}
		return $cq;
	}


// The following assumes hashes have been pre-caculated for all references
	function merge_references($cr, $references) {
		$current_ref_hashes = [];
		foreach( $cr AS $reference ) {
			$current_ref_hashes[$reference->hash] = 1;
		}
		foreach( $references AS $reference ) {
			$hash = $reference->hash;
			if (isset($current_ref_hashes[$hash])) {
				continue;
			}
			$current_ref_hashes[$hash] = 1;
			$cr[] = $reference;
		}
		return $cr;
	}

	function value_from_qualifier($q) {
		$ret = [];
		if (is_array($q) ) {
			$ret = (array) $q['datavalue']['value'];
		} else {
			$ret = (array) $q->datavalue->value;
		}
		return $ret;
	}

# Function to renumber (set series ordinal) for author claims per user request
	function renumber_authors( $work_qid, $renumbering, $remove_claims, $edit_summary ) {
		$prep = $this->oauth->prepare_edit_token('merge_authors') ;
		if ($prep == NULL) {
			$this->error = $this->oauth->error ;
			return false;
		}
		$ch = $prep[0] ;
		$token = $prep[1] ;

	// Fetch latest version of work:
		$work_item = $this->oauth->fetch_item($work_qid, $ch) ;
		$baserev = $work_item->lastrevid;

		$commands = array();
		$author_claims = isset($work_item->claims->P50) ? $work_item->claims->P50 : [] ;
		foreach ( $author_claims AS $c ) {
			$new_cmd = $this->renumber_claim($c, $renumbering);
			if ($new_cmd != NULL) {
				$commands[] = $new_cmd;
			}
		}
		$author_name_claims = isset($work_item->claims->P2093) ? $work_item->claims->P2093 : [] ;
		foreach ( $author_name_claims AS $c ) {
			$new_cmd = $this->renumber_claim($c, $renumbering);
			if ($new_cmd != NULL) {
				$commands[] = $new_cmd;
			}
		}
# Remove additional claims supplied in args:

		foreach ( $remove_claims AS $claim_id ) {
			$commands[] = ['id' => $claim_id, 'remove' => ''] ;
		}

		$res = $this->oauth->apply_commands_to_item($work_qid, $baserev, $edit_summary, $token, $ch, $commands) ;
		if (! $res ) {
			$this->error = $this->oauth->error;
			return false;
		}

		return true;
	}

	function renumber_claim($c, $renumbering) {
		if ( ! isset($renumbering[$c->id] ) ) return NULL;
		$new_num = $renumbering[$c->id];
		if ($new_num == '') return NULL;
		$new_qualifier_entry = $this->create_string_qualifier('P1545', $new_num);
		if ( isset($c->qualifiers) ) {
			if ( isset($c->qualifiers->P1545) ) {
				$ordinals = $c->qualifiers->P1545 ;
				foreach ($ordinals AS $tmp) {
					$old_num = $tmp->datavalue->value ;
					if ($old_num == $new_num) return NULL;
				}
			}
			$c->qualifiers->P1545 = [$new_qualifier_entry];
		} else {
			$c->qualifiers = ['P1545' => [$new_qualifier_entry]];
		}
		return $c;
	}

	function match_authors( $work_qid, $matches, $edit_summary ) {
		$prep = $this->oauth->prepare_edit_token('match_authors') ;
		if ($prep == NULL) return false;
	// Fetch edit token
		$ch = $prep[0] ;
		$token = $prep[1] ;

	// Fetch work
		$work_item = $this->oauth->fetch_item($work_qid, $ch) ;
		$baserev = $work_item->lastrevid;

		$auth_qid_by_ordinal = array();
		$ordinal_used = array();
		foreach ($matches AS $match) {
			$parts = array();
			$author_qid = NULL;
			if (preg_match('/^(Q\d+):(\d+)/', $match, $parts)) {
				$author_qid = $parts[1];
				$num = $parts[2];
			} else {
				$this->error = "ERROR: bad input data '$match'" ;
				return false;
			}
			if (isset($ordinal_used[$num])) {
				$this->error = "ERROR: duplicate ordinal '$num'" ;
				return false;
			} else {
				$ordinal_used[$num] = 1;
				$auth_qid_by_ordinal[$num] = $author_qid;
			}
		}
		$commands = array();
		$author_name_claims = isset($work_item->claims->P2093) ? $work_item->claims->P2093 : [] ;
		foreach ( $author_name_claims AS $c ) {
			if ( isset($c->qualifiers) ) {
				if ( isset($c->qualifiers->P1545) ) {
					$ordinals = $c->qualifiers->P1545 ;
					foreach ($ordinals AS $tmp) {
						$num = $tmp->datavalue->value ;
						if (isset($auth_qid_by_ordinal[$num])) {
							$new_cmds = $this->change_name_to_author_claim($c, $num, $auth_qid_by_ordinal[$num]);
							$commands = array_merge($commands, $new_cmds);
						}
					}
				}
			}
		}

		$res = $this->oauth->apply_commands_to_item($work_qid, $baserev, $edit_summary, $token, $ch, $commands) ;

		if (! $res ) {
			$this->error = $this->oauth->error;
			return false;
		}
		return true;
	}

	function change_name_to_author_claim($c, $num, $author_qid) {
		$commands = array();
		$numeric_id = 0;
		$parts = array();
		if (preg_match('/^Q(\d+)$/', $author_qid, $parts)) {
			$numeric_id = intval($parts[1]);
		}
		
		$quals = isset($c->qualifiers) ? (array) $c->qualifiers : [] ;
		$refs = isset($c->references) ? $c->references : [] ;
		$author_name = $this->string_value_of_claim($c);
		$new_quals = $this->merge_qualifiers($quals, ['P1932' => [$this->create_string_qualifier('P1932', $author_name)]]);
		$new_claim = ['mainsnak' => ['snaktype' => 'value', 'property' => 'P50', 'datatype' => 'wikibase-item', 'datavalue' => ['value' => ['entity-type' => 'item', 'id' => $author_qid, 'numeric-id' => $numeric_id],  'type' => 'wikibase-entityid']], 'type' => 'statement', 'rank' => 'normal'];
		$new_claim['qualifiers'] = $new_quals;
		$new_claim['references'] = $refs;
		$commands[] = $new_claim ;
		$commands[] = ['id' => $c->id, 'remove' => ''] ;
		return $commands;
	}
}

?>
