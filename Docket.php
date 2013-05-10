<?php
	// @@@@@@@@ TODO - Add extra columns to the arrest column to see if expungement, summary, ard, etc...
	// @todo make aliases work automatically - they should be able to be read off of the case summary
	// @todo think about whether I want to have the charges array check to see if a duplicate
	//		 charge is being added and prevent duplicate charges.  A good example of this is if
	//       a charge is "replaced by information" and then later there is a disposition. 
	// 		 we probably don't want both the replaced by information and the final disposition on 
	// 		 the petition.  This is especially true if the finally dispoition is Guilty

require_once("Charge.php");
require_once("Person.php");
require_once("utils.php");
require_once("config.php");

class Docket
{

	private $mdjDistrictNumber;
	private $county;
	private $OTN;
	private $DC;
	private $docketNumber = array();
	private $arrestingOfficer;
	private $arrestingAgency;
	private $arrestDate;
	private $complaintDate;
	private $judge;
	private $DOB;
	private $dispositionDate;
	private $firstName;
	private $lastName;
	private $charges = array();
	private $costsTotal;
	private $costsPaid;
	private $costsCharged;
	private $costsAdjusted;
	private $bailTotal;
	private $bailCharged;
	private $bailPaid;
	private $bailAdjusted;
	private $bailTotalTotal;
	private $bailChargedTotal;
	private $bailPaidTotal;
	private $bailAdjustedTotal;
	private $isCP;
	private $isCriminal;
	private $isARDExpungement;
	private $isExpungement;
	private $isRedaction;
	private $isHeldForCourt;
	private $isSummaryArrest = FALSE;
	private $isArrestSummaryExpungement;
	private $isArrestOver70Expungement;
	private $pdfFile;
	
	// isMDJ = 0 if this is not an mdj case at all, 1 if this is an mdj case and 2 if this is a CP case that decended from MDJ
	private $isMDJ = 0;
		
	protected static $unknownInfo = "N/A";
	protected static $unknownOfficer = "Unknown officer";
	
	protected static $mdjDistrictNumberSearch = "/Magisterial District Judge\s(.*)/i";
	protected static $countySearch = "/\sof\s(\w+)\sCOUNTY/i";
	protected static $mdjCountyAndDispositionDateSearch = "/County:\s+(.*)\s+Disposition Date:\s+(.*)/";
	protected static $OTNSearch = "/OTN:\s+(\D(\s)?\d+(\-\d)?)/";
	protected static $DCSearch = "/District Control Number\s+(\d+)/";
	protected static $docketSearch = "/Docket Number:\s+((MC|CP)\-\d{2}\-(\D{2})\-\d*\-\d{4})/";
	protected static $mdjDocketSearch = "/Docket Number:\s+(MJ\-\d{5}\-(\D{2})\-\d*\-\d{4})/";
	protected static $arrestingAgencyAndOfficerSearch = "/Arresting Agency:\s+(.*)\s+Arresting Officer: (\D+)/";
	protected static $mdjArrestingOfficerSearch = "/^\s*Arresting Officer (\D+)\s*$/";
	protected static $mdjArrestingAgencyAndArrestDateSearch = "/Arresting Agency:\s+(.*)\s+Arrest Date:\s+(\d{1,2}\/\d{1,2}\/\d{4})?/";
	protected static $arrestDateSearch = "/Arrest Date:\s+(\d{1,2}\/\d{1,2}\/\d{4})/";
	protected static $complaintDateSearch = "/Complaint Date:\s+(\d{1,2}\/\d{1,2}\/\d{4})/";
	protected static $mdjComplaintDateSearch = "/Issue Date:\s+(\d{1,2}\/\d{1,2}\/\d{4})/";
	protected static $finalIssuingAuthoritySearch = "/Final Issuing Authority:\s+(.*)/";
	protected static $judgeAssignedSearch = "/Judge Assigned:\s+(.*)\s+(Date Filed|Issue Date):/";
	protected static $crossCourtDocketSearch = "/Cross Court Docket Nos:\s+(.*)\s*$/"; 
	protected static $dateFiledSearch = "/Date Filed:\s+(\d{1,2}\/\d{1,2}\/\d{4})/";
	protected static $lowerCourtDocketSearch = "/Lower Court Docket No:\s+(.*)\s*/";
	protected static $initialIssuingAuthoritySearch = "/Initial Issuing Authority:\s+(.*)\s{2,}.*/";
	protected static $cityStateZipSearch = "/City\/State\/Zip:\s+(.*), (\w{2})\s+(\d{5})/";
	
	#note that the alias name search only captures a maximum of six aliases.  
	# This is because if you do this: /^Alias Name\r?\n(?:(^.+)\r?\n)*/m, only the last alias will be stored in $1.  
	# What a crock!  I can't figure out a way around this
	protected static $aliasNameSearch = "/^Alias Name\r?\n(?:(^.+)\r?\n)(?:(^.+)\r?\n)?(?:(^.+)\r?\n)?(?:(^.+)\r?\n)?(?:(^.+)\r?\n)?(?:(^.+)\r?\n)?/m"; 
	
	// there are two special judge situations that need to be covered.  The first is that MDJ dockets sometimes say
	// "magisterial district judge xxx".  In that case, there could be overflow to the next line.  We want to capture that
	// overflow.  The second is that sometimes the judge assigned says "migrated judge".  We want to make sure we catch that.
	protected static $magisterialDistrictJudgeSearch = "/Magisterial District Judge (.*)/";
	protected static $judgeSearchOverflow = "/^\s+(\w+\s*\w*)\s*$/";
	protected static $migratedJudgeSearch = "/migrated/i";
	protected static $DOBSearch = "/Date Of Birth:?\s+(\d{1,2}\/\d{1,2}\/\d{4})/i";
	protected static $nameSearch = "/^Defendant\s+(.*), (.*)/";

	// ($1 = charge, $2 = disposition, $3 = grade, $4 = code section
	protected static $chargesSearch = "/\d\s+\/\s+(.*[^Not])\s+(Not Guilty|Guilty|Nolle Prossed|Nolle Prossed \(Case Dismissed\)|Nolle Prosequi - Administrative|Guilty Plea|Guilty Plea - Negotiated|Guilty Plea - Non-Negotiated|Withdrawn|Withdrawn - Administrative|Charge Changed|Held for Court|Community Court Program|Dismissed - Rule 1013 \(Speedy|Dismissed - Rule 600 \(Speedy|Dismissed - LOP|Dismissed - LOE|Dismissed - Rule 546|Dismissed|Demurrer Sustained|ARD - County Open|ARD - County|ARD|Transferred to Another Jurisdiction|Transferred to Juvenile Division|Quashed|Summary Diversion Completed|Judgment of Acquittal \(Prior to)\s+(\w{0,2})\s+(\w{1,2}\s?\247\s?\d+(\-|\247|\w+)*)/"; // removed "Replacement by Information"
	
