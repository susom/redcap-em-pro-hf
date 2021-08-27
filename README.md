# ProHF
This EM takes appointment data stored in the PRO HF appointment database and transfers
it to the Registry database.

The appointments are populated by the CEP engine (by Sanjay) using the HL7 feed so all appointment
changes are recorded in real time.

## Setup
The Registry project and Appointment projects need to be selected in the System Settings.

# Cron
This module runs on a cron at 23:00hr daily.
