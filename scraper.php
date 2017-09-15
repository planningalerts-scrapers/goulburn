<?php
### Goulburn Mulwaree Council scraper
require_once 'vendor/autoload.php';
require_once 'vendor/openaustralia/scraperwiki/scraperwiki.php';

use PGuardiario\PGBrowser;
use Sunra\PhpSimple\HtmlDomParser;
date_default_timezone_set('Australia/Sydney');

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

$url_base = "https://eservices.goulburn.nsw.gov.au/eServicesProd/P1/eTrack/";
$da_page  = $url_base . "eTrackApplicationSearchResults.aspx?Field=S&Period=" .$period. "&r=P1.WEBGUEST&f=%24P1.ETR.SEARCH.STW&ApplicationId=DA%2f0001%2f1516";
$info_url = $url_base . "eTrackApplicationDetails.aspx?r=P1.WEBGUEST&f=%24P1.ETR.APPDET.VIW&ApplicationId=";

$browser = new PGBrowser();
$page = $browser->get($da_page);
$dom = HtmlDomParser::str_get_html($page->html);

# By default, assume it is single page, otherwise, calculate how many pages are there
$NumPages = count($dom->find('tr[class=pagerRow] a'));
if ($NumPages === 0) {
    $NumPages = 1;
} else {
    $NumPages = $NumPages + 1;
}

for ($i = 1; $i <= $NumPages; $i++) {
    echo "Scraping page $i of $NumPages\r\n";

    # Just focus on the 'tr' section of the web page
    $dataset = $dom->find("tr[class=normalRow], tr[class=alternateRow]");

    # The usual, look for the data set and if needed, save it
    foreach ($dataset as $record) {
        # Slow way to transform the date but it works
        $date_received = explode(' ', trim($record->find("td", 1)->plaintext));
        $date_received = explode('/', trim($date_received[0]));
        $date_received = "$date_received[2]-$date_received[1]-$date_received[0]";

        # Put all information in an array
        $application = array (
            'council_reference' => trim($record->find('a',0)->plaintext),
            'address'           => preg_replace('/\s+/', ' ', trim($record->find("a", 1)->plaintext)),
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
        $pagelink = $dom->find('tr[class=pagerRow] a', $i-1);
        $form = $page->form();
        $page = $form->doPostBack($pagelink->href);
        $dom = HtmlDomParser::str_get_html($page->html);
    }
}