	// $1 = code section, $3 = grade, $4 = charge, $5 = offense date, $6 = disposition
	protected static $mdjChargesSearch = "/^\s*\d\s+((\w|\d|\s(?!\s)|\-|\247|\*)+)\s{2,}(\w{0,2})\s{2,}([\d|\D]+)\s{2,}(\d{1,2}\/\d{1,2}\/\d{4})\s{2,}(\D{2,})/";
	
	protected static $chargesSearchOverflow = "/^\s+(\w+\s*\w*)\s*$/";
	// disposition date can appear in two different ways (that I have found) and a third for MDJ cases:
	// 1) it can appear on its own line, on a line that looks like: 
	//    Status   mm/dd/yyyy    Final  Disposition
	//    Trial   mm/dd/yyyy    Final  Disposition
	//    Preliminary Hearing   mm/dd/yyyy    Final  Disposition
	//    Migrated Dispositional Event   mm/dd/yyyy    Final  Disposition
	// 2) on the line after the charge disp
	// 3) for MDJ cases, disposition date appears on a line by itself, so it is easier to find
	protected static $dispDateSearch = "/(Status|Status of Restitution|Status - Community Court|Status Listing|Migrated Dispositional Event|Trial|Preliminary Hearing|Pre-Trial Conference)\s+(\d{1,2}\/\d{1,2}\/\d{4})\s+Final Disposition/";
	protected static $dispDateSearch2 = "/(.*)\s(\d{1,2}\/\d{1,2}\/\d{4})/";	
	
	// this is a crazy one.  Basically matching whitespace then $xx.xx then whitespace then 
	// -$xx.xx, etc...  The fields show up as Assesment, Payment, Adjustments, Non-Monetary, Total
	protected static $costsSearch = "/Totals:\s+\\$([\d\,]+\.\d{2})\s+-?\\$([\d\,]+\.\d{2})\s+-?\\$([\d\,]+\.\d{2})\s+-?\\$([\d\,]+\.\d{2})\s+-?\\$([\d\,]+\.\d{2})/";
	protected static $bailSearch = "/Bail.+\\$([\d\,]+\.\d{2})\s+-?\\$([\d\,]+\.\d{2})\s+-?\\$([\d\,]+\.\d{2})\s+-?\\$([\d\,]+\.\d{2})\s+-?\\$([\d\,]+\.\d{2})/";
	public function __construct () {}
	
	
	//getters
	public function getMDJDistrictNumber() { return $this->mdjDistrictNumber; }
	public function getCounty() { if (!isset($this->county)) $this->setCounty(self::$unknownInfo); return $this->county; }
	public function getOTN() { if (!isset($this->OTN)) $this->setOTN(self::$unknownInfo); return $this->OTN; }
	public function getDC() { if (!isset($this->DC)) $this->setDC(self::$unknownInfo); return $this->DC; }
	public function getDocketNumber() { return $this->docketNumber; }
	public function getArrestingOfficer() { if (!isset($this->arrestingOfficer)) $this->setArrestingOfficer(self::$unknownOfficer); return $this->arrestingOfficer; }
	public function getArrestingAgency() { return $this->arrestingAgency; }
	public function getArrestDate() { return $this->arrestDate; }
	public function getComplaintDate() { return $this->complaintDate; }
	//  getDispositionDate() exists elsewhere
	public function getJudge() { return $this->judge; }
	public function getDOB() { return $this->DOB; }
	public function getfirstName() { return $this->firstName; }
	public function getLastName() { return $this->lastName; }
	public function getCharges() { return $this->charges; }
	public function getCostsTotal() { if (!isset($this->costsTotal)) $this->setCostsTotal("0"); return $this->costsTotal; }
	public function getCostsPaid()  { if (!isset($this->costsPaid)) $this->setCostsPaid("0");return $this->costsPaid; }
	public function getCostsCharged() { if (!isset($this->costsCharged)) $this->setCostsCharged("0"); return $this->costsCharged; }
	public function getCostsAdjusted()  { if (!isset($this->costsAdjusted)) $this->setCostsAdjusted("0");return $this->costsAdjusted; }
	public function getBailTotal() { if (!isset($this->bailTotal)) $this->setBailTotal("0"); return $this->bailTotal; }
	public function getBailPaid()  { if (!isset($this->bailPaid)) $this->setBailPaid("0");return $this->bailPaid; }
	public function getBailCharged() { if (!isset($this->bailCharged)) $this->setBailCharged("0"); return $this->bailCharged; }
	public function getBailAdjusted()  { if (!isset($this->bailAdjusted)) $this->setBailAdjusted("0");return $this->bailAdjusted; }
	public function getBailTotalTotal() { if (!isset($this->bailTotalTotal)) $this->setBailTotalTotal("0"); return $this->bailTotalTotal; }
	public function getBailPaidTotal()  { if (!isset($this->bailPaidTotal)) $this->setBailPaidTotal("0");return $this->bailPaidTotal; }
	public function getBailChargedTotal() { if (!isset($this->bailChargedTotal)) $this->setBailChargedTotal("0"); return $this->bailChargedTotal; }
	public function getBailAdjustedTotal()  { if (!isset($this->bailAdjustedTotal)) $this->setBailAdjustedTotal("0");return $this->bailAdjustedTotal; }
	public function getIsCP()  { return $this->isCP; }
	public function getIsCriminal()  { return $this->isCriminal; }
	public function getIsARDExpungement()  { return $this->isARDExpungement; }
	public function getIsExpungement()  { return $this->isExpungement; }
	public function getIsRedaction()  { return $this->isRedaction; }
	public function getIsHeldForCourt()  { return $this->isHeldForCourt; }
	public function getIsSummaryArrest()  { return $this->isSummaryArrest; }
	public function getIsMDJ() { return $this->isMDJ; }
	public function getPDFFile() { return $this->pdfFile;}
		
	//setters
	public function setMDJDistrictNumber($mdjDistrictNumber) { $this->mdjDistrictNumber = $mdjDistrictNumber; }
	public function setCounty($county) { $this->county = ucwords(strtolower($county)); }
	public function setOTN($OTN) 
	{ 
		// OTN could have a "-" before the last digit.  It could also have unnecessary spaces.  
		// We want to chop that off since it isn't important and messes up matching of OTNs
		$this->OTN = str_replace(" ", "", str_replace("-", "", $OTN));
	}
	public function setDC($DC) { $this->DC = $DC; }
	public function setDocketNumber($docketNumber) { $this->docketNumber = $docketNumber; }
	public function setIsSummaryArrest($isSummaryArrest)  { $this->isSummaryArrest = $isSummaryArrest; } 
	public function setArrestingOfficer($arrestingOfficer) {  $this->arrestingOfficer = ucwords(strtolower($arrestingOfficer)); }
	
