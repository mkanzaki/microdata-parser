<?php
/** @prefix : <http://purl.org/net/ns/doas#> .
<> a :PHPScript;
 :title "Microdata Parser/Extractor for ARC2";
 :created "2012-10-01";
 :release [:revision "0.75"; :created "2014-07-10"];
 :description """
 Extracts RDF graph from HTML with microdata annotation, according to "Microdata to RDF" spec (W3C Note).
 Make sure to place this file in ARC2's 'parsers' directory, and also have Microdata.php there. If redistribute this file, Microdata.php must be bundled.
 License is the same as that of ARC2: The W3C Software License or the GPL.
 
 Usage is almost the same as other ARC2 parsers:
	$parser = ARC2::getParser("Microdata");
	$parser->parse($uri);
	$parser->toTurtle($parser->getTriples());
 
 - Note additional extract() is not necessary (called internally). If you want only to parse microdata, use Microdata.php, which is used internally.
 - You can provide HTML data as 2nd param of parse(), as usual for ARC2 parsers.
 - 3rd param of parse() can set how much non-Microdata origin triples to be added. See MDEX_ constant definitions bellow - these are applied by logical AND:
   $parser->parse($uri, '', MDEX_PROPURI | MDEX_MULTIVAL) will use both propertyURI and multipleValues setting in the registry, and ignore others. MDEX_ALL activates all settings. Default setting is MDEX_PROPURI.
 - a default registry is built in this file and automatically loaded. To use a different registry, call $parser->setVocabRegistry("someregistry.json") before parse(). Registry file must be in JSON format.
 
 Caveat:
 - vocabulary entailment is patially implemented. See comment in proc_extra_triples()
 - lang tag is generated only in case the itemprop element has lang attribute, i.e. inherent language information is not considered.
 - Microdata.php uses parse_url to resolve relative URIs. This function might have trouble with non-ascii 'word' id. (名前を勝手に変換しようとして文字化けする可能性あり)
 """ ;
 
 :seeAlso <http://www.w3.org/TR/microdata-rdf/> ;
 :dependencies "should be in ARC2's parsers directory. Also requires Microdata.php" ;
 :license <http://www.w3.org/Consortium/Legal/copyright-software.html>, <http://www.gnu.org/copyleft/gpl.html>.
 */

ARC2::inc('Class');
include_once(dirname(__FILE__)."/Microdata.php");
// option values for 3rd param of parse() re to what extent extra triples to be added. § is section number in "Microdata to RDF" spec
define("MDEX_NONE", 0);
define("MDEX_PROPURI", 1); //propertyURI in registry, §3.1
define("MDEX_MULTIVAL", 2); //multipleValues in registry, §3.2
define("MDEX_PURI_MVAL", MDEX_PROPURI | MDEX_MULTIVAL); //short hand
define("MDEX_VEXPANSION", 4); //vocab expansion: subPropertyOf, equivalentProperty, §4
define("MDEX_PURI_MVAL_VEXP", MDEX_PURI_MVAL | MDEX_VEXPANSION); //short hand
define("MDEX_DATATYPE", 8); //xsd:date etc, §5.1
define("MDEX_TOPITEMS", 16); //md:items, §5.6
define("MDEX_RDFAVOCAB", 32); //rdfa:usesVocabulary, §5.3 - step 7
define("MDEX_VENTAIL", 64); //vocab entailment (partial support), §4.1
define("MDEX_ALL", 127); //logical OR of the above

/**
 * ARC2 common parser/extractor methods
 */
class ARC2_BaseParser extends ARC2_Class{
	
	/**
	 * initialize some global fields (callded by ARC::Class constructor)
	 */
	function __init() {
		parent::__init();
		$this->triples = array();
		$this->t_count = 0;
		$this->added_triples = array();
		$this->bnode_prefix = $this->v('bnode_prefix', 'arc'.substr(md5(uniqid(rand())), 0, 4).'b', $this->a);
		$this->bnode_id = 0;
		$this->auto_extract = $this->v('auto_extract', 1, $this->a);
	}
	
