<?
	error_reporting(E_ALL);
	if (is_file(dirname ( __FILE__ )."/torrents.config.json")){
		define('CONFIG'	,dirname ( __FILE__ )."/torrents.config.json");		
	} else {
		define('CONFIG'	,dirname ( __FILE__ )."/config.json");
	}
	$config = json_decode(file_get_contents(CONFIG), true);
	
	$movies = sanitizeIMDBTitles(simplexml_load_file($config['movies']));
	$shows = sanitizeIMDBTitles(simplexml_load_file($config['shows']));
	$quality = $config['quality'];
	

	if (isset($argv[1]) && $argv[1]=='--debug'){ 
		define("DEBUG",1);
	} else {
		define("DEBUG",0);
	} 
	
	
	function sanitizeIMDBTitles($feed){
		global $config;
		$list = array();
		foreach($feed->channel->item as $item){
			$list[] = preg_replace("/\s\((.*?)\)/is","",(string) $item->title);
		}
		if (empty($list)){			
			exit; // Stop since imdb retuns blank lists;
		} else {
			$str = "(".implode("|",$list).")";
		}
		$str = str_replace(array("-",":","'","!"),array("\-","","",""),$str);
		// If we have title overwrites then apply them here:
		if (isset($config['overwrites'])) foreach($config['overwrites'] as $key=>$val){
			$str = str_replace($key,$val,$str);						
		}
		return $str;
	}

	// strips characters and finds episodes number
	function getShowsNumber($episode){
		return preg_replace("/[^0-9]/is", "",$episode);	
	}
		
	// Checks whether this is a new download based on the episode number
	function isNewestFile($show,$episode,$lines){
		preg_match_all("/(.*?)S([0-9]+)E([0-9]+)/is",implode("\n",$lines),$matches);
		$trimmed = array_reverse(array_map('strtolower',array_map('trim',$matches[1])));
		$seasons = array_reverse($matches[2]);
		$episodes = array_reverse($matches[3]);

		$k = array_search(strtolower(trim($show)),$trimmed);
		if (strtolower($show) == strtolower($trimmed[$k])){
			if (getShowsNumber($episode)>getShowsNumber($seasons[$k].$episodes[$k])){
				echo (DEBUG) ? $show." \tWill Download: ".getShowsNumber($episode)." is newer than ".getShowsNumber($seasons[$k].$episodes[$k])."\n" : '';
				return false;
			} else if (getShowsNumber($episode)==getShowsNumber($seasons[$k].$episodes[$k])){
				echo (DEBUG) ? $show." \tWill Skip: ".getShowsNumber($episode)." is equal to ".getShowsNumber($seasons[$k].$episodes[$k])."\n" : '';
				return true;			
			} else {
				echo (DEBUG) ? $show." \tWill Skip: ".getShowsNumber($episode)." is older than ".getShowsNumber($seasons[$k].$episodes[$k])."\n" : '';
				return true;
			}
		} else {
			echo (DEBUG) ? $show. " \tWill download for the first time\n" : '';
			return false;
		}
		
	}

	// Actual torrent downloaded
	function downloadTorrent($url){
		global $config;
		$path = $config['autotorrents_path']; 
		$filename = md5($url);
		$cmd = 'wget -q -O "'.$path.$filename.'.torrent" "'.$url.'"'; 
		exec($cmd);
				
		// Let's check if file was downloaded ok! Some trackers failed to delivery content and return "Error: pregmatch"
		$contents = file_get_contents($path.$filename.".torrent");
		if (strstr($contents,"Error:")) {
			// Ok we have an error. Delete the downloaded torrent
			$cmd = 'rm -rf "'.$path.$filename.'.torrent"';
			exec($cmd);
			return false;
		} else {
			return true;
		}
		
	}
	
	if (DEBUG){
		echo $shows."\n\n";
		echo $movies."\n\n";
	}

	$downloadList = array();	
	foreach($config['sources'] as $sourceKey=>$url){
		try {
			// Support for gzipped xml
			$headers = get_headers($url);
			$compressed = false;
			foreach($headers as $h){
				if (strstr($h,"gzip")) $compressed = true;
			}
			
			$xml = ($compressed) ? 
				@new SimpleXMLElement("compress.zlib://$url", NULL, TRUE):
				@new SimpleXMLElement($url, NULL, TRUE);
					
			// Create a download list
			foreach($xml->channel->item as $i){
				// Support for Karmorra RSS
				$title = trim(str_replace(array(".","HD 720p: ")," ",$i->title));
				
				if (strstr($i->link,'.torrent')){
					$torrent = (string) $i->link;
				// If this is a magnet link try to get its details from 'Torrage'
				} elseif (strstr($i->link,'magnet:?xt=') && preg_match("/[0-9a-fA-F]{40}/is",$i->link,$hash)) {
					$torrent = 'http://torrage.ws/torrent/'.$hash[0].'.torrent';
				} else {
					$torrent =  $i->enclosure['url'];
				}
				
				// Prepare the downloadList for Tv shows
				if (!preg_match("/${config['exclude_shows']}/is",$title)){
					if (preg_match("/^$shows(.*?)S([0-9]+)E([0-9]+)(.*?)$quality/is",$title,$m)){
						$episode = "S".$m[3]."E".$m[4];
						preg_match("/(.*?)$episode(.*?)/is",$title,$cleanTitle);
						$theTitle = str_replace(".","",ucfirst(trim($cleanTitle[1])));
						$theContent = array($theTitle,$torrent,$episode,$sourceKey);
						$downloadList[$theTitle] = $theContent;
					}
				}
	
				// Prepare the downloadList for Movies
				if (!preg_match("/${config['exclude_movies']}/is",$title)){
					if (preg_match("/^$movies\s([0-9]{4})(.*?)$quality/is",$title,$m)){
						$theTitle = ucwords(strtolower(trim($m[1])));
						$theDate = trim(intval($m[2]));
						$theContent = array($theTitle,$torrent,$theDate,$sourceKey);
						$downloadList[$theTitle] = $theContent;
					}
				}
			}
		} catch (Exception $e) {
			echo $sourceKey.' contains errors in XML: ',  $e->getMessage(), " - Skipping\n";
		}
	}

	if (DEBUG) { print_r($downloadList); }
		
	
	// Download all files inthat download list
	$messages = $files = array();
	$lines = file($config['history_file'],FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	if ($downloadList) foreach($downloadList as $d){
    	$entry = ($d[0]==$d[2]) ? $d[0] : $d[0]." ".$d[2];
    	// Check if we already have this file on our download list
    	// Also if proper is found, bypass the check and still download it
    	$download = (isNewestFile($d[0],$d[2],$lines) || in_array($entry,$lines) || in_array($entry,$files) || preg_match("/proper/is",$d[1])) ? false : true;
    	if ($download && !DEBUG) {
    		if (downloadTorrent($d[1])){
	    		$history = fopen($config['history_file'], 'a');
	    		$messages[$entry] = "[".$d[3]."] $entry";
	    		$files[] = $entry;
	    		fwrite($history,$entry."\n");
	    		fclose($history);
	    	}
    	}
    }



    if ( count($messages)>0 ) {
		$subject = 'Auto Downloads';
		if (isset($config['mail_method']) && $config['mail_method']=='phpmail'){
			mail($config['email'], $subject, implode("\n",$messages));
		} else {
			system('echo "'.implode("\n",$messages).'" | nail -s "'.$subject.'" '.$config['email']);			
		}
	}	

?>