	// when we set the arresting agency, replace any string "PD" with "Police Dept"
	public function setArrestingAgency($arrestingAgency) {  $this->arrestingAgency = preg_replace("/\bpd\b/i", "Police Dept",$arrestingAgency); }
	
	public function setArrestDate($arrestDate) {  $this->arrestDate = $arrestDate; }
	public function setComplaintDate($complaintDate) {  $this->complaintDate = $complaintDate; }
	public function setJudge($judge) { $this->judge = $judge; }
	public function setDispositionDate($dispositionDate) { $this->dispositionDate = $dispositionDate; }
	public function setDOB($DOB) { $this->DOB = $DOB; }
	public function setFirstName($firstName) { $this->firstName = $firstName; }
	public function setLastName($lastName) { $this->lastName = $lastName; }
	public function setCharges($charges) {  $this->charges = $charges; }
	public function setCostsTotal($costsTotal) {  $this->costsTotal = $costsTotal; }
	public function setCostsPaid($costsPaid)  {  $this->costsPaid = $costsPaid; }
	public function setCostsCharged($costsCharged) {  $this->costsCharged = $costsCharged; }
	public function setCostsAdjusted($costsAdjusted)  {  $this->costsAdjusted = $costsAdjusted; }
	public function setBailTotal($bailTotal) {  $this->bailTotal = $bailTotal; }
	public function setBailPaid($bailPaid)  {  $this->bailPaid = $bailPaid; }
	public function setBailCharged($bailCharged) {  $this->bailCharged = $bailCharged; }
	public function setBailAdjusted($bailAdjusted)  {  $this->bailAdjusted = $bailAdjusted; }
	public function setBailTotalTotal($bailTotal) {  $this->bailTotalTotal = $bailTotal; }
	public function setBailPaidTotal($bailPaid)  {  $this->bailPaidTotal = $bailPaid; }
	public function setBailChargedTotal($bailCharged) {  $this->bailChargedTotal = $bailCharged; }
	public function setBailAdjustedTotal($bailAdjusted)  {  $this->bailAdjustedTotal = $bailAdjusted; }
	public function setIsCP($isCP)  {  $this->isCP = $isCP; }
	public function setIsCriminal($isCriminal)  {  $this->isCriminal = $isCriminal; }
	public function setIsARDExpungement($isARDExpungement)  {  $this->isARDExpungement = $isARDExpungement; }
	public function setIsExpungement($isExpungement)  {  $this->isExpungement = $isExpungement; }
	public function setIsRedaction($isRedaction)  {  $this->isRedaction = $isRedaction; }
	public function setIsArrestSummaryExpungement($isSummaryExpungement) { $this->isArrestSummaryExpungement = $isSummaryExpungement; }
	public function setIsArrestOver70Expungement($isOver70Expungement) { $this->isArrestOver70Expungement = $isOver70Expungement; }
	public function setIsHeldForCourt($isHeldForCourt)  {  $this->isHeldForCourt = $isHeldForCourt; }
	public function setIsMDJ($isMDJ)  {  $this->isMDJ = $isMDJ; }
	public function setPDFFile($pdfFile) { $this->pdfFile = $pdfFile; }

	// add a Bail amount to an already created bail figure
	public function addBailTotal($bailTotal) 
	{  
		$this->bailTotal = $this->getBailTotal() + $bailTotal; 
		$this->bailTotalTotal = $this->getBailTotalTotal() + $bailTotal; 
	}
	public function addBailPaid($bailPaid)  
	{  
		$this->bailPaid = $this->getBailPaid() + $bailPaid; 
		$this->bailPaidTotal = $this->getBailPaidTotal() + $bailPaid; 
	}
	public function addBailCharged($bailCharged) 
	{  
		$this->bailCharged = $this->getBailCharged() + $bailCharged; 
		$this->bailChargedTotal = $this->getBailChargedTotal() + $bailCharged; 
	}
	public function addBailAdjusted($bailAdjusted)  
	{  
		$this->bailAdjusted = $this->getBailAdjusted() + $bailAdjusted; 
		$this->bailAdjustedTotal = $this->getBailAdjustedTotal() + $bailAdjusted; 
	}
	
	// push a single chage onto the charge array
	public function addCharge($charge) {  $this->charges[] = $charge; }
	
	
	// @return the first docket number on the array, which should be the CP or lead docket num
	public function getFirstDocketNumber() 
	{
		
		if (count($this->getDocketNumber()) > 0)
		{
			$docketNumber = $this->getDocketNumber();
			return $docketNumber[0];
		}
		else
			return NULL;
	}
	
	// @return true if the arrestRecordFile is a docket sheet, false if it isn't
	public function isDocketSheet($arrestRecordFile)
	{
		if (preg_match("/Docket/i", $arrestRecordFile))
			return true;
		else
			return false;
	}

