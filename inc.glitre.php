<?php

/* 

Copyright 2010 ABM-utvikling

This file is part of Glitre.

Glitre is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

Glitre is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with Glitre.  If not, see <http://www.gnu.org/licenses/>.

*/

$config = array(); 
	
/***************************************************************** 
STEP ONE - Get the records and make sure they are standard MARCXML 
******************************************************************/

function glitre_search($args) {
	
	global $config;

	include('inc.config.php');
	include('File/MARCXML.php');
	$config = get_config($args['library']);

	// Caching of search results
	require('Cache/Lite.php');
	// Options for the cache
	$options = array(
	    'cacheDir' => $config['base_path'] . 'cache/'
	);
	// Create a Cache_Lite object
	$Cache_Lite = new Cache_Lite($options);

	$cacheresult = 'nocache';
	$records = array();
	$single_record = false;
	
	if (!empty($args['q'])) {

		// Set default values if these two are empty, otherwise the cache won't work properly
		$args['sort_by'] = $args['sort_by'] ? $args['sort_by'] : $config['default_sort_by'];
		$args['sort_order'] = $args['sort_order'] ? $args['sort_order'] : $config['default_sort_order'];
		// Calculate the cache id for the sorted results
		$sorted_cache_id = 'sorted_' . $args['sort_by'] . '_' . $args['sort_order'] . '_' . $args['library'] . '_' . md5(strtolower($args['q']));
		// Check if the results, sorted in the way we want them, are cached
		if ($records = unserialize($Cache_Lite->get($sorted_cache_id))) {
			// We found what we wanted		
			$cacheresult = 'sorted';
		} else {
			
			// Set an id for the search cache
			$search_cache_id = 'search_' . $args['library'] . '_' . md5(strtolower($args['q']));	
			// Check if the raw results are already cached
			$marcxml = '';
			if ($marcxml = $Cache_Lite->get($search_cache_id)) {
				// Found it! 
				$cacheresult = 'raw';
			} else {
				// Collect the MARCXML in a string
				if (!empty($config['lib']['sru'])) {
					// SRU
					$query = urlencode(massage_input($args['q']));
					$marcxml = get_sru($query);
				} else {
					// Z39.50
					$query = "any=" . massage_input($args['q']);
					$marcxml = get_z($query);
				}
				if (is_array($marcxml) && $marcxml['error']) {
					return glitre_format_error($marcxml, $args['format']);
				}
				$Cache_Lite->setLifeTime($config['cache_time_search']);
				$Cache_Lite->save($marcxml, $search_cache_id);
			}
			// Sort the records
			$records = glitre_sort($marcxml, $args['sort_by'], $args['sort_order']);
			$cacheablerecords = array();
			foreach ($records as $record) {
				// Remove the serialized objects
				unset($record['marcobj']);
				$cacheablerecords[] = $record;
			}
			$Cache_Lite->setLifeTime($config['cache_time_sorted']);
			$Cache_Lite->save(serialize($cacheablerecords), $sorted_cache_id);
		}
		
		// Pick out the ones we actually want
		// Note: Counting of pages starts on 0 (zero), so page=2 is actually the 3rd page of results
		// Which page are we showing? 
		$page = $args['page'] ? $args['page'] : 0;
		// How many reords should be displayed on a page? 
		// TODO: Parameters should probably be ablo to set this, but with a configurable default and upper limit
		$per_page = $config['lib']['records_per_page'] ? $config['lib']['records_per_page'] : $config['records_per_page'];
		// Get the location of the first record
		$first_record = $page * $per_page;
		// Get the total number of records
		$num_of_records = count($records);
		// Slice out the records that make up the page we are looking for
		$records = array_slice($records, $first_record, $per_page);
		// Calculate the position of the last record
		$last_record = $first_record + count($records);
		// Check the number of records after the slice
		if (count($records) < 1) {
			exit('Error: invalid result-page');
		}
		
		// Recreate the MARC objects if they are missing (because these records were revived from the cache) 
		$simplerecords = array(); 
		foreach ($records as $record) {
			
			if (!isset($record['marcobj'])) {
				// Simplify the records to just an array of objects
				$marc = new File_MARCXML($record['marcxml'], File_MARC::SOURCE_STRING);
				$simplerecords[] = $marc->next();
			} else {
				$simplerecords[] = $record['marcobj'];
			}
		}
		$records = $simplerecords;
				
	} elseif(!empty($args['id'])) {
		
		// Set an id for the single-record-by-id cache
		$record_cache_id = 'record_' . $args['library'] . '_' . md5(strtolower($args['id']));	
		// Check if the record is already cached
		$record = '';
		if ($marcxml = $Cache_Lite->get($record_cache_id)) {
			// Found it! 
			$cacheresult = 'record';
		} else {
			// Collect the MARCXML in a string
			if (!empty($config['lib']['sru'])) {
				// SRU
				$query = 'rec.id=' . urlencode($args['id']);
				$marcxml = get_sru($query);
			} else {
				// Z39.50
				$query = 'tnr=' . urlencode($args['id']);
				$marcxml = get_z($query);
			}
			$Cache_Lite->setLifeTime($config['cache_time_record']);
			$Cache_Lite->save($marcxml, $record_cache_id);
		}
		$marc = new File_MARCXML($marcxml, File_MARC::SOURCE_STRING);		
		$records[] = $marc->next();
		$single_record = true;
		
	}
	
	// A simple log for evaluating the cache strategy
	if ($config['cache_log_file']) {
		$qid = $args['q'] ? $args['q'] : $args['id'];
		$log = date("Y-m-d H:i") . "\t" . $page . "\t" . $args['library'] . "\t" . $qid . "\t" . $cacheresult . "\n";
		$fp = fopen($config['cache_log_file'], 'a');
		if ($fp) {
			fwrite($fp, $log);
			fclose($fp);
		} else {
			exit('Could not open ' . $config['cache_log_file']);	
		}
	}
	
	// The position of the first record needs to be bumped up by one
	$first_record++;
	// Format the records
	return glitre_format($records, $args['format'], $single_record, $num_of_records, $first_record, $last_record, $args['content_type']);
}

