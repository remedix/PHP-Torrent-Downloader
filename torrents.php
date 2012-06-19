<?
	error_reporting(E_ALL);
	define('DEBUG',	0);
	define('CONFIG'	,"/volume1/homes/admin/torrents/torrents.config.json");
	$config = json_decode(file_get_contents(CONFIG), true);

	$excludeShows = "(brrip|1080p|dvdrip|hebsub|dvdr|480p|WEB\-DL|lies)"; // 'lies' is for house of lies
	$excludeMovies= "(hdtv|dvdrip|1080p|hebsub|dvdr|480p|WEB\-DL)";
	$historyFile = $config['HISTORY_FILE'];
	
	$sources = $config['sources'];
	$shows = "(".implode("|",$config['shows']).")";
	$movies = "(".implode("|",$config['movies']).")";

	// Actual torrent downloaded
	function downloadTorrent($url){
		global $config;
		$path = $config['AUTOTORRENTS_PATH']; 
		$filename = md5($url);
		$cmd = 'wget -q -O "'.$path.$filename.'.torrent" '.$url; 
		exec($cmd."\n");
	}

	$downloadList = array();	
	foreach($sources as $sourceKey=>$url){
		// Support for gzipped xml
		$xml = new SimpleXMLElement("compress.zlib://$url", NULL, TRUE);
				
		// Create a download list
		foreach($xml->channel->item as $i){
			$title = $i->title;
			if (strstr($i->link,'.torrent')){
				$torrent = (string) $i->link;
			} else {
				$torrent =  $i->enclosure['url'];
			}
					
			// Prepare the downloadList for Tv shows
			if (!preg_match("/$excludeShows/is",$title)){
				if (preg_match("/$shows\s(.*?)S([0-9]+)E([0-9]+)(.*?)720p\s/is",$title,$m)){
					$episode = "S".$m[3]."E".$m[4];
					preg_match("/(.*?)$episode(.*?)/is",$title,$cleanTitle);
					$theTitle = ucfirst(trim($cleanTitle[1]));
					$theContent = array($theTitle,$torrent,$episode,$sourceKey);
					$downloadList[$theTitle] = $theContent;
				}
			}

			// Prepare the downloadList for Tv shows
			if (!preg_match("/$excludeMovies/is",$title)){
				if (preg_match("/^$movies\s(.*?)\s720p\s/is",$title,$m)){
					$theTitle = ucwords(strtolower(trim($m[1])));
					$theDate = trim(intval($m[2]));
					//$theTitle = trim($theTitle.' '.$theDate);
					// double check with the size. It should be greater than 2GB
					// TBD
					$theContent = array($theTitle,$torrent,null,$sourceKey);
					$downloadList[$theTitle] = $theContent;
				}
			}
		}
	}
	
	
	if (DEBUG) { print_r($downloadList); exit; }

	// Download all files inthat download list
	$messages = $files = array();
    $lines = file($historyFile,FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($downloadList) foreach($downloadList as $d){
    	$entry = ($d[0]==$d[2]) ? $d[0] : $d[0]." ".$d[2];
    	$download = (in_array($entry,$lines) || in_array($entry,$files)) ? false : true;
    	if ($download) {
    		downloadTorrent($d[1]);
    		$history = fopen($historyFile, 'a');
    		$messages[$entry] = "[".$d[3]."] $entry";
    		$files[] = $entry;
    		fwrite($history,$entry."\n");
    		fclose($history);
    	}
    }

	if ( count($messages)>0 ) {
		system('/bin/echo "'.implode("\n",$messages).'" | /opt/bin/nail -s "Auto Downloads" '.$config['email']);
	}
?>