	// @return true if the arrestRecordFile is a docket sheet, false if it isn't
	public function isMDJDocketSheet($arrestRecordFile)
	{
		if (preg_match("/Magisterial District Judge/i", $arrestRecordFile))
			return true;
		else
			return false;
	}

	
	// reads in a record and sets all of the relevant variable.
	// assumes that the record is an array of lines, read through the "file" function.
	// the file should be created by running pdftotext.exe on a pdf of the defendant's arrest.
	// this does not read the summary.
	public function readArrestRecord($arrestRecordFile)
	{
		// check to see if this is an MDJ docket sheet.  If it is, we have to
		// read it a bit differently in places
		if ($this->isMDJDocketSheet($arrestRecordFile[0]))
		{
			$this->setIsMDJ(1);
			if (preg_match(self::$mdjDistrictNumberSearch, $arrestRecordFile[0], $matches))
				$this->setMDJDistrictNumber(trim($matches[1]));

		}

		foreach ($arrestRecordFile as $line_num => $line)
		{
			// print "$line_num: $line<br/>";
			
			// do all of the searches that are common to the MDJ and CP/MC docket sheets
								
			// figure out which county we are in
			if (preg_match(self::$countySearch, $line, $matches))
				$this->setCounty(trim($matches[1]));
			elseif (preg_match(self::$mdjCountyAndDispositionDateSearch, $line, $matches))
			{
				$this->setCounty(trim($matches[1]));
				$this->setDispositionDate(trim(($matches[2])));
			}
				
			// find the docket Number
			else if (preg_match(self::$docketSearch, $line, $matches))
			{
				$this->setDocketNumber(array(trim($matches[1])));

				// we want to set this to be a summary offense if there is an "SU" in the 
				// docket number.  The normal docket number looks like this:
				// CP-##-CR-########-YYYY or CP-##-SU-#######-YYYYYY; the latter is a summary
				if (trim($matches[3]) == "SU")
					$this->setIsSummaryArrest(TRUE);
				else
					$this->setIsSummaryArrest(FALSE);
			}
			else if (preg_match(self::$mdjDocketSearch, $line, $matches))
			{
				$this->setDocketNumber(array(trim($matches[1])));
			}
			
			else if (preg_match(self::$OTNSearch, $line, $matches))
				$this->setOTN(trim($matches[1]));

			else if (preg_match(self::$DCSearch, $line, $matches))
				$this->setDC(trim($matches[1]));
			
			// find the arrest date.  First check for agency and arrest date (mdj dockets).  Then check for arrest date alone
			else if (preg_match(self::$mdjArrestingAgencyAndArrestDateSearch, $line, $matches))
			{
				$this->setArrestingAgency(trim($matches[1]));
				if (isset($matches[2]))
					$this->setArrestDate(trim($matches[2]));
			}
				
			else if (preg_match(self::$arrestDateSearch, $line, $matches))
				$this->setArrestDate(trim($matches[1]));

			// find the complaint date
			else if (preg_match(self::$complaintDateSearch, $line, $matches))
				$this->setComplaintDate(trim($matches[1]));

			// for non-mdj, aresting agency and officer are on the same line, so we have to find
			// them together and deal with them together.
			else if (preg_match(self::$arrestingAgencyAndOfficerSearch, $line, $matches))
			{
				// first set the arresting agency
				$this->setArrestingAgency(trim($matches[1]));

				// then deal with the arresting officer
				$ao = trim($matches[2]);
				
				// if there is no listed affiant or the affiant is "Affiant" then set arresting 
				// officer to "Unknown Officer"
				if ($ao == "" || !(stripos("Affiant", $ao)===FALSE))
					$ao = self::$unknownOfficer;
				$this->setArrestingOfficer($ao);
			}	

			// mdj dockets have the arresting office on a line by himself, as last name, first
			else if (preg_match(self::$mdjArrestingOfficerSearch, $line, $matches))
			{
				$officer = trim($matches[1]);
				// find the comma and switch the order of the names
				$officerArray = explode(",", $officer, 2);
				if (sizeof($officerArray) > 0)
					$officer = trim($officerArray[1]) . " " . trim($officerArray[0]);
				
				$this->setArrestingOfficer($officer);				
			}
				
			
			// the judge name can appear in multiple places.  Start by checking to see if the
			// judge's name appears in the Judge Assigned field.  If it does, then set it.
			// Later on, we'll check in the "Final Issuing Authority" field.  If it appears there
			// and doesn't show up as "migrated," we'll reassign the judge name.
			else if (preg_match(self::$judgeAssignedSearch, $line, $matches))
			{
				$judge = trim($matches[1]);
				
				// check to see if this line has "magisterial district judge" in it.  If it does, 
				// lop off that phrase and then check the next line to see if anything important is on it
				if (preg_match(self::$magisterialDistrictJudgeSearch, $judge, $judgeMatch))
				{
					// first catch the judge
					$judge = trim($judgeMatch[1]);
					
					// then check the next line to see if there is anything of interest
					$i = $line_num+1;
					if (preg_match(self::$judgeSearchOverflow, $arrestRecordFile[$i], $judgeOverflowMatch))
						$judge .= " " . trim($judgeOverflowMatch[1]);					
				}
			
				if (!preg_match(self::$migratedJudgeSearch, $judge, $junk))
					$this->setJudge($judge);
					
				// if this is an mdj docket, the complaint date will also be on this same line, so we want to search for that as well
				if (preg_match(self::$mdjComplaintDateSearch, $line, $matches))
				$this->setComplaintDate(trim($matches[1]));
			}
			
			else if (preg_match(self::$judgeSearch, $line, $matches))
			{
				// make sure the judge field isn't blank or doesn't equal "migrated"
				if (!preg_match(self::$migratedJudgeSearch, $matches[1], $junk) && trim($matches[1]) != "")
					$this->setJudge(trim($matches[1]));
			}
			
			
			else if  (preg_match(self::$DOBSearch, $line, $matches))
				$this->setDOB(trim($matches[1]));
			
			else if (preg_match(self::$nameSearch, $line, $matches))
			{
				$this->setFirstName(trim($matches[2]));
				$this->setLastName(trim($matches[1]));
			}

			else if (preg_match(self::$dispDateSearch, $line, $matches))
				$this->setDispositionDate($matches[2]);
				
			// charges can be spread over two lines sometimes; we need to watch out for that
			else if (preg_match(self::$chargesSearch, $line, $matches))
			{
				
				$charge = trim($matches[1]);
				// we need to check to see if the next line has overflow from the charge.
				// this happens on long charges, like possession of controlled substance
				$i = $line_num+1;
				
				if (preg_match(self::$chargesSearchOverflow, $arrestRecordFile[$i], $chargeMatch))
				{
					$charge .= " " . trim($chargeMatch[1]);
					$i++;
				}
	
			
				// need to grab the disposition date as well, which is on the next line
				if (isset($this->dispositionDate))
					$dispositionDate = $this->getDispositionDate();
				else if (preg_match(self::$dispDateSearch2, $arrestRecordFile[$i], $dispMatch))
					// set the date;
					$dispositionDate = $dispMatch[2];
				else
					$dispositionDate = NULL;
					
				$charge = new Charge($charge, $matches[2], trim($matches[4]), trim($dispositionDate), trim($matches[3]));
				$this->addCharge($charge);
			}
			
			// match a charge for MDJ
			else if ($this->getIsMDJ() && preg_match(self::$mdjChargesSearch, $line, $matches))
			{
				$charge = trim($matches[4]);

				// we need to check to see if the next line has overflow from the charge.
				// this happens on long charges, like possession of controlled substance
				$i = $line_num+1;
				
				if (preg_match(self::$chargesSearchOverflow, $arrestRecordFile[$i], $chargeMatch))
				{
					$charge .= " " . trim($chargeMatch[1]);
					$i++;
				}

				
				// add the charge to the charge array
				if (isset($this->dispositionDate))
					$dispositionDate = $this->getDispositionDate();
				else
					$dispositionDate = NULL;
				$charge = new Charge($charge, trim($matches[6]), trim($matches[1]), trim($dispositionDate), trim($matches[3]));
				$this->addCharge($charge);
			}			
			
			else if (preg_match(self::$bailSearch, $line, $matches))
			{
				$this->addBailCharged(doubleval(str_replace(",","",$matches[1])));  
				$this->addBailPaid(doubleval(str_replace(",","",$matches[2])));  // the amount paid
				$this->addBailAdjusted(doubleval(str_replace(",","",$matches[3])));
				$this->addBailTotal(doubleval(str_replace(",","",$matches[5])));  // tot final amount, after all adjustments
			}

			else if (preg_match(self::$costsSearch, $line, $matches))
			{
				$this->setCostsCharged(doubleval(str_replace(",","",$matches[1])));  
				$this->setCostsPaid(doubleval(str_replace(",","",$matches[2])));  // the amount paid
				$this->setCostsAdjusted(doubleval(str_replace(",","",$matches[3])));
				$this->setCostsTotal(doubleval(str_replace(",","",$matches[5])));  // tot final amount, after all adjustments
			}
		}
	}
		
