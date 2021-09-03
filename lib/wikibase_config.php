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
$identifier_prop_ids = ['P496','P213','P1053','P214','P2038'];
$identifier_details = [
	'P213' => ['label' => 'ISNI', 'url_prefix' => 'http://isni.org/'],
	'P214' => ['label' => 'VIAF ID', 'url_prefix' => 'https://viaf.org/viaf/'],
	'P496' => ['label' => 'ORCID', 'url_prefix' => 'https://orcid.org/'],
	'P1053' => ['label' => 'Researcher ID', 'url_prefix' => 'https://www.researcherid.com/rid/'],
	'P2038' => ['label' => 'ResearchGate Profile', 'url_prefix' => 'https://www.researchgate.net/profile/']
];
$affiliation_prop_ids = ['P108','P1416','P69'];

?>
