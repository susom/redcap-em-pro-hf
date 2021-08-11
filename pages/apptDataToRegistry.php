<?php
namespace Stanford\ProHF;
/** @var \Stanford\ProHF\ProHF $module */


use \REDCap;
use \Project;
use \Exception;

$pid = isset($_GET['pid']) && !empty($_GET['pid']) ? $_GET['pid'] : null;
$module->emDebug("Pid: " . $pid);

$appt_form = 'demographics';
$registry_form = 'enrollment';

// Retrieve the project we should be processing for and make sure this is it
$registry_pid = $module->getSystemSetting("registry-db");
$appt_pid = $module->getSystemSetting("appt-db");
if ($pid <> $appt_pid) {
    $module->emError("This project does not match the appointment DB ($pid) - exiting");
    return;
}

// Retrieve all appointments that have not yet happened
list($appointments, $appt_fields) = retrieveAppointmentList($appt_pid);
$module->emDebug("Retrieved " . count($appointments) . " appointments");

// Retrieve all patients in registry project
list($patients, $pat_fields) = retrieveRegistryPatients($registry_pid);
$module->emDebug("Retrieved " . count($patients) . " patients");
// Update any patients that have not yet decided on participating in the study
list($new_patients, $update_patients) =compareApptsToPatients($appointments, $appt_fields, $patients, $pat_fields, $registry_pid);
$module->emDebug("Number of new patients: " . count($new_patients));
$module->emDebug("Number of updated patients: " . count($update_patients));


// Save new patients with their closest appointment date

$status_new = savePatientData($registry_pid, $new_patients);
$status_update = savePatientData($registry_pid, $update_patients);

// Save success status
if ($status_new and $status_update) {
    $module->emDebug("Successfully processed appointments for PRO-HF");
    //\REDCap::logEvent("PRO-HF EM","Successfully processed appointments for PRO-HF");
} else {
    if (!$status_new) {
        $module->emError("Could not update the Registry project $registry_pid with new patients");
    }
    if (!$status_update) {
        $module->emError("Could not update the Registry project $registry_pid with updated appointments");
    }
}

// go back to the runLoader page
$loader_url = $module->getUrl("pages/runLoader.php", false, true);
header("Location: " . $loader_url);
exit;

function retrieveAppointmentList($appt_pid) {

    global $Proj, $module;

    // Retrieve all fields on the demographics form
    $first_form = $Proj->firstForm;
    $first_event = $Proj->firstEventId;
    $fields = array_keys($Proj->forms[$first_form]['fields']);

    // There is a config entry where Alex can specify a list of providers that we will filter  on.
    // This is to allow him to slowly add providers once we verify the workflow is what they want.
    $provider_filter_list = $module->getProjectSetting("enabled-providers");
    if (!empty($provider_filter_list)) {

        // This is a comma separated list of providers to filter on  (i.e. "Sandhu, Ashley")
        $list = explode(',', $provider_filter_list);
        $providers =  '';
        foreach($list as $name) {
            $prov_name = upper(trim($name));
            if (empty($providers)) {
                $providers = ' and  (starts_with([clinician], "' . $prov_name . '")';
            } else {
                $providers .= ' or starts_with([clinician], "' . $prov_name . '")';
            }
        }
        $providers .= ')';
    } else {
        $providers = '';
    }

    // Create the filter so we only retrieve appointments that in the future
    $now = date('Y-m-d H:i:s');
    $filter = '[appt_date] > "' . $now . '"' . $providers;
    $module->emDebug("Filter: " . $filter);

    // Retrieve all the appointment information from the demographics form
    $params = array(
        'return_format' => 'array',
        'project_id'    => $appt_pid,
        'filterLogic'   => $filter,
        'fields'        => $fields
    );
    $all_appts = REDCap::getData($params);

    // Loop over each appointment and reformat all appointments into a patient list keeping the appt that is closest to today
    $appointments = array();
    foreach($all_appts as $record_id => $this_appt) {
        $one_appt = $this_appt[$first_event];

            // Check to see if we already have an appt for this patient, if not, add it.
            $mrn = str_replace('-', '', $one_appt['mrn']);
            $one_appt['mrn'] = $mrn;
            if (empty($appointments[$mrn]) ) {

                $appointments[$mrn] = $one_appt;
            } else {
                // If this patient already has an appt, determine if this one is sooner and if so, replace the old one
                $saved_appt = $appointments[$mrn]['appt_date'];
                $new_appt_date = $one_appt['appt_date'];

                if ($saved_appt > $new_appt_date ) {
                    // This new appt date is closer to today than the saved appt so save this new date
                    $appointments[$mrn] = $one_appt;
                }
            }
            // If this appt was canceled, remove the app_date
            if ($appointments[$mrn]['appt_status'] == 'Canceled'){
                $appointments[$mrn]['appt_date']='';
                $appointments[$mrn]['appt_date_time']='';
            }
    }

    return array($appointments, $fields);
}