	// Compares two arrests to see if they are part of the same case.  Two arrests are part of the 
	// same case if the DC or OTNs match; first check DC, then check OTN.
	// There are some cases where the OTNs match, but not the DC.  This can happen when:
	// someone is arrest and charged with multiple sets of crimes; all of these cases go to CP court
	// but they aren't consolidated.  B/c the arrests happened at the same time, OTN will
	// be the same on all cases, but the DC numbers will only match from the MC to the CP that 
	// follows
	// Don't match true if we match ourself
	public function compare($that)
	{
		// return false if we match ourself
		if ($this->getFirstDocketNumber() == $that->getFirstDocketNumber())
			return FALSE;
		else if ($this->getDC() != self::$unknownInfo && $this->getDC() == $that->getDC())
			return TRUE;
		else if ($this->getDC() == self::$unknownInfo && ($this->getOTN() != self::$unknownInfo && $this->getOTN() == $that->getOTN()))
		  	return TRUE;
		else
			return FALSE;
	}

	// combines the $this and $that. We assume for the purposes of this function that
	// $this and $that are the same docket number as that was previously checked
	// @param $that is an Arrest, but obtained from the Summary docket sheet, so it doesn't
	// have charge information with, just judge, arrest date, etc...
/*
	public function combineWithSummary($that)
	{
		if ($that->getJudge() != "")
			$this->setJudge($that->getJudge());
		if (!isset($this->arrestDate) || $this->getArrestDate() == self::$unknownInfo)
			$this->setArrestDate($that->getArrestDate());
		if (!isset($this->dispositionDate) || $this->getDispositionDate() == self::$unknownInfo)
			$this->setDispositionDate($that->getDispositionDate());
	}
*/
	//gets the first docket number on the array
	// Compares $this arrest to $that arrest and determines if they are actually part of the same
	// case.  Two arrests are part of the same case if they have the same OTN or DC number.
	// If the two arrests are part of the same case, combines them by taking all of the information
	// from one case and adding it to the other case (unless that information is already there.
	// It is important to note that you can only combine a CP case with an MC case.  You cannot
	// two MC cases together without a CP.
	// @param $that = Arrest to combine with $this
/*
	public function combine($that)
	{
		
		// if $this isn't a CP case, then don't combine.  If $that is a CP case, don't combine.
		if (!$this->isCP() || $that->isCP())
		{
			return FALSE;
		}
		
		// return false if we don't find something with the same DC or OTN number
		if (!$this->compare($that))
			return FALSE;
		
		// if $that (the MC case) is an expungement itself, then we don't want to combine.
		// If the MC case was an expungement, then no charges will move up from the MC case
		// to the associated CP case.  This happens in the following situation: 
		// Person is arrested and charged with three different sets of crimes that show up on
		// 3 different MC cases.  One of the MC cases is completely resolved at the prelim hearing
		// and charges are dismissed.  The other two MC cases have "held for court" charges
		// which are brought up to a CP case.  THe CP case OTN will match all three MC cases, but 
		// will only have charges from the two MC cases that were "held for court"
		if ($that->isArrestExpungement())
			return FALSE;
		
		// combine docket numbers
		$this->setDocketNumber(array_merge($this->getDocketNumber(),$that->getDocketNumber()));
		
		// combine charges.  Only include $that charges that are not "held for court"
		// The reason for this is that held for court charges will already appear on the CP,
		// they will just appear with a disposition.  We don't want to include held for court
		// charges and then assume that this isn't an expungement in our later logic.
		// This is a possible future thing to change.  Perhaps held for court should be put on
		// And something should be "expungeable" regardless of whether "held for court"
		// charges are on there.
		$thatChargesNoHeldForCourt = array();
		foreach ($that->charges as $charge)
		{
			$thatDisp = $charge->getDisposition();

			// note strange use of strpos.  strpos returns the location of the first occurrence of the string
			// or boolean false.  you have to check with === FALSE b/c the first occurence of the strong could
			// be position 0 or 1, which would otherwise evaluate to true and false!
			if (strpos($thatDisp, "Held for Court")===FALSE && strpos($thatDisp, "Waived for Court")===FALSE)
				$thatChargesNoHeldForCourt[] = $charge;
		}
		
		// if $thatChargesNoHeldForCourt[] has less elements than $that->charges, we know that
		// some charges were disposed of at the lower court level.  In that case, we need to
		// add the lower court judges in as well on the expungement sheet.
		// @todo add judges here
		$this->setCharges(array_merge($this->getCharges(),$thatChargesNoHeldForCourt));
		
		// combine bail amounts.  This isn't used for the petitions, but it is helpful for later
		// when we print out the overview of bail.  
		// Generally speaking, an individual could have a bail assessment on an MC case, even if
		// all charged went to CP court (this would happen if they failed to appear for a hearing
		// and then later appeared, were sent to CP court, and were tried there.
		// generally speaking, there are not fines on an MC case that is ultimately combined with
		// a CP case.
		$this->setBailChargedTotal($this->getBailChargedTotal()+$that->getBailChargedTotal());
		$this->setBailTotalTotal($this->getBailTotalTotal()+$that->getBailTotalTotal());
		$this->setBailAdjustedTotal($this->getBailAdjustedTotal()+$that->getBailAdjustedTotal());
		$this->setBailPaidTotal($this->getBailPaidTotal()+$that->getBailPaidTotal());

		// set MDJ as "2" if that is an an mdj.  "2" means that this is a case descending from MDJ
		// also set the mdj number
		if ($that->getIsMDJ())
		{
			$this->setIsMDJ(2);
			$this->setMDJDistrictNumber($that->getMDJDistrictNumber());
		}
		return TRUE;
	}
*/

/*
	// @return a comma separated list of all of the dispositions that are on the "charges" array
	// @param if redactableOnly is true (default) returns only redactable offenses
	public function getDispList($redactableOnly=TRUE)
	{
		$disposition = "";
		foreach ($this->getCharges() as $charge)
		{
			// if we are only looking for redactable charges, skip this charge if it isn't redactable
			if ($redactableOnly && !$charge->isRedactable())
				continue;
			if ((stripos($disposition,$charge->getDisposition())===FALSE))
			{
				if ($disposition != "")
					$disposition .= ", ";
				$disposition .= $charge->getDisposition();
			}
		}
		return $disposition;
	}
*/	

/*
	// @param redactableOnly - boolean defaults to false; if set to true, only returns redactable charges
	// @return a string holding a comma separated list of charges that are in the charges array; 
	// @return if "redactableOnly" is TRUE, returns only those charges that are expungeable	
	public function getChargeList($redactableOnly=FALSE)
	{
		$chargeList = "";
		foreach ($this->getCharges() as $charge)
		{
			// if we are trying to only get the list of "Expungeable" offenses, then 
			// continue to the next charge if this charge is not Expungeable
			if ($redactableOnly && !$charge->isRedactable())
				continue;
			if ($chargeList != "")
				$chargeList .= ", ";
			$chargeList .= ucwords(strtolower($charge->getChargeName()));
		}
		return $chargeList;
	}
*/
	
	
	// returns the age based off of the DOB read from the arrest record
	public function getAge()
	{
		$birth = new DateTime($this->getDOB());
		$today = new DateTime();
		return dateDifference($today, $birth);
	}

