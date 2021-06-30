<?php
error_reporting(E_ALL);
require_once("glpi_api.php");

$config = require_once('config.php');

function logging($msg) {
	global $config;

	if ($config['logging']) {
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

$eventval = [];
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

function getOpenSearchCriteria($last_state) {
	global $service, $eventhost;

	$last_state = ucfirst(strtolower($last_state));
	return [
		'criteria' => [
			[
				'field'	=> '12',
				'searchtype' => 'notcontains',
				'value' => 6
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

function closeTicket($tickets_id) {
	global $glpi;

	$glpi->updateItem('Ticket', [
		'input'	=> [
			'id'		=> $tickets_id,
			'status'	=> 6
		]
	]);
}

function createTicket() {
	global $glpi, $config, $eventhost, $servicestate, $servicestatetype, $hoststate,
	$service, $serviceattempts, $maxserviceattempts, $lastservicestate, $servicecheckcommand,
	$serviceoutput, $longserviceoutput;

	$state_label = ucfirst(strtolower($servicestate));
	$glpi->addItem('Ticket', [
		'input'	=> [
			'name' => "$service on $eventhost is in a $state_label State!",
			'content' => "$service on $eventhost is in a $state_label State.  Please check that the service or check is running and responding correctly<br>
				Check service status at {$config['nagios_host']} \n
				<b>Service Check Details</b> 
				Host \t\t\t = $eventhost<br>
				Service Check \t = $service<br>
				State \t\t\t = $servicestate<br>
				Check Attempts \t = $serviceattempts/$maxserviceattempts<br>
				Check Command \t = $servicecheckcommand<br>
				Check Output \t\t = $serviceoutput<br><br>
				$longserviceoutput",
			'priority' => $config['critical_priority'],
			'_users_id_requester' => $config['glpi_requester_user_id'],
			'_groups_id_requester' => $config['glpi_requester_group_id'],
			'_users_id_observer' => $config['glpi_watcher_user_id'],
			'_groups_id_observer' => $config['glpi_watcher_group_id'],
			'_users_id_assign' => $config['glpi_assign_user_id'],
			'_groups_id_assign' => $config['glpi_assign_user_id']
		]
	]);
}

function changeTicketNameStatus($tickets_id, $state) {
	global $glpi, $config, $service, $eventhost;

	$state_label = ucfirst(strtolower($state));
	$update_post['input'][] = [
		'id' 		=> $tickets_id,
		'name' 		=> "$service on $eventhost is in a $state_label State!",
		'priority' 	=> $config['critical_priority']
	];
	$glpi->updateItem('Ticket', $update_post);
}

function addStateChangeFollowup($tickets_id, $state) {
	global $glpi;

	$state_label = ucfirst(strtolower($state));
	$followup_post['input'][] = [
		'itemtype'		=> 'Ticket',
		'items_id' 		=> $tickets_id,
		'is_private' 	=> "0",
		'content' 		=> "State changed to $state_label, priority updated"
	];
	$glpi->addItem('Ticket/' .$tickets_id .'/ITILFollowup', $followup_post);
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
				case "WARNING":
					logging("Manage Service Tickets: Last Service State $lastservicestate, checking for open $lastservicestate Tickets");
					$search = getOpenSearchCriteria($lastservicestate);

					$tickets = $glpi->search('Ticket', $search);

					if (!empty($tickets['data']->data)){
						logging("Manage Service Tickets: Found open $lastservicestate Tickets, updating tickets");
						foreach ($tickets['data']->data as $ticket) {
							closeTicket($ticket->{2});
						}
					} else {
						logging("Manage Service Tickets: No $lastservicestate Tickets found, exiting gracefully");
					}
					break;
				case "OK":
				case "UNKNOWN":
					logging("Manage Service Tickets: Last Service State $lastservicestate, exiting gracefully");
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
								$search = getOpenSearchCriteria($lastservicestate);

								$tickets = $glpi->search('Ticket', $search);
								
								if (!empty($tickets['data']->data)){
									logging("Manage Service Tickets: Found open Warning Tickets, updating tickets");
									$post = array('input' => array());
									foreach ($tickets['data']->data as $ticket) {
										changeTicketNameStatus($ticket->{2}, $servicestate);
										addStateChangeFollowup($ticket->{2}, $servicestate);
									}
								}
								break;
							case "OK":
							case "UNKNOWN":
								createTicket();
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
								$search = getOpenSearchCriteria($lastservicestate);

								$tickets = $glpi->search('Ticket', $search);
								
								if (!empty($tickets['data']->data)){
									$post = array('input' => array());
									foreach ($tickets['data']->data as $ticket) {
										changeTicketNameStatus($ticket->{2}, $servicestate);
										addStateChangeFollowup($ticket->{2}, $servicestate);
									}
								}
								break;
							case "OK":
							case "UNKNOWN":
								createTicket();
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