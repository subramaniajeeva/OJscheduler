<?php
//define("VENDOR_PATH", __DIR__ . "/../../thirdpartylib/");
require VENDOR_PATH . "domparser/simple_html_dom.php";
//$tc = new Topcoder();
//var_dump($tc->updateContestSchedule());
class Topcoder{
	use Config;
	public function getSchedule($aContestNames, $timeZone, $limit = 0){
//		$this->parseSrm();
		foreach ($aContestNames as $contestName){
			$aContests[$contestName] = $this->convertSrmTo($timeZone, self::conf($contestName), $limit);
		}
		return $aContests;
	}

	// call this class from curl cron job
	public function updateContestSchedule(){
		return $this->parseSrm();	
	}

	private function parseSrm() {
		$year = self::getFormattedCurrentYear();
		$month = self::getFormattedCurrentMonth();
		$currentMonthYear = $month . '_' . $year;
		$start_html = file_get_html('http://community.topcoder.com/tc?module=Static&d1=calendar&d2=' . $currentMonthYear);
		$availableMonths = array();

		foreach ($start_html->find('select[name=month]') as $result) {
			foreach ($result->find('option') as $option)
				$availableMonths[] = $option->value;
		}
		$currentMonthIndex = array_search($currentMonthYear, $availableMonths);
		$aSchedule = array();
		for ($month = $currentMonthIndex; $month < sizeof($availableMonths); $month++)
	//	$month = $currentMonthIndex;
			{
			$mainhtml = file_get_html('http://community.topcoder.com/tc?module=Static&d1=calendar&d2=' . $availableMonths[$month]);
			foreach ($mainhtml->find('td[class=value]') as $result)
			{
				foreach ($result->find('div[class=srm]') as $div)
					foreach ($div->find('strong') as $strong)
					{
						foreach ($strong->find('a') as $a) {
							$aUTCTime = self::parseFromLink($a);
							if ($aUTCTime != null) {
								$aSchedule [$a->innertext] = $aUTCTime;
							}
						}
					}
			}
		}
//		var_dump($aSchedule); //debug
		$oSchedule = ObjectFactory::getGenModelInstance("srm", $aSchedule);
		return self::writeConf($oSchedule, 'srm');
	}
	
	private function convertSrmTo($toTimeZone, $oUTCSchedule){
		$aUTCSchedule = $oUTCSchedule->asArray();
		foreach ($aUTCSchedule as $srm => $aSchedule){
			$aUTCSchedule[$srm]['registrationtime'] = self::toTimeZone($toTimeZone, $aSchedule['registrationtime']);
			$aUTCSchedule[$srm]['contesttime'] = self::toTimeZone($toTimeZone, $aSchedule['contesttime']); 
		}
	return $aUTCSchedule;
	}

	private function parseFromLink($a){
		if (substr($a->href, 0, 4) == "http") 
			$html = file_get_html($a->href);
		else{
			$html = file_get_html('http://community.topcoder.com' . $a->href);
			$currentSrm = $a->innertext;
		}
		foreach ($html->find('td[class=statText]') as $result){
			foreach( $result->find('b') as $b){
				$aSchedule[] = self::sanitize($b->innertext);
			}
		}
		return self::getUTCTime($aSchedule);
	}

	private function toTimeZone($timeZone, $dateTime){
		$utc_date = DateTime::createFromFormat(
			'Y-m-d H:i:s', 
			$dateTime, 
			new DateTimeZone('UTC')
		);
		try {
			$utc_date->setTimeZone(new DateTimeZone($timeZone));
			return $utc_date->format('Y-m-d H:i:s');
		} catch(Exception $e){
			echo $e->getMessage();
		}
	}

	private function getUTCTime($datetime){
		date_default_timezone_set('UTC');
		$date = self::formatDate($datetime[0][0]);
		$timezone = $datetime[1][2];
		$registrationTime = self::formatTime($datetime[1]);
		$contestTime = self::formatTime($datetime[2]);
		if (time() > strtotime($date . ' ' . $contestTime . ' ' . $timezone)) {
			return null;
		}
		$aSchedule['RegistrationTime'] = date('Y-m-d H:i:s',strtotime($date . ' ' . $registrationTime . ' ' . $timezone));
		$aSchedule['ContestTime'] = date('Y-m-d H:i:s',strtotime($date . ' ' . $contestTime . ' ' . $timezone));
		return $aSchedule;
	}

	private function formatDate($date){
		$date = explode(".", $date);
		$month = $date[0];
		$day = $date[1];
		$year = $date[2];
		return $year . "-" . $month . "-" . $day;	
	}	

	private function formatTime($time){
		$hour = $time[0];
		$min = $time[1];
		return date("H:i", strtotime($hour . " " . $min));
	}

	private function sanitize($sValue){
		$sValue = preg_replace('/[^a-zA-Z0-9.: ]/', '', $sValue);
		$sValue = preg_replace('!\s+!', ' ', $sValue);
		return explode(' ', rtrim(ltrim($sValue)));
	}

	private function getFormattedCurrentMonth(){
		return substr(strtolower(date('F')), 0, 3);
	}

	private function getFormattedCurrentYear(){
		return substr(date('Y'), -2);
	}
}
?>
