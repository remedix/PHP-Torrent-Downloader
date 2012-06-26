<?
	error_reporting(E_ALL);
	define('DEBUG',	0);
	define('CONFIG'	,dirname ( __FILE__ )."/torrents.config.json");
	$config = json_decode(file_get_contents(CONFIG), true);

	$excludeShows = $config['exclude_shows'];
	$excludeMovies = $config['exclude_movies'];
	
	$historyFile = $config['history_file'];

	$sources = $config['sources'];
	$shows = "(".implode("|",$config['shows']).")";
	$movies = "(".implode("|",$config['movies']).")";

	// Actual torrent downloaded
	function downloadTorrent($url){
		global $config;
		$path = $config['autotorrents_path']; 
		$filename = md5($url);
		$cmd = 'wget -q -O "'.$path.$filename.'.torrent" '.$url; 
		exec($cmd."\n");
	}

	$downloadList = array();	
	foreach($sources as $sourceKey=>$url){
		try {
			// Support for gzipped xml
			$xml = @new SimpleXMLElement("compress.zlib://$url", NULL, TRUE);
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
		} catch (Exception $e) {
			echo $sourceKey.' contains errors in XML: ',  $e->getMessage(), " - Skipping\n";
		}
	}


	if (DEBUG) { print_r($downloadList); exit; }
	
	// Download all files inthat download list
	$messages = $files = array();
	$lines = file($historyFile,FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	if ($downloadList) foreach($downloadList as $d){
    	$entry = ($d[0]==$d[2]) ? $d[0] : $d[0]." ".$d[2];
    	// Check if we already have this file on our download list
    	// Also if proper is found, bypass the check and still download it
    	$download = (in_array($entry,$lines) || in_array($entry,$files) || preg_match("/proper/is",$d[1])) ? false : true;
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
