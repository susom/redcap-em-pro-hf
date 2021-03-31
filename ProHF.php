<?php
namespace Stanford\ProHF;

use ExternalModules\ExternalModules;
require_once "emLoggerTrait.php";

class ProHF extends \ExternalModules\AbstractExternalModule {

    use emLoggerTrait;

    public function __construct() {
		parent::__construct();
		// Other code to run when object is instantiated
	}

    public function redcap_save_record($project_id, $record,  $instrument,  $event_id,  $group_id=NULL,  $survey_hash=NULL,  $response_id=NULL, $repeat_instance)
    {
        $this->emDebug("In redcap_save_record with record id " . $record);
        $this->emDebug("project id $project_id, record $record, instrument $instrument");
        $this->moveApptDataToRegistry();
    }


    public function moveApptDataToRegistry() {
        // Only enabled on 1 project.  The apptDataToRegistry won't process anything unless it is the
        // correct appointment project
        $enabled = ExternalModules::getEnabledProjects($this->PREFIX);
        while($row = $enabled->fetch_assoc()){

            $proj_id = $row['project_id'];

            // Put together a URL so we get into project context
            // $this_url = $this->getUrl('pages/apptDataToRegistry.php', true, true) . '?pid=' . $proj_id;
            $this_url = $this->getUrl('pages/apptDataToRegistry.php', true, true);
            $this->emDebug("This is the URL: " . $this_url);

            // Go into project context and process data for this project
            $response = http_get($this_url);
            $this->emDebug("Response from GET: " . json_encode($response));
        }
    }

}
