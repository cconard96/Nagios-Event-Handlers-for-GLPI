<?php
error_reporting(E_ALL);
require_once("glpi_api.php");

const STATUS_OPEN = 1;

$config = require('config.php');

function logging($msg){
	if ($config['log']) {
		syslog(LOG_INFO, $msg);
	}
}

logging("Manage Service Tickets: Called Script");

if (!extension_loaded("curl")) {
	logging("Manage Service Tickets: Extension curl not loaded");
	die("Extension curl not loaded");
}

$glpi = new GLPI_API([
	'username' => $config['glpi_user'],
	'password' => $config['glpi_password'],
	'apikey' => $config['glpi_apikey'],
	'host' => $config['glpi_host'],
	'verifypeer' => $config['verifypeer']
]);

$eventval=array();
	if ($argv>1) {
	   for ($i=1 ; $i<count($argv) ; $i++) {
		  $it = explode("=",$argv[$i],2);
		  $it[0] = preg_replace('/^--/','',$it[0]);
		  $eventval[$it[0]] = (isset($it[1]) ? $it[1] : true);
	   }
	}

$eventhost=$eventval['eventhost'];
$servicestate=$eventval['servicestate'];
$servicestatetype=$eventval['servicestatetype'];
$hoststate=$eventval['hoststate'];
$service=$eventval['service'];
$serviceattempts=$eventval['serviceattempts'];
$maxserviceattempts=$eventval['maxserviceattempts'];
$lastservicestate=$eventval['lastservicestate'];
$servicecheckcommand=$eventval['servicecheckcommand'];
$serviceoutput=$eventval['serviceoutput'];
$longserviceoutput=$eventval['longserviceoutput'];
unset($eventval);

logging("Manage Service Tickets: EventHost = ".$eventhost);
logging("Manage Service Tickets: ServiceState = ".$servicestate);
logging("Manage Service Tickets: ServiceStateType = ".$servicestatetype);
logging("Manage Service Tickets: HostState = ".$hoststate);
logging("Manage Service Tickets: Service = ".$service);
logging("Manage Service Tickets: ServiceAttempts = ".$serviceattempts);
logging("Manage Service Tickets: MaxServiceAttempts = ".$maxserviceattempts);
logging("Manage Service Tickets: LastServiceState = ".$lastservicestate);
logging("Manage Service Tickets: ServiceCheckCommand = ".$servicecheckcommand);
logging("Manage Service Tickets: ServiceOutput = ".$serviceoutput);
logging("Manage Service Tickets: LongServiceOutput = ".$longserviceoutput);

function getSearchCriteria($status, $last_state) {
	$last_state = ucfirst(strtolower($last_state));
	return [
		'criteria' => [
			[
				'field'	=> '12',
				'searchtype' => 'equals',
				'value' => 1
			],
			[
				'link'	=> 'AND',
				'field'	=> '1',
				'searchtype'	=> 'contains',
				'value'			=> "$service on $eventhost is in a $last_state State!"
			]
		]
	];
}