	/**
	 * create new blank node ID
	 */
	function createBnodeID(){
		$this->bnode_id++;
		return '_:' . $this->bnode_prefix . $this->bnode_id;
	}
	
	/**
	 * Add one triple to ARC2 internal structure
	 * @param array $t	array of a triple with subject, object type, langtag and datatype
	 */
	function addT($t) {
		if (function_exists('html_entity_decode')) {
			$t['o'] = html_entity_decode($t['o']);
		}
		if ($this->skip_dupes) {
			$h = md5(serialize($t));
			if (!isset($this->added_triples[$h])) {
				$this->triples[$this->t_count] = $t;
				$this->t_count++;
				$this->added_triples[$h] = true;
			}
		}
		else {
			$this->triples[$this->t_count] = $t;
			$this->t_count++;
		}
	}

	/**
	 * get all triples stored in the ARC2 internal structure
	 * @return array	Set of triples
	 */
	function getTriples() {
		return $this->v('triples', array());
	}

	/**
	 * count processed triples by addT()
	 * @return int	number of triples
	 */
	function countTriples() {
		return $this->t_count;
	}
	
	/**
	 * get simple index style triple
	 * @param int $flatten_objects	
	 * @param string $vals	
	 * @return array	simple index
	 */
	function getSimpleIndex($flatten_objects = 1, $vals = '') {
		return ARC2::getSimpleIndex($this->getTriples(), $flatten_objects, $vals);
	}
}

/**
 * Microdata RDF extractor class, using Microdata.php to parse microdata
 */
class ARC2_MicrodataParser extends ARC2_BaseParser {
	const PFX_VOCAB_HASHLESS = 0;
	const PFX_VOCAB_NEEDHASH = 1;
	const PFX_CONTEXTUAL = 2;
	const RDFNS = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';
	const XSD = 'http://www.w3.org/2001/XMLSchema#';
	const DATE = '\d{4}-[01]\d-[0-3]\d';
	const TIME = '[0-2]\d:[0-6]\d:[0-6]\d(\.\d+)?';
	const TZ = '(Z|[+-][0-2]\d:[0-6]\d)';

	/**
	 * Parses microdata in HTML, extracts RDF triples, and store them in ARC2 structure
	 * @param string $url	URL of source HTML, or base URL if $data is provided
	 * @param string $data	HTML data (optional)
	 * @param string $uber_md	to what extent extra triples should be added which is not part of Microdata model. See constant definition (at the top of this file) for possible values.
	 */
	function parse($url, $data="", $uber_md=1) {
		$this->uber_md = $uber_md;
		if($this->uber_md) $this->load_registry();
		if(!$this->baseUri) $this->baseUri = $url;
		$this->url_hashescaped = $this->frag_escape($this->baseUri).'#';
		$this->pseudo_id = 1;
		// call to Microdata.php functions, and get microdata items
		$mdparser = new Microdata($url, $data, $this->baseUri);
		$mditems = $mdparser->obj(true); //true = get structured values

		// extrat RDF from microdata items
		$topitems = array();
		foreach ($mditems as $item){
			list($itemid, $vtype) = $this->extractRdf($item);
			$topitems[] = array('o' => $itemid, 'o_type' => $vtype);
		}
		$this->proc_extra_triples($topitems);
	}

