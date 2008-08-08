<?php
/*
 * Centreon is developped with GPL Licence 2.0 :
 * http://www.gnu.org/licenses/old-licenses/gpl-2.0.txt
 * Developped by : Julien Mathis - Romain Le Merlus 
 * 
 * The Software is provided to you AS IS and WITH ALL FAULTS.
 * Centreon makes no representation and gives no warranty whatsoever,
 * whether express or implied, and without limitation, with regard to the quality,
 * any particular or intended purpose of the Software found on the Centreon web site.
 * In no event will Centreon be liable for any direct, indirect, punitive, special,
 * incidental or consequential damages however they may arise and even if Centreon has
 * been previously advised of the possibility of such damages.
 * 
 * For information : contact@centreon.com
 */
	
	function escape_command($command) {
		return ereg_replace("(\\\$|`)", "", $command);
	}
	require_once("@CENTREON_ETC@/centreon.conf.php");
	require_once("$centreon_path/www/DBOdsConnect.php");

	$title	 = array(	"active_host_check" => _("Host checks"), 
						"active_host_last" => _("Active hosts"),
						"host_latency" => _("Host check latency"),
						"active_service_check" => _("Service checks"), 
						"active_service_last" => _("Active services"), 
						"service_latency" => _("Service check latency"), 
						"cmd_buffer" => _("Commands in buffer"), 
						"host_states" => _("Host status"), 
						"service_states" => _("Services status"));


	$options = array(	"active_host_check" => "nagios_active_host_execution.rrd", 
						"active_host_last" => "nagios_active_host_last.rrd",
						"host_latency" => "nagios_active_host_latency.rrd",
						"active_service_check" => "nagios_active_service_execution.rrd", 
						"active_service_last" => "nagios_active_service_last.rrd", 
						"service_latency" => "nagios_active_service_latency.rrd", 
						"cmd_buffer" => "nagios_cmd_buffer.rrd", 
						"host_states" => "nagios_hosts_states.rrd", 
						"service_states" => "nagios_services_states.rrd");
	
	$differentStats = array(	"nagios_active_host_execution.rrd" => array("Used", "High", "Total"), 
								"nagios_active_host_last.rrd" => array("T1", "T5", "T15", "T60"), 
								"nagios_active_host_latency.rrd" => array("Used", "High", "Total"), 
								"nagios_active_service_execution.rrd" => array("Used", "High", "Total"), 
								"nagios_active_service_last.rrd" => array("T1", "T5", "T15", "T60"), 
								"nagios_active_service_latency.rrd" => array("Used", "High", "Total"), 
								"nagios_cmd_buffer.rrd" => array("Used", "High", "Total"), 
								"nagios_hosts_states.rrd" => array("Up", "Down", "Unreach"), 
								"nagios_services_states.rrd" => array("Ok", "Warn", "Crit", "Unk"));


	/*
	 * Verify if start and end date
	 */	

	if (!isset($_GET["start"])) {		
		$start = time() - (60*60*96);		
	}
	else {				
		switch ($_GET["start"]) {
			case "today" : $start = time() - (60*60*24); break;
			case "yesterday" : $start = time() - (60*60*48); break;
			case "last4days" : $start = time() - (60*60*96); break;
			case "lastweek" : $start = time() - (60*60*168); break;
		}
	}
	
	if (!isset($_GET["end"]))
		$end = time();
	else
		$end = $_GET["end"];

		 
	$command_line = " graph - --start=".$start." --end=".$end;

	/*
	 * get all template infos
	 */
	 
	$command_line .= " --interlaced --imgformat PNG --width=400 --height=100 --title='".$title[$_GET["key"]]."' --vertical-label='".$_GET["key"]."' --slope-mode  ";
	$command_line .= "--rigid --alt-autoscale-max ";
			
	/*
	 * Init DS template For each curv
	 */
	
	$colors = array("1"=>"#19EE11", "2"=>"#82CFD8", "3"=>"#F8C706", "4"=>"#F8C706");
	//$metrics = array("Used" => 1, "High" => 2, "Total" => 3);
	
	$metrics = $differentStats[$options[$_GET["key"]]];
	$cpt = 1;
	$DBRESULT =& $pearDBO->query("SELECT RRDdatabase_nagios_stats_path FROM config");
	if (PEAR::isError($DBRESULT))
		print "DB Error : ".$DBRESULT->getDebugInfo()."<br />";
	while ($nagios_stats =& $DBRESULT->fetchRow())
		$nagios_stats_path = $nagios_stats['RRDdatabase_nagios_stats_path'];
	foreach ($metrics as $key => $value){
		$command_line .= " DEF:v".$cpt."=".$nagios_stats_path."perfmon-".$_GET["ns_id"]."/".$options[$_GET["key"]].":".$value.":AVERAGE ";
		$cpt++;
	}
	$command_line .= " COMMENT:\" \\l\" ";
	
	
	# Create Legende
	$cpt = 1;

	foreach ($metrics as $key => $tm){
		//$command_line .= " AREA:v".($cpt).$colors[$cpt]."3c ";
		$command_line .= " LINE1:v".($cpt);
		$command_line .= $colors[$cpt].":\"";
		$command_line .= $tm."\"";
		$cpt++;
	}

	$command_line = "/usr/bin/rrdtool ".$command_line." 2>&1";
	$command_line = escape_command("$command_line");

	//print $command_line;
	$fp = popen($command_line  , 'r');
	if (isset($fp) && $fp ) {
		$str ='';
		while (!feof ($fp)) {
	  		$buffer = fgets($fp, 4096);
	 		$str = $str . $buffer ;
		}
		print $str;
	}
	
?>