// What state is the HOST in?
if (($hoststate == "UP")) {  // Only open tickets for services on hosts that are UP
	logging("Manage Service Tickets: Host is up, checking service state");
	switch ($servicestate) {
		case "OK":
			logging("Manage Service Tickets: Service State is OK, checking Last Service State");
			# The service just came back up - perhaps we should close the ticket...
			switch($lastservicestate){
				case "CRITICAL":
					logging("Manage Service Tickets: Last Service State Critical, checking for open Critical Tickets");
					$search = getSearchCriteria(STATUS_OPEN, $lastservicestate);

					$tickets = $glpi->search('Ticket', $search);
					if (!empty($tickets['data']->data)){
						logging("Manage Service Tickets: Found open Critical Tickets, updating tickets");
						$post = array('input' => array());
						foreach ($tickets['data']->data as $ticket) {
							$post['input'][] = array('id' => $ticket->{2} , 'status' => 6);
							$glpi->updateItem('Ticket', $post);
						}
					} else {
						logging("Manage Service Tickets: No Critical Tickets found, exiting gracefully");
					}
					break;
				case "WARNING":
					logging("Manage Service Tickets: Last Service State Warning, checking for open Warning Tickets");
					$search = getSearchCriteria(STATUS_OPEN, $lastservicestate);

					$tickets = $glpi->search('Ticket', $search);

					if (!empty($tickets['data']->data)){
						logging("Manage Service Tickets: Found open Warning Tickets, updating tickets");
						$post = array('input' => array());
						foreach ($tickets['data']->data as $ticket) {
							$post['input'][] = array('id' => $ticket->{2} , 'status' => 6);
							$glpi->updateItem('Ticket', $post);
						}
					} else {
						logging("Manage Service Tickets: No Warning Tickets found, exiting gracefully");
					}
					break;
				case "OK":
					logging("Manage Service Tickets: Last Service State OK, exiting gracefully");
					break;
				case "UNKNOWN":
					logging("Manage Service Tickets: Last Service State UNKNOWN, exiting gracefully");
					break;
			} //Last Service State
			break;
		case "CRITICAL":
			logging("Manage Service Tickets: Service State is CRITICAL, checking Service State Type");
			# Aha!  The service appears to have a problem - perhaps we should open a ticket...
			# Is this a "soft" or a "hard" state?
			switch ($servicestatetype) {
				case "HARD":
					logging("Manage Service Tickets: Service State Type is HARD, checking service attempts");
					if ($serviceattempts == $maxserviceattempts){
						logging("Manage Service Tickets: Service Attempts = 3, checking Last Service State");
						switch($lastservicestate){
							case "WARNING":
								logging("Manage Service Tickets: Last Service State is WARNING, Checking for open warning tickets");
								//Update previous warning ticket(s)
								$search = getSearchCriteria(STATUS_OPEN, $lastservicestate);

								$tickets = $glpi->search('Ticket', $search);
								
								if (!empty($tickets['data']->data)){
									logging("Manage Service Tickets: Found open Warning Tickets, updating tickets");
									$post = array('input' => array());
									foreach ($tickets['data']->data as $ticket) {
										$update_post['input'][] = [
											'id' => $ticket->{2} , 'name' => "$service on $eventhost is in a Critical State!" , 'priority' => $config['critical_priority']
										];
										$glpi->updateItem('Ticket', $update_post);
										$followup_post['input'][] = array('tickets_id' => $ticket->{2} , 'is_private' => "0" , 'content' => 'State changed to Critical, priority updated');
										$glpi->addItem('Ticket/' . $ticket->{2} .'/TicketFollowup', $followup_post);
									}
								}
								break;
							case "OK":
								logging("Manage Service Tickets: Last Service State is OK, Creating new Critical ticket");
								//Create a new ticket
								$ticket = array(
									'input' => array(
										'name' => "$service on $eventhost is in a Critical State!",
										'content' => "$service on $eventhost is in a Critical State.  Please check that the service or check is running and responding correctly \n
											Check service status at ${$config['nagios_host']} \n
											<b>Service Check Details</b> 
											Host \t\t\t = $eventhost 
											Service Check \t = $service 
											State \t\t\t = $servicestate 
											Check Attempts \t = $serviceattempts/$maxserviceattempts 
											Check Command \t = $servicecheckcommand 
											Check Output \t\t = $serviceoutput 
											$longserviceoutput",
										'priority' => $config['critical_priority'],
										'_users_id_requester' => $config['glpi_requester_user_id'],
										'_groups_id_requester' => $config['glpi_requester_group_id'],
										'_users_id_observer' => $config['glpi_watcher_user_id'],
										'_groups_id_observer' => $config['glpi_watcher_group_id'],
										'_users_id_assign' => $config['glpi_assign_user_id'],
										'_groups_id_assign' => $config['glpi_assign_user_id']
									)
								);
								$glpi->addItem('Ticket', $ticket);
								break;
							case "UNKNOWN":
								logging("Manage Service Tickets: Last Service State is UNKNOWN, Creating new Critical ticket");
								//Create a new ticket
								$ticket = array(
									'input' => array(
										'name' => "$service on $eventhost is in a Critical State!",
										'content' => "$service on $eventhost is in a Critical State.  Please check that the service or check is running and responding correctly \n
											Check service status at ${$config['nagios_host']} \n
											<b>Service Check Details</b> 
											Host \t\t\t = $eventhost 
											Service Check \t = $service 
											State \t\t\t = $servicestate 
											Check Attempts \t = $serviceattempts/$maxserviceattempts 
											Check Command \t = $servicecheckcommand 
											Check Output \t\t = $serviceoutput 
											$longserviceoutput",
										'priority' => $config['critical_priority'],
										'_users_id_requester' => $config['glpi_requester_user_id'],
										'_groups_id_requester' => $config['glpi_requester_group_id'],
										'_users_id_observer' => $config['glpi_watcher_user_id'],
										'_groups_id_observer' => $config['glpi_watcher_group_id'],
										'_users_id_assign' => $config['glpi_assign_user_id'],
										'_groups_id_assign' => $config['glpi_assign_user_id']
									)
								);
								$glpi->addItem('Ticket', $ticket);
								break;
						} //Last Service State
					} //Service Attempts
				break;
			} //Switch Service State Type	
			break;
		case "WARNING":
			logging("Manage Service Tickets: Service State is WARNING, checking Service State Type");
			# Aha!  The service appears to have a problem - perhaps we should open a ticket...
			# Is this a "soft" or a "hard" state?
			switch ($servicestatetype) {
				case "HARD":
					logging("Manage Service Tickets: Service State Type is HARD, checking service attempts");
					if ($serviceattempts == $maxserviceattempts){
						logging("Manage Service Tickets: Service Attempts = 3, checking Last Service State");
						switch($lastservicestate){
							case "CRITICAL":
								logging("Manage Service Tickets: Last Service State is CRITICAL, Checking for open critical tickets");
								$search = getSearchCriteria(STATUS_OPEN, $lastservicestate);

								$tickets = $glpi->search('Ticket', $search);
								
								if (!empty($tickets['data']->data)){
									$post = array('input' => array());
									foreach ($tickets['data']->data as $ticket) {
										$update_post['input'][] = [
											'id' => $ticket->{2} , 'name' => "$service on $eventhost is in a Warning State!" , 'priority' => $config['warning_priority']
										];
										$glpi->updateItem('Ticket', $update_post);
										$followup_post['input'][] = array('tickets_id' => $ticket->{2} , 'is_private' => "0" , 'content' => 'State changed to Warning, priority updated');
										$glpi->addItem('Ticket/' . $ticket->{2} .'/TicketFollowup', $followup_post);
									}
								}
								break;
							case "OK":
								logging("Manage Service Tickets: Last Service State is OK, Creating new Warning ticket");
								//Create a new ticket
								$ticket = array(
									'input' => array(
										'name' => "$service on $eventhost is in a Warning State!",
										'content' => "$service on $eventhost is in a Warning State.  Please check that the service or check is running and responding correctly \n
										Check service status at ${$config['nagios_host']} \n
										<b>Service Check Details</b> 
										Host \t\t\t = $eventhost 
										Service Check \t = $service 
										State \t\t\t = $servicestate 
										Check Attempts \t = $serviceattempts/$maxserviceattempts 
										Check Command \t = $servicecheckcommand 
										Check Output \t = $serviceoutput 
										$longserviceoutput",
										'priority' => $config['warning_priority'],
										'_users_id_requester' => $config['glpi_requester_user_id'],
										'_groups_id_requester' => $config['glpi_requester_group_id'],
										'_users_id_observer' => $config['glpi_watcher_user_id'],
										'_groups_id_observer' => $config['glpi_watcher_group_id'],
										'_users_id_assign' => $config['glpi_assign_user_id'],
										'_groups_id_assign' => $config['glpi_assign_user_id']
									)
								);
								$glpi->addItem('Ticket', $ticket);
								break;
							case "UNKNOWN":
								logging("Manage Service Tickets: Last Service State is UNKNOWN, Creating new Warning ticket");
								//Create a new ticket
								$ticket = array(
									'input' => array(
										'name' => "$service on $eventhost is in a Warning State!",
										'content' => "$service on $eventhost is in a Warning State.  Please check that the service or check is running and responding correctly \n
										Check service status at $nagios_host \n
										<b>Service Check Details</b> 
										Host \t\t\t = $eventhost 
										Service Check \t = $service 
										State \t\t\t = $servicestate 
										Check Attempts \t = $serviceattempts/$maxserviceattempts 
										Check Command \t = $servicecheckcommand 
										Check Output \t = $serviceoutput 
										$longserviceoutput",
										'priority' => $config['warning_priority'],
										'_users_id_requester' => $config['glpi_requester_user_id'],
										'_groups_id_requester' => $config['glpi_requester_group_id'],
										'_users_id_observer' => $config['glpi_watcher_user_id'],
										'_groups_id_observer' => $config['glpi_watcher_group_id'],
										'_users_id_assign' => $config['glpi_assign_user_id'],
										'_groups_id_assign' => $config['glpi_assign_user_id']
									)
								);
								$glpi->addItem('Ticket', $ticket);
								break;
						} //Last Service State
					}
					break;
			} //Switch Service State Type
			break;
		case "UNKNOWN":
			logging("Manage Service Tickets: Service State UNKNOWN, exiting gracefully");
			break;
	} //Switch Service State
}

$glpi->killSession();
?>