	/**
	 * convert one microdata Item to RDF graph and store triples
	 * @param object &$item	reference to an Item object
	 * @param string $parent_vbase	vocaburary URI of parent Item
	 * @param string $parent_uripfx	URI prefix of parent Item
	 * @return array	(item subject, subject_type)
	 */
	function extractRdf(&$item, $parent_vbase="", $parent_uripfx=""){
		// set the subject
		if($item->id){
			$s = $item->id;
			$stype = substr($s, 0, 2) == '_:' ? 'bnode' : 'uri'; //special care for itemref treatment
		}else{
			$s = $this->createBnodeID();
			$stype = 'bnode';
		}

		// set type and vocabulary base URI
		if($item->type){
			list($vocab_base, $uripfx) = $this->find_vbase($item->type[0]);
			foreach($item->type as $type){
				// note itemtype was checked to be abs URI. see MicrodataPhpDOMElement::itemType().
				$this->add_type_T($s, $stype, $type);
			}
		}else{
			//MD spec 2.1 The microdata model ... The relevant types for a typed item is the item's item types, if it has one, or else is the relevant types of the item for which it is a property's value
			//hence MD to RDF spec: ...Otherwise, set type to current type from evaluation context if not empty
			$vocab_base = $parent_vbase ? $parent_vbase : $this->url_hashescaped;
			$uripfx = $parent_uripfx ? $parent_uripfx : '';
		}
		
		// set properties and objects, then add triples
		$this->set_triples(
			$item, array('s' => $s, 's_type' => $stype),
			$vocab_base,
			$uripfx
		);
		
		return array($s, $stype);
	}
	
	/**
	 * set item's properties and those values, then store triples
	 * @param object &$item	reference to an Item object
	 * @param array $t	triple holder, whose subject is already set by the caller
	 * @param string $vocab_base	base URI to generate propety URI
	 * @param string $uripfx	URI prefix to find registry definition
	 */
	function set_triples(&$item, $t, $vocab_base, $uripfx){
		//$t['s'] and $t['s_type'] is set by the caller because these are common among the item
		// set defaults if use registry
		$def = $this->uber_md ? $this->mdregistry[$uripfx] : array();
		list($default_order, $dummy) = $this->set_order_dtype($def, 'unordered');
		// proc each property
		foreach($item->properties as $iprop => $objarr){
			// reset $triples for current property
			$triples = array();
			// set expanded property
			$t['p'] = 
				// if absolute URI, use as is
				// (assuming all property URIs use http: scheme. maybe more check needed)
				substr($iprop, 0, 5) == 'http:' ? $iprop :
				// if simple name, prepend vocabulary base URI
				$vocab_base . $iprop;
			// check registry for particular property
			list($val_order, $val_type) = $this->set_order_dtype($def['properties'][$iprop], $default_order);
			
			// process each value of this property
			foreach($objarr as $obj){
				if(is_object($obj)){
					list($t['o'], $t['o_type']) = $this->extractRdf($obj, $vocab_base, $uripfx);
					$t['o_lang'] =$t['o_datatype'] = '';
				}else{
					$t['o'] = $obj['value'];
					if(($t['o_type'] = $obj['vtype']) == 'literal'){
						$t['o_lang'] = $obj['lang']; // lang attr value. NOT inherited language
						$t['o_datatype'] = ($this->uber_md & MDEX_DATATYPE) ?
						$this->find_datatype($obj['value'], $obj['elt_type'], $val_type) : '';
					}else{
						$t['o_lang'] =$t['o_datatype'] = '';
					}
				}
				$triples[] = $t; // save one triple, and reuse $t since S, P are the same
				if($this->uber_md & MDEX_VEXPANSION)
					$this->vocab_expansion($def['properties'][$iprop], $t);
			}
			
			// treat value order according to registry (if set to use) and add triples
			if($val_order == 'list'){
				$this->set_collection($triples, $t['s'], $t['s_type'], $t['p']);
			}else{
				foreach($triples as $triple) $this->addT($triple);
			}
		}
	}
	
