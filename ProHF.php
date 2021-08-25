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

    public function moveApptDataToRegistry() {
        // Only enabled on 1 project.  The apptDataToRegistry won't process anything unless it is the
        // correct appointment project. The apptDataToRegistry.php page will check to make sure it the pid is correct.
        $this->emDebug("In moveApptDataToRegistry cron job with prefix: " . $this->PREFIX);
        $enabled = ExternalModules::getEnabledProjects($this->PREFIX);
        while($row = $enabled->fetch_assoc()){

            $proj_id = $row['project_id'];

            // Put together a URL so we get into project context
            $this_url = $this->getUrl('pages/apptDataToRegistry.php', true, true) . '&pid=' . $proj_id;
            $this->emDebug("This is the cron URL: " . $this_url);

            // Go into project context and process data for this project
            $response = http_get($this_url);
            $this->emDebug("Response from cron GET: " . json_encode($response));
        }
    }

}
