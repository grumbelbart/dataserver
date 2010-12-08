<?
class Zotero_Cite {
	private static $citePaperJournalArticleURL = false;
	
	
	/**
	 * Generate JSON for items and send to citeproc-js web service
	 */
	public static function getBibliographyFromCiteServer($items, $style='chicago-note-bibliography', $css='inline') {
		$cslItems = array();
		foreach ($items as $item) {
			$cslItems[] = $item->toCSLItem();
		}
		
		$json = array(
			"items" => $cslItems
		);
		
		$json = json_encode($json);
		
		if (!is_string($style) || !preg_match('/^[a-zA-Z0-9\-]+$/', $style)) {
			throw new Exception("Invalid style", Z_ERROR_CITESERVER_INVALID_STYLE);
		}
		
		$servers = Z_CONFIG::$CITE_SERVERS;
		// Try servers in a random order
		shuffle($servers);
		
		foreach ($servers as $server) {
			$url = "http://$server/?responseformat=json&style=$style";
			
			$start = microtime(true);
			
			$ch = curl_init($url);
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
			curl_setopt($ch, CURLOPT_HTTPHEADER, array("Expect:"));
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
			curl_setopt($ch, CURLOPT_TIMEOUT, 4);
			curl_setopt($ch, CURLOPT_HEADER, 0); // do not return HTTP headers
			curl_setopt($ch, CURLOPT_RETURNTRANSFER , 1);
			$response = curl_exec($ch);
			
			$time = microtime(true) - $start;
			
			$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			
			if ($code == 404) {
				throw new Exception("Invalid style", Z_ERROR_CITESERVER_INVALID_STYLE);
			}
			
			// If no response, try another server
			if (!$response) {
				continue;
			}
			
			break;
		}
		
		if (!$response) {
			throw new Exception("Error generating bibliography");
		}
		
		//
		// Ported from Zotero.Cite.makeFormattedBibliography() in Zotero client
		//
		
		$bib = json_decode($response);
		if (!$bib) {
			throw new Exception("Error generating bibliography");
		}
		$bib = $bib->bibliography;
		$html = $bib[0]->bibstart . implode("", $bib[1]) . $bib[0]->bibend;
		
		if ($css == "none") {
			return $html;
		}
		
		$sfa = "second-field-align";
		
		//if (!empty($_GET['citedebug'])) {
		//	echo "<!--\n";
		//	echo("maxoffset: " . $bib[0]->maxoffset . "\n");
		//	echo("entryspacing: " . $bib[0]->entryspacing . "\n");
		//	echo("linespacing: " . $bib[0]->linespacing . "\n");
		//	echo("hangingindent: " . (isset($bib[0]->hangingindent) ? $bib[0]->hangingindent : "false") . "\n");
		//	echo("second-field-align: " . $bib[0]->$sfa . "\n");
		//	echo "-->\n\n";
		//}
		
		// Validate input
		if (!is_numeric($bib[0]->maxoffset)) throw new Exception("Invalid maxoffset");
		if (!is_numeric($bib[0]->entryspacing)) throw new Exception("Invalid entryspacing");
		if (!is_numeric($bib[0]->linespacing)) throw new Exception("Invalid linespacing");
		
		$maxOffset = (int) $bib[0]->maxoffset;
		$entrySpacing = (int) $bib[0]->entryspacing;
		$lineSpacing = (int) $bib[0]->linespacing;
		$hangingIndent = !empty($bib[0]->hangingindent);
		$secondFieldAlign = !empty($bib[0]->$sfa); // 'flush' and 'margin' are the same for HTML
		
		$xml = new SimpleXMLElement($html);
		
		$multiField = !!$xml->xpath("//div[@class = 'csl-left-margin']");
		
		// One of the characters is usually a period, so we can adjust this down a bit
		$maxOffset = max(1, $maxOffset - 2);
		
		// Force a minimum line height
		if ($lineSpacing <= 1.35) $lineSpacing = 1.35;
		
		$xml['style'] .= "line-height: " . $lineSpacing . "; ";
		
		if ($hangingIndent) {
			if ($multiField && !$secondFieldAlign) {
				throw new Exception("second-field-align=false and hangingindent=true combination is not currently supported");
			}
			// If only one field, apply hanging indent on root
			else if (!$multiField) {
				$xml['style'] .= "padding-left: {$hangingIndent}em; text-indent:-{$hangingIndent}em;";
			}
		}
		
		// csl-entry
		$divs = $xml->xpath("//div[@class = 'csl-entry']");
		$num = sizeOf($divs);
		$i = 0;
		foreach ($divs as $div) {
			$first = $i == 0;
			$last = $i == $num - 1;
			
			if ($entrySpacing) {
				if (!$last) {
					$div['style'] .= "margin-bottom: " . $entrySpacing . "em;";
				}
			}
			
			$i++;
		}
		
		// Padding on the label column, which we need to include when
		// calculating offset of right column
		$rightPadding = .5;
		
		// div.csl-left-margin
		foreach ($xml->xpath("//div[@class = 'csl-left-margin']") as $div) {
			$div['style'] = "float: left; padding-right: " . $rightPadding . "em; ";
			
			// Right-align the labels if aligning second line, since it looks
			// better and we don't need the second line of text to align with
			// the left edge of the label
			if ($secondFieldAlign) {
				$div['style'] .= "text-align: right; width: " . $maxOffset . "em;";
			}
		}
		
		// div.csl-right-inline
		foreach ($xml->xpath("//div[@class = 'csl-right-inline']") as $div) {
			$div['style'] .= "margin: 0 .4em 0 " . ($secondFieldAlign ? $maxOffset + $rightPadding : "0") . "em;";
			
			if ($hangingIndent) {
				$div['style'] .= "padding-left: {$hangingIndent}em; text-indent:-{$hangingIndent}em;";
			}
		}
		
		// div.csl-indent
		foreach ($xml->xpath("//div[@class = 'csl-indent']") as $div) {
			$div['style'] = "margin: .5em 0 0 2em; padding: 0 0 .2em .5em; border-left: 5px solid #ccc;";
		}
		
		return $xml->asXML();
	}
	
	
	//
	// Ported from cite.js in the Zotero client
	//
	