	/**
	 * add extra triples defined in "Microdata to RDF" spec, but not part of microdata.
	 * each inclusion can be controlled by $uber_md param of parse()
	 * @param array $topitems	top items (that have no parent item)
	 */
	function proc_extra_triples(&$topitems){
		// RDF collection for top items: §5.6
		if($this->uber_md & MDEX_TOPITEMS)
			$this->set_collection($topitems, $this->baseUri, 'uri', 'http://www.w3.org/ns/md#item');
		
		// RDFa Vocabulary Entailment: §5.3 - step 7
		if($this->uber_md & MDEX_RDFAVOCAB){
			$t = array(
				's' => $this->baseUri, 's_type' => 'uri',
				'p' => 'http://www.w3.org/ns/rdfa#usesVocabulary',
				'o_type' => 'uri'
			);
			foreach(array_keys($this->used_vocab) as $vocab){
				$t['o'] = $vocab;
				$this->addT($t);
			}
		}
		
		// entailment: §4.1
		if($this->uber_md & MDEX_VENTAIL){
			//add entailment (subPropertyOf|equvalentProperty) relationship of properties, which might be useful to work with a reasoner after extraction.
			//Note equvalentProperty entailment (e.g. add schema.org/additionalType for other asserted rdf:type's) is not supproted.
			$t = array('s_type' => 'uri', 'o_type' => 'uri');
			foreach($this->expanded as $what => $props){
				$t['p'] = $this->mdreg_ventail[$what];
				foreach($props as $prop => $ent){
					$t['s'] = $prop;
					foreach(array_keys($ent) as $entailed){
						$t['o'] = $entailed;
						$this->addT($t);
					}
				}
			}
		}
	}

	/**
	 * determine base URI for property, possibly based on registry scheme
	 * @param string $typeuri	URI of the itemtype
	 * @return sarray	(property base URI, URI prefix of the vocabulary)
	 */
	function find_vbase($typeuri){
		if($this->uber_md & MDEX_PROPURI){
			foreach($this->uripfx as $uripfx => $scheme){
				if(substr($typeuri, 0, strlen($uripfx)) == $uripfx){
					$this->used_vocab[$uripfx]++;
					if($scheme == self::PFX_CONTEXTUAL){
						//contextual scheme
						return array("http://www.w3.org/ns/md?type=".$this->frag_escape($typeuri)."&prop=", $uripfx);
					}else{
						//vocabulary scheme
						return array($uripfx.($scheme==self::PFX_VOCAB_NEEDHASH ? '#' : ''), $uripfx);
					}
				}
			}
		}
		// default behavior
		$uriparts = $this->splitURI($typeuri);
		return array($uriparts[0], '');
	}
	
	/**
	 * determine datatype of the value
	 * @param string $value	value of the literal node
	 * @param string $elt_type	element info from Microdata.php (e.g. 'time')
	 * @param string $val_type	datatype from registry
	 * @return string	datatype if any, or ''
	 */
	function find_datatype($value, $elt_type, $val_type){
		if($val_type) return $val_type;
		$type = '';
		switch($elt_type){
		case 'time':
			if(preg_match("/^\d{4}$/", $value)){
				$type = self::XSD."gYear";
			}elseif(preg_match("/^".self::DATE."$/", $value)){
				$type = self::XSD."date";
			}elseif(preg_match("/^".self::DATE.'T'.self::TIME.self::TZ."?$/", $value)){
				$type = self::XSD."dateTime";
			}elseif(preg_match("/^".self::TIME."$/", $value)){
				$type = self::XSD."time";
			}elseif(preg_match("/^-?P(\d+Y)?(\d+M)?(\d+D)?T?(\d+H)?(\d+M)(\d+S)$/", $value)){
				$type = self::XSD."duration";
			}
			break;
		}
		return $type;
	}
	
	/**
	 * set the value ordering rule and datatype for the property based on registry
	 * @param array $def	propety definition from registry
	 * @param string $default_order	default order rule
	 * @retur array	(ordering rule, datatype)
	 */
	function set_order_dtype(&$def, $default_order){
		$order = ($this->uber_md & MDEX_MULTIVAL) ?
		(isset($def['multipleValues']) ? $def['multipleValues'] : $default_order) :
		'unordered';
		$type = ($this->uber_md & MDEX_DATATYPE) ?
		(isset($def['datatype']) ? $def['datatype'] : '') :
		'';
		return array($order, $type);
	}
	
