<?PHP

$tool_name = 'author-disambiguator';
$sparql_endpoint = 'https://query.wikidata.org/sparql';
$wikibase_endpoint = 'www.wikidata.org';
$base_entity_url = "http://$wikibase_endpoint/";
$wikibase_api_url = "https://$wikibase_endpoint/w/api.php";
$quickstatements_api_url = 'https://quickstatements.toolforge.org/api.php';
$reasonator_prefix = 'https://reasonator.toolforge.org/?q=';
$sqid_prefix = 'https://sqid.toolforge.org/#/view?id=';

$human_qid = 'Q5';
$human_group_qid = 'Q16334295';
$instance_prop_id = 'P31';
$subclass_prop_id = 'P279';
$author_prop_id = 'P50';
$author_name_prop_id = 'P2093';
$stated_as_prop_id = 'P1932';
$ordinal_prop_id = 'P1545';
$cites_work_prop_id = 'P2860';
$published_in_prop_id = 'P1433';
$published_date_prop_id = 'P577';
$topic_prop_id = 'P921';
$title_prop_id = 'P1476';
$doi_prop_id = 'P356';
$pubmed_prop_id = 'P698';
$orcid_prop_id = 'P496';
$isni_prop_id = 'P213';
$researcherid_prop_id = 'P1053';
$viaf_prop_id = 'P214';
$researchgate_prop_id = 'P2038';
$affiliation_prop_ids = ['P108','P1416','P69'];

?>