	// @return the disposition date of the first charge on the charges array
	// @return if no disposition date exists on the first chage, then sets the dipsositionDate to the migrated disposition date
	public function getDispositionDate()
	{
		if (!isset($this->dispositionDate))
		{
			if (count($this->charges))
			{
				$firstCharge = $this->getCharges();
				$this->setDispositionDate($firstCharge[0]->getDispDate());
			}
			else
				$this->setDispositionDate(self::$unknownInfo);
		}	
		
		return $this->dispositionDate;
	}
	
	// @function getBestDispotiionDate returns a dispotition date if available.  Otherwise returns
	// the arrest date.
	// @return a date
	public function getBestDispositionDate()
	{
		if ($this->getDispositionDate() != self::$unknownInfo)
			return $this->getDispositionDate();
		else
			return $this->getArrestDate();
	}

	// returns true if this is a criminal offense.  this is true if we see CP|MC-##-CR|SU, 
	// not SA or MD
	public function isArrestCriminal()
	{
		if (isset($this->isCriminal))
			return  $this->getIsCriminal();
		else
		{
			$criminalMatch = "/CR|SU|MJ/";
			if (preg_match($criminalMatch, $this->getFirstDocketNumber()))
			{
					$this->setIsCriminal(TRUE);
					return TRUE;
			}
			$this->setIsExpungement(FALSE);
			return FALSE;
		}
	}
/*
	// returns true if this arrest includes ARD offenses.
	public function isArrestARDExpungement()
	{
		if (isset($this->isARDExpungement))
			return  $this->getIsARDExpungement();
		else
		{
			foreach ($this->getCharges() as $num=>$charge)
			{
				if($charge->isARD())
				{
					$this->setIsARDExpungement(TRUE);
					return TRUE;
				}
			}
			$this->setIsARDExpungement(FALSE);
			return FALSE;
		}
	}
*/

/*
	// @function isArrestOver70Expungement() - returns true if the petition is > 70yo and they have been arrest
	// free for at least the last 10 years.
	// @param arrests - an array of all of the other arrests that we are comparing this to to see if they are 
	// 10 years arrest free
	//@ return TRUE if the conditions above are me; FALSE if not
	public function isArrestOver70Expungement($arrests, $person)
	{
		// if already set, then just return the member variable
		if (isset($this->isArrestOver70Expungement))
			return $this->isArrestOver70Expungement;
			
		// return false right away if the petition is younger than 70
		if ($person->getAge() < 70)
		{
			$this->setIsArrestOver70Expungement(FALSE);
			return FALSE;
		} 	

		// also return false right away if there aren't any charges to actually look at
		if (count($this->getCharges())==0)
		{
			$this->setIsArrestOver70Expungement(FALSE);
			return FALSE;
		} 	
		
		// do an over 70 exp if at least one is not redactible; if this is a regular exp, just do a regular exp
		// NOTE: THis may be a problem for HELD FOR COURT charges; keep this in mind
		if ($this->isArrestExpungement())
		{
			$this->setIsArrestOver70Expungement(FALSE);
			return FALSE;
		}
		
		// at this point we know two things: we are over 70 and we need to get non-redactable charges off of 
		// the record
		// Loop through all of the arrests passed in to get the disposition dates or the 
		// arrest dates if the disposition dates don't exist.  
		// return false if any of them are within 10 years of today

		$dispDates = array();
		$dispDates[] = new DateTime($this->getBestDispositionDate());
		foreach ($arrests as $arrest)
		{
			$dispDates[] = new DateTime($arrest->getBestDispositionDate());
		}

		// look at each dispDate in the array and make sure it was more than 10 years ago
		$today = new DateTime();
		foreach ($dispDates as $dispDate)
		{
			if (abs(dateDifference($dispDate, $today)) < 10)
			{
				$this->setIsArrestOver70Expungement(FALSE);
				return FALSE;
			}
		}
		
		// if we got here, it means there are no five year periods of freedom
		$this->setIsArrestOver70Expungement(TRUE);
		return TRUE;
			
	}
*/

/*
	// @function isArrestSummaryExpungement - returns true if this is an expungeable summary 
	// arrest.  
	// This is true in a slightly more complicated sitaution than the others.  To be a 
	// summary expungement a few things have to be true:
	// 1) This has to be a summary offense, characterized by "SU" in the docket number.
	// 2) The person must have been found guilty or plead guilty to the charges (if they were
	// not guilty or dismissed, then there is nothing to worry about - normal expungmenet.
	// 3) The person must have five years arrest free AFTER the arrest.  This doesn't have to be 
	// the five years immediately following the arrest nor does it have to be the most recent five
	// years.  It just has to be five years arrest free at some point post arrest.  
	// @note - a problem that might come up is if someone has a summary and then is confined in jail
	// for a long period of time (say 10 years).  This will apear eligible for a summary exp, but
	// is not.
	// @param arrests - an array of all of the other arrests that we are comparing this too to see
	// if they are 5 years arrest free
	// @return TRUE if the conditions above are met; FALSE if not.
	public function isArrestSummaryExpungement($arrests)
	{
		// if already set, then just return the member variable
		if (isset($this->isArrestSummaryExpungement))
			return $this->isArrestSummaryExpungement;
			
		// return false right away if this is not a summary arrest
		if (!$this->getIsSummaryArrest())
		{
			$this->setIsArrestSummaryExpungement(FALSE);
			return FALSE;
		} 	

		// also return false right away if there aren't any charges to actually look at
		if (count($this->getCharges())==0)
		{
			$this->setIsArrestSummaryExpungement(FALSE);
			return FALSE;
		} 	
		
		// loop through all of the charges; only do a summary exp if none are redactible
		// NOTE: THis may be a problem for HELD FOR COURT charges; keep this in mind
		// NOTE: Is it possible that someone has some not guilty and some guilty for summary charges?
		foreach ($this->getCharges() as $num=>$charge)
		{
			if($charge->isRedactable())
			{
				$this->setIsArrestSummaryExpungement(FALSE);
				return FALSE;
			}
		}
			
		// at this point we know two things: summary arrest and the charges are all guilties.
		// now we need to check to see if they are arrest free for five years.	
		// Loop through all of the arrests passed in to get the disposition dates or the 
		// arrest dates if the disposition dates don't exist.  
		// Drop dates that are before this date.
		// Make a sorted array of all of the dates and find the longest gap.
		$thisDispDate = new DateTime($this->getBestDispositionDate());
		$dispDates = array();

		$dispDates[] = $thisDispDate;
		$dispDates[] = new DateTime(); // add today onto the array as well
		foreach ($arrests as $arrest)
		{
			$thatDispDate = new DateTime($arrest->getBestDispositionDate());
			// if the disposition date of that arrest was before this arrest, ignore it
			if ($thatDispDate < $thisDispDate)
				continue;
			else
				$dispDates[] = $thatDispDate;
		}
		// sort array
		asort($dispDates);

		// sort through the first n-1 members of the dateArray and compare them to the next
		// item in the array to see if there is more than 5 years between them
		for ($i=0; $i<(sizeof($dispDates)-1); $i++)
		{
			if (abs(dateDifference($dispDates[$i+1], $dispDates[$i])) >= 5)
			{
				$this->setIsArrestSummaryExpungement(TRUE);
				return TRUE;
			}
		}
		
		// if we got here, it means there are no five year periods of freedom
		$this->setIsArrestSummaryExpungement(FALSE);
		return FALSE;
			
	}
*/
/*
	// returns true if this is an expungeable arrest.  this is true if no charges are guilty
	// or guilty plea or held for court.
	public function isArrestExpungement()
	{
		if (isset($this->isExpungement))
			return  $this->getIsExpungement();

		else
		{
			foreach ($this->getCharges() as $num=>$charge)
			{
				// the quirky case where on a CP, the held for court charges are listed from the MC
				// case.
				if ($this->isCP() && $charge->getDisposition() == "Held for Court")
					continue;
				if(!$charge->isRedactable())
				{
					$this->setIsExpungement(FALSE);
					return FALSE;
				}
			}
			
			// deal with the quirky case where there are no charges on the array.  This happens
			// rarely where there is a docket sheet that lists charges, but doesn't list
			// dispositions at all.
			if (count($this->getCharges()) == 0)
			{
					$this->setIsExpungement(FALSE);
					return FALSE;
			}
			
			$this->setIsExpungement(TRUE);
			return TRUE;
		}
	}
*/