	/**
	 * Perform vocabulary expansion based on registry
	 * @param array $pdef	propety definition from registry
	 * @param array $t	the original triple (not reference because change value)
	 */
	function vocab_expansion(&$pdef, $t){
		foreach(array('equivalentProperty','subPropertyOf') as $what){
			if(isset($pdef[$what])){
				$original_prop = $t['p'];
				$entarr = is_array($pdef[$what]) ? $pdef[$what] : array($pdef[$what]);
				foreach($entarr as $entailed){
					//vocab expansion, typically add rdf:type for schema.org/additionalType
					$t['p'] = $entailed;
					$this->addT($t);
					//record expansion for later entailment
					$this->expanded[$what][$original_prop][$entailed]++ ;
				}
			}
		}
	}
	
	/**
	 * store an array of RDF nodes as RDF collection
	 * @param array &$triples	array of RDF nodes as ARC2 triple format (only each object is stored)
	 * @param string $item_s	subject of the collection node
	 * @param string $item_stype	type of the subject
	 * @param string $prop	predicate that connects the subject and the collection node
	 */
	function set_collection(&$triples, $item_s, $item_stype, $prop){
		$bnode = $this->createBnodeID();
		$this->addT(array(
			's' => $item_s, 's_type' => $item_stype,
			'p' => $prop, 
			'o' => $bnode, 'o_type' => 'bnode',
		));
		//$this->add_type_T($bnode, 'bnode', self::RDFNS.'Collection');
		$final_t = array_pop($triples);
		while($t = array_shift($triples)){
			$s = $bnode;
			$bnode = $this->createBnodeID();
			$this->add_list_member($s, $t, $bnode, 'bnode');
		};
		$this->add_list_member($bnode, $final_t, self::RDFNS.'nil', 'uri');
	}

	/**
	 * Add a set of rdf:first and rdf:rest triples for one node in a collection
	 * @param string $s	the blank node to connect the list
	 * @param array &$t	a triple array whose object is the collection node (s, p are not used)
	 * @param string $nextnode	next collection bnode, or rdf:nil
	 * @param string $nextnode_type	'bnode' if list continues, 'uri' if rdf:nil
	 */
	function add_list_member($s, &$t, $nextnode, $nextnode_type){
		$this->addT(array(
			's' => $s, 's_type' => 'bnode',
			'p' => self::RDFNS.'first', 
			'o' => $t['o'], 'o_type' => $t['o_type'],
			'o_lang' => $t['o_lang'],'o_datatype' => $t['o_datatype']
		));
		$this->addT(array(
			's' => $s, 's_type' => 'bnode',
			'p' => self::RDFNS.'rest', 
			'o' => $nextnode, 'o_type' => $nextnode_type
		));
	}

	/**
	 * Add one rdf:type triple
	 * @param string $s	subject of the triple
	 * @param string $stype	type of the subject ('uri' or 'bnode')
	 * @param string $c	object of the triple = class
	 */
	function add_type_T($s, $stype, $c){
		$this->addT(array(
			's' => $s, 's_type' => $stype,
			'p' => self::RDFNS.'type', 
			'o' => $c, 'o_type' => 'uri'
		));
	}

	/**
	 * Set base URI to resolve relative URIs (if not set, document URI is base URI).
	 * @param string $uri	the base URI
	 */
	function setBase($uri){
		$this->baseUri = $uri;
	}
	
	/**
	 * Set vocabulary registry file (if not set, use built-in default registry).
	 * @param string $path_or_uri	location of the registry file
	 */
	function setVocabRegistry($path_or_uri){
		$this->registry = $path_or_uri;
	}
	
