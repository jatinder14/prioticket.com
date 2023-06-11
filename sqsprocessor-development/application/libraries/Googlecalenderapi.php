<?php

error_reporting(E_ALL);

ini_set("display_errors", 1);
require_once('./././google_calendar/vendor/autoload.php');

define('APPLICATION_NAME', 'Google Calendar API PHP Quickstart');
define('CREDENTIALS_PATH', './././google_calendar/calendar-php-qickstart.json');
define('CLIENT_SECRET_PATH', './././google_calendar/client_secret.json');

// If modifying these scopes, delete your previously saved credentials
// at ~/.credentials/calendar-php-quickstart.json
define('SCOPES', implode(' ', array(
    Google_Service_Calendar::CALENDAR)
));


# googleCalenderAPI: PHP wrapper class for googleCalender APIs
# Author: Karan Bhardwaj

class googleCalenderAPI {

    var $Client;
    var $EventDetails;

    public function __construct($secret_path = CLIENT_SECRET_PATH, $credential_path = CREDENTIALS_PATH) {
        
        /* Generate Google Client object */
        $client = new Google_Client();
        $client->setApplicationName(APPLICATION_NAME);
        $client->setScopes(SCOPES);
        $client->setAuthConfig($secret_path);
        $client->setAccessType('offline');
        $client->setApprovalPrompt('force');

        // Load previously authorized credentials from a file.
        $credentialsPath = $this->expandHomeDirectory($credential_path);
        if (file_exists($credentialsPath)) {
            $accessToken = json_decode(file_get_contents($credentialsPath), true);
        } else {
            // Request authorization from the user.
            $authUrl = $client->createAuthUrl();
            printf("Open the following link in your browser:\n%s\n", $authUrl);
            print 'Enter verification code: ';
            $authCode = trim(fgets(STDIN));

            // Exchange authorization code for an access token.
            $accessToken = $client->fetchAccessTokenWithAuthCode($authCode);

            // Store the credentials to disk.
            if (!file_exists(dirname($credentialsPath))) {
                mkdir(dirname($credentialsPath), 0700, true);
            }
            file_put_contents($credentialsPath, json_encode($accessToken));
            printf("Credentials saved to %s\n", $credentialsPath);
        }
        $client->setAccessToken($accessToken);

        if ($client->isAccessTokenExpired()) {
            $refreshToken = $client->getRefreshToken();
            $client->refreshToken($refreshToken);
            $newAccessToken = $client->getAccessToken();
            $newAccessToken['refresh_token'] = $refreshToken;
            file_put_contents($credentialsPath, json_encode($newAccessToken));
        }
        // Refresh the token if it's expired.
//        if ($client->isAccessTokenExpired()) {
//            $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
//            file_put_contents($credentialsPath, json_encode($client->getAccessToken()));
//        }
        $this->Client = $client;
    }

    /**
     * Expands the home directory alias '~' to the full path.
     * @param string $path the path to expand.
     * @return string the expanded path.
     */
    function expandHomeDirectory($path) {
        $homeDirectory = getenv('HOME');
        if (empty($homeDirectory)) {
            $homeDirectory = getenv('HOMEDRIVE') . getenv('HOMEPATH');
        }
        return str_replace('~', realpath($homeDirectory), $path);
    }

    //create_event();
    //show_list();
    //update_event(1);
    //find_update();
    //get_single_event();
    function get_single_event() {
        // Get the API client and construct the service object.
        $client = $this->Client;
        $service = new Google_Service_Calendar($client);
        $event = $service->events->get('primary', "216n28nqcqs75tnhmrcq64ld68");
        echo $event->location;
    }

    function create_event() {
        $client = $this->Client;
        // Get the API client and construct the service object.
        $service = new Google_Service_Calendar($client);
        $event = new Google_Service_Calendar_Event($this->EventDetails);

        $calendarId = 'primary';
        $event = $service->events->insert($calendarId, $event);
        return $event->getId();
    }

    function create_calendar() {
        // Get the API client and construct the service object.
        $client = $this->Client;
        $service = new Google_Service_Calendar($client);
        $calendar = new Google_Service_Calendar_Calendar();
        $calendar->setSummary('calendarSummary');
        $calendar->setTimeZone('America/Los_Angeles');

        $createdCalendar = $service->calendars->insert($calendar);
        echo $createdCalendar->getId();
    }