	// returns true if the first docket number starts with "CP"
	public function isCP()
	{
		if (isset($this->isCP))
			return $this->getIsCP();
		else
		{
			$match = "/^CP/";
			if (preg_match($match, $this->getFirstDocketNumber()))
			{
				$this->setIsCP(TRUE);
				return TRUE;
			}
			
			$this->setIsCP(FALSE);
			return FALSE;
		}
	}

/*	
	// returns true if this is a redactable offense.  this is true if there are charges that are NOT
	// guilty or guilty plea or held for court.  returns true for expungements as well.
	public function isArrestRedaction()
	{
		if (isset($this->isRedaction))
			return  $this->getIsRedaction();

		else
		{
			foreach ($this->getCharges() as $charge)
			{
				// if we don't match Guilty|Guilty Plea|Held for court, this is redactable
				if ($charge->isRedactable())
				{
					$this->setIsRedaction(TRUE);
					return TRUE;
				}
			}
			// if we ever get here, we have no redactable offenses, so return false
			$this->setIsRedaction(FALSE);
			return FALSE;
		}
	}
*/

/*
	// return true if any of the charges are held for court.  this means we are ripe for 
	// consolodating with another arrest
	public function isArrestHeldForCourt()
	{
		if (isset($this->isHeldForCourt))
				return  $this->getIsHeldForCourt();
		else
		{
			$heldForCourtMatch = "/[Held for Court|Waived for Court]/";
			foreach ($this->getCharges() as $num=>$charge)
			{
				// if we match Held for court, setheldforcourt = true
				if (preg_match($heldForCourtMatch,$charge->getDisposition()))
				{
					$this->setIsHeldForCourt(TRUE);
					return TRUE;
				}
			}
			// if we ever get here, we have no heldforcourt offenses, so return false
			$this->setIsHeldForCourt(FALSE);
			return FALSE;
	
		}
	}
*/

/*	
	// @returns an associative array with court information based on the county name
	public function getCourtInformation($db)
	{
		// $sql is going to be different based on whether this is an mdj case or a regular case
		$table = "court";
		$column = "county";
		$value = $this->getCounty();
		
		if ($this->getIsMDJ() == 1)
		{
			$table = "mdjcourt";
			$column = "district";
			$value = $this->getMDJDistrictNumber();
		}

		// sql statements are case insensitive by default		
		$query = "SELECT * FROM $table WHERE $table.$column='$value'";
		$result = mysql_query($query, $db);

		if (!$result) 
		{
			if ($GLOBALS['debug'])
				die('Could not get the court information from the DB:' . mysql_error());
			else
				die('Could not get the court Information from the DB');
		}
		$row = mysql_fetch_assoc($result);
		return $row;
	}
*/	
		