function retrieveRegistryPatients($registry_pid) {

    global $module;

    // Get the data dictionary for the registry project
    try {
        $reg_data_dictionary = new Project($registry_pid);
    } catch (Exception $ex) {
        $module->emError("Cannot retrieve data dictionary for project $registry_pid. Exception: " . $ex);
        return null;
    }

    // Retrieve all fields on the demographics form
    $first_form = $reg_data_dictionary->firstForm;
    $first_event = $reg_data_dictionary->firstEventId;
    $fields = array_keys($reg_data_dictionary->forms[$first_form]['fields']);

    // Create the filter so we only retrieve patients who have not decided on consent yet.
    $filter = '[cons_eng] = "" and [cons_span] = "" and [declined] = ""';

    // Retrieve all the appointment information from the demographics form
    $params = array(
        'return_format' => 'array',
        'project_id'    => $registry_pid,
        'filterLogic'   => $filter,
        'fields'        => $fields
    );
    $all_patients = REDCap::getData($params);

    // Reformat the data so it is indexed by MRN
    $patients = array();
    foreach($all_patients as $record_id => $one_patient) {
        $patient_rec = $one_patient[$first_event];
        $mrn = $patient_rec['mrn'];
        $patients[$mrn] = $patient_rec;
    }

    return array($patients, $fields);
}


function compareApptsToPatients($appointments, $appt_fields, $patients, $pat_fields, $registry_pid) {

    global $module;


    // For new patients, find the next record id and get list of fields that are common between the 2 projects
    $next_record_id = findNextRecord($registry_pid);
    $common_fields = array_intersect($appt_fields, $pat_fields);

    $update_fields = array('appt_date_time','appt_date', 'pat_enc_csn_id', 'appt_status', 'appt_cancelled_reason', 'clinician',
                           'newpt', 'virtualvisit', 'hf', 'visit_type','email','email_overwrite');

    // Retrieve the json list that stores the provider's names and emails
    $provider_list = $module->getProjectSetting('provider-list');
    $providers = json_decode($provider_list, true);

    // Loop over appointments and see if this person exists in the registry project
    $new_patient_list = array();
    $update_patient_list = array();
    foreach($appointments as $mrn => $appt) {

        // Find the name and email of the attending for this appt
        $attending = $providers[upper($appt['clinician'])];

        if (empty($patients[$mrn])) {

            // This person does not exist in the registry project yet so add them
            $new_patient = array_intersect_key($appt, array_flip($common_fields));
            $new_patient['record_id'] = $next_record_id++;
            $new_patient['clinician_attending'] = $attending['name'];
            $new_patient['clinician_email'] = extractEmailFromText($attending['email']);
            $new_patient['email'] = extractEmailFromText($appt['email']);
            $new_patient['email_overwrite'] = extractEmailFromText($appt['email_overwrite']);
            $new_patient['clinstrata'] = $attending['code'];
            $new_patient['appt_date_time'] = $appt['appt_date'];
            $new_patient['appt_date'] = substr($appt['appt_date'],0,10);
            $new_patient_list[] = $new_patient;

        } else {

            // Just update the appointment info and not the demographics
            $update_patient = array_intersect_key($appt, array_flip($update_fields));
            $update_patient['record_id'] = $patients[$mrn]['record_id'];
            $new_patient['clinician_attending'] = $attending['name'];
            $new_patient['clinician_email'] = extractEmailFromText($attending['email']);
            $new_patient['clinstrata'] = $attending['code'];
            $new_patient['appt_date_time'] = $appt['appt_date'];
            $update_patient['email'] = extractEmailFromText($appt['email']);
            $update_patient['email_overwrite'] = extractEmailFromText($appt['email_overwrite']);
            $update_patient['appt_date'] = substr($appt['appt_date'],0,10);
            $update_patient_list[] = $update_patient;
        }
    }

    // Return the patient lists
    return array($new_patient_list, $update_patient_list);
}

function savePatientData($pid, $data) {

    global $module;

    $module->emDebug("Project ID:$pid");
    $module->emDebug("data:".count($data));
    foreach($data as $one_patient){
        $one_patient['enrollment_complete']=2;
        $response = REDCap::saveData($pid, 'json', json_encode(array($one_patient)),'overwrite');
        if (!empty($response['errors'])) {
            $module->emError("Could not save patient data for project $pid. Error " . $response['errors']);
            $module->emDebug("Return From Save Data:" . json_encode($response));
            //REDCap::logEvent("PRO-HF EM","Could not save patient data for project Error " .json_encode( $response['errors']));
        }

    }
    return true;
}

function findNextRecord($registry_pid) {

    global $module;

    // Find the next record_id for new patients
    $record_list = REDCap::getData($registry_pid, 'array', null, array('record_id'));
    $record_ids = array_keys($record_list);
    $max_record_id = max($record_ids);
    if (empty($max_record_id)) {
        $max_record_id = 1;
    } else {
        $max_record_id = $max_record_id + 1;
    }

    return $max_record_id;

}

function extractEmailFromText($string){
    // try to extract a valid email address from text field returns empty string if not possible
    $pattern = "/[a-z0-9_\-\+\.]+@[a-z0-9\-]+\.([a-z]{2,4})(?:\.[a-z]{2})?/i";
    preg_match_all($pattern, $string, $matches);
    return trim($matches[0][0]);
}