    function show_list() {
        // Get the API client and construct the service object.
        $client = $this->Client;
        $service = new Google_Service_Calendar($client);
        //// Print the next 10 events on the user's calendar.
        $calendarId = 'primary';
        $optParams = array(
            'maxResults' => 10,
            'orderBy' => 'startTime',
            'singleEvents' => TRUE,
            'timeMin' => date('c'),
        );
        $results = $service->events->listEvents($calendarId, $optParams);

        if (count($results->getItems()) == 0) {
            print "No upcoming events found.\n";
        } else {
            print "Upcoming events:\n";
            foreach ($results->getItems() as $event) {

                $start = $event->start->dateTime;
                echo "<pre>";
                print_r($event);
                echo "</pre>";
                if (empty($start)) {
                    $start = $event->start->date;
                }
            }
        }
    }

    function update_event($event_id, $details = array()) {
        // Get the API client and construct the service object.
        $client = $this->Client;
        $service = new Google_Service_Calendar($client);
        // First retrieve the event from the API.
        $event = $service->events->get('primary', $event_id);
        $this->CreateLog('GoogleCalenderEvent.php', 'event_details', array('EventDetailsData' => json_encode($event)));
        if($event->status == 'cancelled'){
            $updated_event_id = $this->create_event();
            $this->CreateLog('GoogleCalenderEvent.php', 'created_Update_event_id', array('Updated_event_id' => json_encode($updated_event_id)));
            return $updated_event_id;
        } else {
            foreach ($details as $key => $val) {
                $data = $event->{'get' . $key}();
                if ($key == 'description') {
                    $data = explode(', ' . PHP_EOL, $data);
                    $data[] = $val;
                    $data = implode(', ' . PHP_EOL, $data);
                    $event->{'set' . $key}($data);
                } else if ($key == 'attendees') {
                    $data = array_merge($data, $val);
                    $event->{'set' . $key}($data);
                } else if ($key == 'summary') {
                    $event->setSummary($val);
                } else {
                    $event->{'set' . $key}($val);
                }
            }
            $updatedEvent = $service->events->update('primary', $event->getId(), $event);
            $this->CreateLog('GoogleCalenderEvent.php', 'UpdateEventData', array('UpdatedEventData' => json_encode($updatedEvent)));
        }
    }

    function find_update() {
        // Get the API client and construct the service object.
        $client = $this->Client;
        $service = new Google_Service_Calendar($client);
        // Print the next 10 events on the user's calendar.
        $calendarId = 'primary';
        $optParams = array(
          //'maxResults' => 10,
            'orderBy' => 'startTime',
            'singleEvents' => TRUE,
            'timeMin' => date('c'),
        );
        $results = $service->events->listEvents($calendarId, $optParams);

        if (count($results->getItems()) == 0) {
            print "No upcoming events found.\n";
        } else {
            foreach ($results->getItems() as $event) {
                $event_summary = explode("-", $event->getSummary());
                if (count($event_summary) == 2) {
                    if ($event_summary[1] == "112") {
                        echo $event->getid() . "<br>";
                        echo $event->location;
                        update_event($event->getid());
                    }
                } else {
                    delete_event($event->getid());
                }
            }
            show_list();
        }
    }

    function delete_event($event_id) {
        //Get the API client and construct the service object.
        $client = $this->Client;
        $service = new Google_Service_Calendar($client);
        $service->events->delete('primary', $event_id);
    }
    
     /**
     * @Name : CreateLog()
     * @Purpose : To Create logs    
     */
    function CreateLog($filename, $apiname, $paramsArray) {
        if (ENABLE_LOGS) {
            $log = 'Time: ' . date('m/d/Y H:i:s') . "\r\r:" . $apiname . ': ';
            if (count($paramsArray) > 0) {
                $i = 0;
                foreach ($paramsArray as $key => $param) {
                    if ($i == 0) {
                        $log .= $key . '=>' . $param;
                    } else {
                        $log .= ', ' . $key . '=>' . $param;
                    }
                    $i++;
                }
            }

            if (is_file('qrcodes/images/' . $filename)) {
                if (filesize('qrcodes/images/' . $filename) > 1048576) {
                    rename("qrcodes/images/$filename", "qrcodes/images/" . $filename . "_" . date("m-d-Y-H-i-s") . ".php");
                }
            }
            $fp = fopen('qrcodes/images/' . $filename, 'a');
            fwrite($fp, "\n\r\n\r\n\r" . $log);
            fclose($fp);
        }
    }
}

?>