/***************************************
STEP TWO - Sort the records as desired 

Arguments 
$marcxml: a string containing records in MARCXML
$sort_by: what criteria to sort on
$sort_order: ascending or descending sort

Returns 
Sorted records, in the form of an array of arrays

record1
	title
	author
	year
	marcobj

****************************************/

function glitre_sort($marcxml, $sort_by = 'default', $sort_order = 'default') {
	
	global $config;
	
	if ($sort_by == 'default') {
		$sort_by = $config['default_sort_by'];
	}
	if ($sort_order == 'default') {
		$sort_order = $config['default_sort_order'];
	}
	
	// Check that sort_by and sort_order are valid
	$allowed_sort_by = array('author', 'year', 'title');
	if (!in_array($sort_by, $allowed_sort_by)) {
		exit("Invalid sort_by: $sort_by");	
	}
	$allowed_sort_order = array('descending', 'ascending');
	if (!in_array($sort_order, $allowed_sort_order)) {
		exit("Invalid sort_order: $sort_order");	
	}
	
	// Parse the records
	$rawrecords = new File_MARCXML($marcxml, File_MARC::SOURCE_STRING);

	// Make the records sortable	
	$records = array();
	while ($rawrec = $rawrecords->next()) {
		$records[] = get_sortable_record($rawrec);
	}
	
	// Do the actual sorting
	if       ($sort_by == 'year'   && $sort_order == 'descending') {
		usort($records, "sort_year_descending");
	} elseif ($sort_by == 'year'   && $sort_order == 'ascending')  {
		usort($records, "sort_year_ascending");
	} elseif ($sort_by == 'title'  && $sort_order == 'descending') {
		usort($records, "sort_title_descending");
	} elseif ($sort_by == 'title'  && $sort_order == 'ascending')  {
		usort($records, "sort_title_ascending");
	} elseif ($sort_by == 'author' && $sort_order == 'descending') {
		usort($records, "sort_author_descending");
	} elseif ($sort_by == 'author' && $sort_order == 'ascending')  {
		usort($records, "sort_author_ascending");
	} 	

	return $records;
	
}

function sort_year_descending($a, $b) {
    return strcmp($b['year'], $a['year']);
}

function sort_year_ascending($a, $b) {
    return strcmp($a['year'], $b['year']);
}

function sort_title_descending($a, $b) {
    return strcmp($b['title'], $a['title']);
}

function sort_title_ascending($a, $b) {
    return strcmp($a['title'], $b['title']);
}

function sort_author_descending($a, $b) {
    return strcmp($b['author'], $a['author']);
}

function sort_author_ascending($a, $b) {
    return strcmp($a['author'], $b['author']);
}