	/**
	 * Mappings for names
	 * Note that this is the reverse of the text variable map, since all mappings should be one to one
	 * and it makes the code cleaner
	 */
	private static $zoteroNameMap = array(
		"author" => "author",
		"editor" => "editor",
		"translator" => "translator",
		"seriesEditor" => "collection-editor",
		"bookAuthor" => "container-author"
	);
	
	/**
	 * Mappings for text variables
	 */
	private static $zoteroFieldMap = array(
		"title" => array("title"),
		"container-title" => array("publicationTitle",  "reporter", "code"), /* reporter and code should move to SQL mapping tables */
		"collection-title" => array("seriesTitle", "series"),
		"collection-number" => array("seriesNumber"),
		"publisher" => array("publisher", "distributor"), /* distributor should move to SQL mapping tables */
		"publisher-place" => array("place"),
		"authority" => array("court"),
		"page" => array("pages"),
		"volume" => array("volume"),
		"issue" => array("issue"),
		"number-of-volumes" => array("numberOfVolumes"),
		"number-of-pages" => array("numPages"),
		"edition" => array("edition"),
		"version" => array("version"),
		"section" => array("section"),
		"genre" => array("type", "artworkSize"), /* artworkSize should move to SQL mapping tables, or added as a CSL variable */
		"medium" => array("medium"),
		"archive" => array("archive"),
		"archive_location" => array("archiveLocation"),
		"event" => array("meetingName", "conferenceName"), /* these should be mapped to the same base field in SQL mapping tables */
		"event-place" => array("place"),
		"abstract" => array("abstractNote"),
		"URL" => array("url"),
		"DOI" => array("DOI"),
		"ISBN" => array("ISBN"),
		"call-number" => array("callNumber"),
		"note" => array("extra"),
		"number" => array("number"),
		"references" => array("history"),
		"shortTitle" => array("shortTitle"),
		"journalAbbreviation" => array("journalAbbreviation")
	);
	
	private static $zoteroDateMap = array(
		"issued" => "date",
		"accessed" => "accessDate"
	);
	
