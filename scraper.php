<?php
### Goulburn Mulwaree Council scraper
require 'scraperwiki.php'; 
require 'simple_html_dom.php';
date_default_timezone_set('Australia/Sydney');


### Collect all 'hidden' inputs, plus add the current $eventtarget
### $eventtarget is coming from the 'pages' section of the HTML
function buildformdata($dom, $eventTarget, $eventArgument) {
    $a = array();
    foreach ($dom->find("input[type=hidden]") as $input) {
        if ($input->value === FALSE) {
            $a = array_merge($a, array($input->name => ""));
        } else {
            $a = array_merge($a, array($input->name => $input->value));
        }
    }
    $a = array_merge($a, array('__EVENTTARGET' => $eventTarget));
    $a = array_merge($a, array('__EVENTARGUMENT' => $eventArgument));
    
    return $a;
}

$url_base = "https://eservices.goulburn.nsw.gov.au/eServicesProd/P1/eTrack/";

    # Default to 'thisweek', use MORPH_PERIOD to change to 'thismonth' or 'lastmonth' for data recovery
    switch(getenv('MORPH_PERIOD')) {
        case 'thismonth' :
            $period = 'TM';
            break;
        case 'lastmonth' :
            $period = 'LM';
            break;
        case 'thisweek' :
        default         :
            $period = 'TW';
            break;
    }

$da_page  = $url_base . "eTrackApplicationSearchResults.aspx?Field=S&Period=" .$period. "&r=P1.WEBGUEST&f=%24P1.ETR.SEARCH.STW&ApplicationId=DA%2f0001%2f1516";
$info_url = $url_base . "eTrackApplicationDetails.aspx?r=P1.WEBGUEST&f=%24P1.ETR.APPDET.VIW&ApplicationId=";

$mainUrl = scraperWiki::scrape("$da_page");
$dom = new simple_html_dom();
$dom->load($mainUrl);

# Just focus on the 'tr' section of the web page
$dataset = $dom->find("tr[class=normalRow], tr[class=alternateRow]");

# By default, assume it is single page, otherwise, put all pagnation info in an array
$NumPages = count($dom->find('tr[class=pagerRow] a'));
if ($NumPages === 0) { 
    $NumPages = 1;
} else { 
    $NumPages = $NumPages + 1;
    $doPostBackAry = array();
    foreach($dom->find('tr[class=pagerRow] a') as $record) {
        $tempstr = explode(',', $record->href, 2);
        preg_match("/'([^']+)'/", $tempstr[0], $eventTarget);
        preg_match("/'([^']+)'/", $tempstr[1], $eventArgument);
        $doPostBackAry[] = array('__EVENTTARGET' => $eventTarget[1], '__EVENTARGUMENT' => $eventArgument[1]);
    }
}


for ($i = 1; $i <= $NumPages; $i++) {
    echo "Scraping page $i of $NumPages\r\n";
    
    # The usual, look for the data set and if needed, save it
    foreach ($dataset as $record) {
        # Slow way to transform the date but it works
        $date_received = explode(' ', trim($record->find("td", 1)->plaintext));
        $date_received = explode('/', trim($date_received[0]));
        $date_received = "$date_received[2]-$date_received[1]-$date_received[0]";

        # Put all information in an array
        $application = array (
            'council_reference' => trim($record->find('a',0)->plaintext),
            'address'           => preg_replace('/\s+/', ' ', trim($record->find("a", 1)->plaintext)) . ", Australia",
            'description'       => preg_replace('/\s+/', ' ', trim($record->find("td", 2)->plaintext)),
            'info_url'          => $info_url . trim($record->find('a',0)->plaintext),
            'comment_url'       => $info_url . trim($record->find('a',0)->plaintext),
            'date_scraped'      => date('Y-m-d'),
            'date_received'     => date('Y-m-d', strtotime($date_received))
        );

        # Check if record exist, if not, INSERT, else do nothing
        $existingRecords = scraperwiki::select("* from data where `council_reference`='" . $application['council_reference'] . "'");
        if (count($existingRecords) == 0) {
            print ("Saving record " . $application['council_reference'] . "\n");
            # print_r ($application);
            scraperwiki::save(array('council_reference'), $application);
        } else {
            print ("Skipping already saved record " . $application['council_reference'] . "\n");
        }
    }
    
    # If more than a single page, advance to the next page
    if (($NumPages > 1) AND ($i < $NumPages)) {
        $request = array(
            'http'    => array(
            'method'  => "POST",
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query(buildformdata($dom, $doPostBackAry[$i-1]['__EVENTTARGET'], $doPostBackAry[$i-1]['__EVENTARGUMENT'] ))));
        $context = stream_context_create($request);
        $html = file_get_html($da_page, false, $context);
        
        $dataset = $html->find("tr[class=normalRow], tr[class=alternateRow]");        
    }

}

?>