function get_sortable_record($rec){

	$outrecord = array();

	// Title
	if ($rec->getField("245") && $rec->getField("245")->getSubfield("a")) {
		$outrecord['title'] = marctrim($rec->getField("245")->getSubfield("a"));
	}
	if ($rec->getField("245") && $rec->getField("245")->getSubfield("b")) {
		$outrecord['title'] .= " " . marctrim($rec->getField("245")->getSubfield("b"));
	}
	
	// Author
	if ($rec->getField("100") && $rec->getField("100")->getSubfield("a")) {
		// Personal author
		$outrecord['author'] = marctrim($rec->getField("100")->getSubfield("a"));
	}
	if ($rec->getField("110") && $rec->getField("110")->getSubfield("a")) {
		// Corporate author
		$outrecord['author'] = marctrim($rec->getField("110")->getSubfield("a"));
	}
	
	// Year
	if ($rec->getField("260") && $rec->getField("260")->getSubfield("c")) {
		preg_match("/\d{4}/", marctrim($rec->getField("260")->getSubfield("c")), $match);
		$outrecord['year'] = $match[0];
	}
	
	// Save the record-as-object for later re-use
	$outrecord['marcobj'] = $rec;
	// Save the record-as-XML for later serialization and caching
	$outrecord['marcxml'] = $rec->toXML();
	
	return $outrecord;
	
}

/*

Sort using XSLT - not used any more
Arguments: 
$marcxml: a string containing records in MARCXML
$sort_by: what criteria to sort on
$sort_order: ascending or descending sort
Applies xslt/simplesort.xslt and returns the result. 

function glitre_xslt_sort($marcxml, $sort_by = 'year', $sort_order = 'descending') {
	
	global $config;
	
	// Check that sort_by and sort_order are valid
	$allowed_sort_by = array('author', 'year', 'title');
	if (!in_array($sort_by, $allowed_sort_by)) {
		exit("Invalid sort_by: $sort_by");	
	}
	$allowed_sort_order = array('descending', 'ascending');
	if (!in_array($sort_order, $allowed_sort_order)) {
		exit("Invalid sort_order: $sort_order");	
	}

	// Create a DOM and load the data
	$xml = new DOMDocument;
	$xml->loadXML($marcxml);
	
	// Create a dom and load the XSLT
	$xsl = new DOMDocument;
	$xsl->load($config['base_path'] . 'xslt/simplesort.xslt', LIBXML_NOCDATA);
		
	// Configure the XSLT processor
	$proc = new XSLTProcessor;
	// Parameters
	$proc->setParameter('', 'sortBy', $sort_by);
	$proc->setParameter('', 'sortOrder', $sort_order);
	// Add the XSLT DOM to the processor
	$proc->importStyleSheet($xsl);
	
	// Do the transformation
	$dom = $proc->transformToDoc($xml);
	
	// Return the XML 
	return $dom->saveXML();
	
}

*/

/*****************************************
STEP THREE - Format the records as desired 
******************************************/

function glitre_format($records, $format, $single_record, $num_of_records, $first_record, $last_record, $content_type = false){

	global $config;

	if (in_array($format, $config['allowed_formats'])) {
		$file = $config['base_path'] . 'formats/' . $format . '.php';
		if (is_file($file)) {
			include($file);	
			if ($single_record) {	
				return format_single($records);
			} else {
				return format($records, $num_of_records, $first_record, $last_record);
			}
		} else {
			// TODO: Log false use of format
			return "$file not found!";
		}
	} else {
		exit("Invalid format!");	
	}

}

function glitre_format_error($err, $format){

	global $config;

	if (list($mode, $type) = explode('.', $format)) {
		// TODO
		$file = $config['base_path'] . 'plugin/' . $type . '.php';
		if (is_file($file)) {
			include($file);	
			return format_error($err);
		} else {
			// TODO: Log false use of format
			return "$file not found!";
		}
	}

}

/********
FUNCTIONS
*********/