	public function simplePrint()
	{
		echo "\nDocket #:";
		foreach ($this->getDocketNumber() as $value)
			print "$value | ";
		echo "\nName: " . $this->getFirstName() . ". " . $this->getLastName();
		echo "\nDOB: " . $this->getDOB();
		echo "\nage: " . $this->getAge();
		echo "\nOTN: " . $this->getOTN();
		echo "\nDC: " .$this->getDC();
		echo "\narrestingOfficer: " .$this->getArrestingOfficer();
		echo "\narrestDate: " . $this->getArrestDate();
		echo "\njudge: " .$this->getJudge();
		foreach ($this->getCharges() as $num=>$charge)
		{
			echo "\ncharge $num: " . $charge->getChargeName() . "|".$charge->getDisposition()."|".$charge->getCodeSection()."|".$charge->getDispDate();
		}
		echo "\nTotal Costs: " .$this->getCostsTotal();
		echo "\nCosts Paid: " . $this->getCostsPaid();
		echo "\n";
	}
	
/*					
	public function writeExpungementToDatabase($person, $attorney, $db)
	{
		// the defendant has already been inserted
		// next insert the arrest, which includes the defendant ID
		// next insert each charge, which includes the arrest id and the defendant ID
		// finally insert the expungement, which includes the arrest id, the defendant id, the chargeid, the userid, and a timestamp
		$defendantID = $person->getPersonID();
		$attorneyID = $attorney->getUserID();
		$arrestID = $this->writeArrestToDatabase($defendantID, $db);

		// we only want to write an expungement to the database if this is a redactable arrest
		if ($this->isArrestExpungement() || $this->isArrestRedaction() || $this->isArrestSummaryExpungement)
			$expungementID = $this->writeExpungementDataToDatabase($arrestID, $defendantID, $attorneyID, $db);
		else
			$expungementID = "NULL";
			
		$numRedactableCharges = 0;
		foreach ($this->getCharges() as $charge)
		{
			// if the charge isn't redactable, we don't want to include an expungement ID
			// The expungementID may be placed on other charges from the same arrest.
			// We use a tempID so that we don't change the value of the main variable each time 
			// through the loop.
			// If we are a redactable charge, increment the counter.
			$tempExpungementID = $expungementID;
			if (!$charge->isRedactable() && (!$this->isArrestSummaryExpungement && !$this->isArrestOver70Expungement))
				$tempExpungementID = "NULL";
			else
				$numRedactableCharges++;
				
			$chargeID = $this->writeChargeToDatabase($charge, $arrestID, $defendantID, $tempExpungementID, $db);
		}
		
		$this->updateExpungementWithNumCharges($expungementID, $numRedactableCharges, $db);
		
		// finally, save the PDF to the database, if there was a pdf file to save
		$this->writePDFToDatabase($expungementID, $db);
		
		
	}
	
	// @return the id of the arrest just inserted into the database
	// @param $defendantID - the id of the defendant that this arrest concerns
	// @param $db - the database handle
	public function writeArrestToDatabase($defendantID, $db)
	{
		$sql = "INSERT INTO arrest (`defendantID`, `OTN` ,`DC` ,`docketNumPrimary` ,`docketNumRelated` ,`arrestingOfficer` ,`arrestDate` ,`dispositionDate` ,`judge` ,`costsTotal` ,`costsPaid` ,`costsCharged` ,`costsAdjusted` ,`bailTotal` ,`bailCharged` ,`bailPaid` ,`bailAdjusted` ,`bailTotalToal` ,`bailChargedTotal` ,`bailPaidTotal` ,`bailAdjustedTotal` ,`isARD` ,`isSummary` ,`county` ,`policeLocality`) VALUES ('$defendantID', '" . $this->getOTN() . "', '" . $this->getDC() . "', '" . $this->getFirstDocketNumber() . "', '" . implode("|", $this->getDocketNumber()) . "', '" . mysql_real_escape_string($this->getArrestingOfficer()) . "', '" . dateConvert($this->getArrestDate()) . "', '" . dateConvert($this->getDispositionDate()) . "', '" . mysql_real_escape_string($this->getJudge()) . "', '" . $this->getCostsTotal() . "', '" . $this->getCostsPaid() . "', '" . $this->getCostsCharged() . "', '" . $this->getCostsAdjusted() . "', '" . $this->getBailTotal() . "', '" . $this->getBailCharged() . "', '" . $this->getBailPaid() . "', '" . $this->getBailAdjusted() . "', '" . $this->getBailTotalTotal() . "', '" . $this->getBailChargedTotal() . "', '" . $this->getBailPaidTotal() . "', '" . $this->getBailAdjustedTotal() . "', '" . $this->getIsARDExpungement() . "', '" . $this->getIsSummaryArrest() . "', '" . $this->getCounty() . "', '" . mysql_real_escape_string($this->getArrestingAgency()) . "')";

		if ($GLOBALS['debug'])
			print $sql;
		$result = mysql_query($sql, $db);
		if (!$result) 
		{
			if ($GLOBALS['debug'])
				die('Could not add the arrest to the DB:' . mysql_error());
			else
				die('Could not add the arrest to the DB');
		}
		return mysql_insert_id();
	}
	
	// @return the id of the charge just inserted into the database
	// @param $defendantID - the id of the defendant that this arrest concerns
	// @param $db - the database handle
	// @param $charge - the charge that we are inserting
	// @param $arrestID - the id of the arrest that we are innserting
	public function writeChargeToDatabase($charge, $arrestID, $defendantID, $expungementID, $db)
	{
		$sql = "INSERT INTO charge (`arrestID`, `defendantID`, `expungementID`, `chargeName`, `disposition`, `codeSection`, `dispDate`, `isARD`, `isExpungeableNow`, `grade`, `arrestDate`) VALUES ('$arrestID', '$defendantID', $expungementID, '" . mysql_real_escape_string($charge->getChargeName()) . "', '" . mysql_real_escape_string($charge->getDisposition()) . "', '" . $charge->getCodeSection() . "', '" . dateConvert($charge->getDispDate()) . "', '" . $charge->getIsARD() . "', '" . $charge->getIsRedactable() . "', '" . $charge->getGrade() . "', '" . dateConvert($this->getArrestDate()) . "')";
		
		$result = mysql_query($sql, $db);
		if (!$result) 
		{
			if ($GLOBALS['debug'])
				die('Could not add the arrest to the DB:' . mysql_error());
			else
				die('Could not add the arrest to the DB');
		}
		return mysql_insert_id();
	}
	
	// @return the expungementID
	// @param $defendantID - the id of the defendant that this arrest concerns
	// @param $db - the database handle
	// @param $arrestID - the id of the arrest that we are innserting
	// @param $chargeID - the id of the charge that we are innserting
	public function writeExpungementDataToDatabase($arrestID, $defendantID, $attorneyID, $db)
	{
		$sql  = "INSERT INTO  expungement (`arrestID`, `defendantID`, `userid`, `isExpungement`, `isRedaction`, `isSummaryExpungement`, `timestamp`) VALUES ('$arrestID',  '$defendantID', '$attorneyID', '" . $this->isArrestExpungement() . "', '" . $this->isArrestRedaction() . "', '" . $this->isArrestSummaryExpungement ."', CURRENT_TIMESTAMP)";

		$result = mysql_query($sql, $db);
		if (!$result) 
		{
			if ($GLOBALS['debug'])
				die('Could not add the expungement to the DB:' . mysql_error());
			else
				die('Could not add the expungement to the DB');
		}
		return mysql_insert_id();
	
	}
*/


	
}  // end class Docket

?>