	/**
	 * load vocabulary registry of microdata to RDF conversion rule set
	 */
	function load_registry(){
		if(isset($this->uripfx)) return; // already loaded
		$this->uripfx = array();
		if($this->registry){
			if($regdata = file_get_contents($this->registry)){
				$this->mdregistry = json_decode($regdata, true);
			}else{
				return false;
			}
		}else{
			$this->mdregistry = $this->get_default_registry();
		}
		foreach($this->mdregistry as $uri => $def){
			if($def['propertyURI'] == 'contextual'){
				$this->uripfx[$uri] = self::PFX_CONTEXTUAL;
			}else{
				//vocabulary
				$this->uripfx[$uri] = preg_match("![#/]$!", $uri) ? self::PFX_VOCAB_HASHLESS : self::PFX_VOCAB_NEEDHASH;
			}
		}
		if($this->uber_md & (MDEX_VEXPANSION | MDEX_VENTAIL)){
			$this->mdreg_ventail = array(
				'equivalentProperty' => 'http://www.w3.org/2002/07/owl#equivalentProperty',
				'subPropertyOf' => 'http://www.w3.org/2000/01/rdf-schema#subPropertyOf'
			);
			// to count expanded triples in order to add entail triple later
			$this->expanded = array(
				'equivalentProperty' => array(),
				'subPropertyOf' => array()
			);
		}
		// to count used vocabulary in order to add rdfa:usesVocabulary later
		$this->used_vocab = array();
	}
	
	/**
	 * get built-in default vocabulary registry
	 * @return array	default registry data
	 */
	function get_default_registry(){
		return json_decode(
			//JSON format registry at http://www.w3.org/ns/md as of 2012-1201
			//Content-Location: md.json
			//Last-Modified: Sat, 01 Dec 2012 22:45:21 GMT
			'{
  "http://schema.org/": {
    "propertyURI":    "vocabulary",
    "multipleValues": "unordered",
    "properties": {
      "additionalType": {"subPropertyOf": "http://www.w3.org/1999/02/22-rdf-syntax-ns#type"},
      "blogPosts": {"multipleValues": "list"},
      "breadcrumb": {"multipleValues": "list"},
      "byArtist": {"multipleValues": "list"},
      "creator": {"multipleValues": "list"},
      "episode": {"multipleValues": "list"},
      "episodes": {"multipleValues": "list"},
      "event": {"multipleValues": "list"},
      "events": {"multipleValues": "list"},
      "founder": {"multipleValues": "list"},
      "founders": {"multipleValues": "list"},
      "itemListElement": {"multipleValues": "list"},
      "musicGroupMember": {"multipleValues": "list"},
      "performerIn": {"multipleValues": "list"},
      "actor": {"multipleValues": "list"},
      "actors": {"multipleValues": "list"},
      "performer": {"multipleValues": "list"},
      "performers": {"multipleValues": "list"},
      "producer": {"multipleValues": "list"},
      "recipeInstructions": {"multipleValues": "list"},
      "season": {"multipleValues": "list"},
      "seasons": {"multipleValues": "list"},
      "subEvent": {"multipleValues": "list"},
      "subEvents": {"multipleValues": "list"},
      "track": {"multipleValues": "list"},
      "tracks": {"multipleValues": "list"}
    }
  },
  "http://microformats.org/profile/hcard": {
    "propertyURI":    "vocabulary",
    "multipleValues": "unordered"
  },
  "http://microformats.org/profile/hcalendar#": {
    "propertyURI":    "vocabulary",
    "multipleValues": "unordered",
    "properties": {
      "categories": {"multipleValues": "list"}
    }
  }
}'
		, true);
	}
	
	/**
	 * performs "fragment escape" as defined in HTML5
	 * @param string $uri	URI string to escape
	 * @return string	escaped URI
	 */
	function frag_escape($uri){
		return str_replace(
			array('"', '#', '%', '<', '>', '[', '\\', ']', '^', '{', '|', '}'),
			array('%22', '%23', '%25', '%3C', '%3E', '%5B', '%5C', '%5D', '%5E', '%7B', '%7C', '%7D'),
			$uri
		);//"
	}

}

?>