/*
Utfører Z39.50-søket og returnerer postene i MARCXML-format, som en streng
*/
function get_z($ccl) {
	
	$out = '';
	
	/*
	hvis ikke ccl-parameteren er oppgitt får man en tom XML-struktur
	tilbake med records som rotnode
	*/
	if (!isset($ccl))
	{
		
		$out .= "<records>\n</records>";
	} 
	/*
	hvis ccl-parameteren er satt får man MARCXML basert på ccl-
	parameteren tilbake
	*/
	else
	{
		
		$out .= "<records>\n";
		/*
		kjører funksjonen yazCclArray som returnerer en array med
		MARCXML-data basert på $query. 
		*/
		$fetch = yazCclArray($ccl);
		
		if ($fetch['error']) {
			return $fetch;
		}
		
		/*
		henter ut verdien med nøkkelen 'result'. det er her selve
		dataene ligger lagret. $fetch-arrayen har også en verdi med
		nøkkel 'hits' som forteller hvor mange records $fetch inneholder
		*/
		$data = $fetch['result'];
		//går gjennom $data-arrayen
		foreach ($data as $record)
		{
			//splitter på nylinjetegn
			$lines = explode("\n", $record);
			/*
			overskriver den første noden i hver record med en
			'<record>'-node. dette gjør at namespacet blir fjernet
			og gjør parsing og transformering av XML lettere
			*/
			$lines[0] = "<record>";
			/*
			samler arrayen $lines til en streng og konverterer til
			utf-8
			*/
			$out .= utf8_encode(implode("\n", $lines));
		}
		$out .= "</records>";
	}
	
	return $out;
	
}

/*
returnerer en array med XML-data, hvert element i arrayen
inneholder XML-data om en record. funksjonen fungerer omtrent
på samme måte som yazCclSearch
*/
function yazCclArray($ccl)
{
	
	global $config;
	$system = $config['lib']['system'];
	
	// Create an array to hold settings for the different systems
	$zopts = array();
	$zopts['aleph'] = array(
	  'syntax' => '', 
	  'yaz_con_opts' => array(
		'piggyback' => true,
	  ),
	);
	$zopts['bibliofil'] = array(
	  'syntax' => 'normarc', 
	  'yaz_con_opts' => array(
		'piggyback' => true,
	  ),
	);
	$zopts['bibsys'] = array(
	  'syntax' => '', 
	  'yaz_con_opts' => array(
		'piggyback' => false,
	  ),
	);
	$zopts['koha'] = array(
	  'syntax' => '', 
	  'yaz_con_opts' => array(
		'piggyback' => true,
	  ),
	);
	$zopts['mikromarc'] = array(
	  'syntax' => 'normarc', 
	  'yaz_con_opts' => array(
		'piggyback' => true,
	  ),
	);
	/*
	$zopts['reindex'] = array(
	  'syntax' => '', 
	  'yaz_con_opts' => array(
		'piggyback' => false,
	  ),
	);
	$zopts['tidemann'] = array(
	  'syntax' => '', 
	  'yaz_con_opts' => array(
		'piggyback' => false,
	  ),	
	);
	*/
		
	$hits = 0;
	
	$type = 'xml';
	
	$id = yaz_connect($config['lib']['z3950'], $zopts[$system]['yaz_con_opts']);
	yaz_element($id, "F");
	yaz_syntax($id, $zopts[$system]['syntax']);
	yaz_range($id, 1, 1);
	
	yaz_ccl_conf($id, get_zconfig());
	$cclresult = array();
	if (!yaz_ccl_parse($id, $ccl, $cclresult)) {
		echo 'Error yaz_ccl_parse: '.$cclresult["errorstring"];
	} else {
		// Norwegian Z39.50 have no or limited support for yaz_sort
		// See http://wiki.biblab.no/index.php/Z39.50%2C_SRU_og_sortering for details
		// yaz_sort($id, "1=31 di");
		$rpn = $cclresult["rpn"];
		// Debug
		yaz_search($id, "rpn", utf8_decode($rpn));
	}
	
	yaz_wait();

	$error = yaz_error($id);
	if (!empty($error))	{
		$yaz_errno = yaz_errno($id);
		// echo "<p>Error yaz_wait: $error ($yaz_errno)</p>";
		$error = array(
		  'error' => true, 
		  'stage' => 'yaz_wait',
		  'desc' => $error, 
		  'num' => $yaz_errno
		);
		return $error;
	} else {
		$hits = yaz_hits($id);
	}
	
	$data = array();
	
	for ($p = 1; $p <= $hits; $p++)
	{
		$rec = yaz_record($id, $p, $type);
		if (empty($rec)) continue;
		$data[] = $rec;
		// If a max number of records is set for this library, respect it - otherwise use the default.
		$records_max = $config['lib']['records_max'] ? $config['lib']['records_max'] : $config['records_max'];
		if ($p == $records_max) {
		  break;
		}
	}
	
	$ret = array("hits" => $hits, "result" => $data);
	
	return $ret;
}