	private static $zoteroTypeMap = array(
		'book' => "book",
		'bookSection' => "chapter",
		'journalArticle' => "article-journal",
		'magazineArticle' => "article-magazine",
		'newspaperArticle' => "article-newspaper",
		'thesis' => "thesis",
		'encyclopediaArticle' => "entry-encyclopedia",
		'dictionaryEntry' => "entry-dictionary",
		'conferencePaper' => "paper-conference",
		'letter' => "personal_communication",
		'manuscript' => "manuscript",
		'interview' => "interview",
		'film' => "motion_picture",
		'artwork' => "graphic",
		'webpage' => "webpage",
		'report' => "report",
		'bill' => "bill",
		'case' => "legal_case",
		'hearing' => "bill",				// ??
		'patent' => "patent",
		'statute' => "bill",				// ??
		'email' => "personal_communication",
		'map' => "map",
		'blogPost' => "webpage",
		'instantMessage' => "personal_communication",
		'forumPost' => "webpage",
		'audioRecording' => "song",		// ??
		'presentation' => "speech",
		'videoRecording' => "motion_picture",
		'tvBroadcast' => "broadcast",
		'radioBroadcast' => "broadcast",
		'podcast' => "song",			// ??
		'computerProgram' => "book"		// ??
	);
	
	private static $quotedRegexp = '/^".+"$/';
	
	public static function retrieveItem($zoteroItem) {
		if (!$zoteroItem) {
			throw new Exception("Zotero item not provided");
		}
		
		// don't return URL or accessed information for journal articles if a
		// pages field exists
		$itemType = Zotero_ItemTypes::getName($zoteroItem->itemTypeID);
		$cslType = isset(self::$zoteroTypeMap[$itemType]) ? self::$zoteroTypeMap[$itemType] : false;
		if (!$cslType) $cslType = "article";
		$ignoreURL = (($zoteroItem->getField("accessDate", true, true, true) || $zoteroItem->getField("url", true, true, true)) &&
				in_array($itemType, array("journalArticle", "newspaperArticle", "magazineArticle"))
				&& $zoteroItem->getField("pages", false, false, true)
				&& self::$citePaperJournalArticleURL);
		
		$cslItem = array(
			'id' => $zoteroItem->id,
			'type' => $cslType
		);
		
		// get all text variables (there must be a better way)
		// TODO: does citeproc-js permit short forms?
		foreach (self::$zoteroFieldMap as $variable=>$fields) {
			if ($variable == "URL" && $ignoreURL) continue;
			
			foreach($fields as $field) {
				$value = $zoteroItem->getField($field, false, true, true);
				if ($value !== "") {
					// Strip enclosing quotes
					if (preg_match(self::$quotedRegexp, $value)) {
						$value = substr($value, 1, strlen($value)-2);
					}
					$cslItem[$variable] = $value;
					break;
				}
			}
		}
		
		// separate name variables
		$authorID = Zotero_CreatorTypes::getPrimaryIDForType($zoteroItem->itemTypeID);
		$creators = $zoteroItem->getCreators();
		foreach ($creators as $creator) {
			if ($creator['creatorTypeID'] == $authorID) {
				$creatorType = "author";
			}
			else {
				$creatorType = Zotero_CreatorTypes::getName($creator['creatorTypeID']);
			}
			
			$creatorType = isset(self::$zoteroNameMap[$creatorType]) ? self::$zoteroNameMap[$creatorType] : false;
			if (!$creatorType) continue;
			
			$nameObj = array('family' => $creator['ref']->lastName, 'given' => $creator['ref']->firstName);
			
			if (isset($cslItem[$creatorType])) {
				$cslItem[$creatorType][] = $nameObj;
			}
			else {
				$cslItem[$creatorType] = array($nameObj);
			}
		}
		
		// get date variables
		foreach (self::$zoteroDateMap as $key=>$val) {
			$date = $zoteroItem->getField($val, false, true, true);
			if ($date) {
				$cslItem[$key] = array("raw" => $date);
				continue;
				
				
				$date = Zotero_Date::strToDate($date);
				
				if (!empty($date['part']) && !$date['month']) {
					// if there's a part but no month, interpret literally
					$cslItem[$variable] = array("literal" => $date['part']);
				}
				else {
					// otherwise, use date-parts
					$dateParts = array();
					if ($date['year']) {
						$dateParts[] = $date['year'];
						if ($date['month']) {
							$dateParts[] = $date['month'] + 1; // Mimics JS
							if ($date['day']) {
								$dateParts[] = $date['day'];
							}
						}
					}
					$cslItem[$key] = array("date-parts" => array($dateParts));
				}
			}
		}
		
		return $cslItem;
	}
	
	/*Zotero.Cite.System.getAbbreviations = function() {
		return {};
	}*/
}
?>