/*
kvalifikatorsetup til yaz_ccl_conf, disse verdiene er hentet fra
BIB-1 attributtsettet funnet her:
http://bibsyst.no/produkter/bibliofil/bib1.php
ti => 1=4
ti = tittel
1 = structure (virker bare med 1 her)
4 = use attribute

KVALIFIKATORFORKLARING:
ti -> tittel
kl -> klassifikasjon (dewey)
fo -> forfatter
år -> år
sp -> språk
eo -> emneord
is -> isbn
tnr -> tittelnummer
*/
function get_zconfig() {

	return $config = array(
		'any' => '1=1016 4=2',
		'tnr' => '1=12 4=2'
	);	
	
}

/*
Utfører SRU-søket og returnerer postene i MARCXML-format, som en streng. 
Argumenter: 
query = det søkebegrepet som det skal søkes etter
limit = maks antall poster som skal returneres
*/
function get_sru($query) {
	
	global $config;
	
	$version = '1.2';
	$recordSchema = 'marcxml';
	$startRecord = 1; 
	$maximumRecords = $config['records_max'];
	
	// Bygg opp SRU-urlen
	$sru_url = $config['lib']['sru'];

	$sru_url .= "?operation=searchRetrieve";
	$sru_url .= "&version=$version";
	$sru_url .= "&query=$query";
	$sru_url .= "&recordSchema=$recordSchema";
	$sru_url .= "&startRecord=$startRecord";
	$sru_url .= "&maximumRecords=$maximumRecords";
	
	// Debug
	// echo($sru_url);

	// Hent SRU-data
	$sru_data = file_get_contents($sru_url) or exit("Feil");
	
	// Massér SRU-dataene slik at vi lett kan behandle dem med funksjonene fra File_MARC
	$sru_data = str_replace("<record xmlns=\"http://www.loc.gov/MARC21/slim\">", "<record>", $sru_data);
	preg_match_all('/(<record>.*?<\/record>)/si', $sru_data, $treff);
	$marcxml = implode("\n\n", $treff[0]);
	$marcxml = '<?xml version="1.0" encoding="utf-8"?>' . "\n<collection>\n$marcxml\n</collection>";
	
	return $marcxml;

}

function massage_input($s) {

	// Fjern komma fra feks Asbjørnsen, Kristin
	$s = str_replace(',', '', $s);
	// Fjern &
	$s = str_replace('&', '', $s);
	
	return $s;
	
}

/*
1. For some reason this: 
$post->getField("zzz")->getSubfield("a")
always gives this: 
[a]: Title...
This function chops off the first 5 characters

2. Some servers return MARC21-style data, with trailing punctuation
Remove that punctuation
*/

function marctrim($s) {
	
	$s = substr($s, 5);
	if (strlen($s) > 2 && !substr_compare($s, ' :', -2, 2)) { $s = substr_replace($s, '', -2, 2);	}
	if (strlen($s) > 2 && !substr_compare($s, ' /', -2, 2)) { $s = substr_replace($s, '', -2, 2);	}
	return $s;
	
}

/*
Run plugins and return their output

In config.php, plugins are activated by lines such as these: 
	$c['active_plugins']['hitlist']['oppnabib'] = 'oppnabib_detail_compact';
	$c['active_plugins']['hitlist']['openlibrary_image'] = 'openlibrary_image_get_image_url_s';
* The name of the second array ('hitlist' in this example) is the name of the location where the info from the   
  plugin will be displayed. Different formats may choose to do different things based on this. 
* The name of the third array ('oppnabib' and 'openlibrary_image') is the name of the plugin, and should
  correspond to a file called e.g. plugins/oppnabib.php
* The value of the expression is the name of the function (provided by the plugin) that should be run to get
  the information that is wanted
  
Arguments: 
$location = the location to run plugins for, as described above
$record = the MARC record that the information should be based on
*/  

function run_plugins($location, $record) {

	global $config;
	$out = '';
		
	// Prepare the plugins
	$plugin_functions = array();
	foreach ($config['active_plugins'][$location] as $plugin => $plugin_function) {
		include_once('../plugins/' . $plugin . '.php');
		$plugin_functions[] = $plugin_function;
	}
	
	// Iterate through the functions for the plugins and display info
	foreach ($plugin_functions as $func) {
		if ($info = $func($record)) {
			$out .= $info;
		}
	}
	
	return $out;
	
};

?>
