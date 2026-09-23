CHANGELOG
------------

Unreleased

* Kirjuri now runs on PHP 8.1 and newer (tested on PHP 8.4 with MariaDB 10.11). It no longer runs on PHP 7.
* Added a test suite in tests/ (PHPUnit unit tests and HTTP integration tests against a real database), run by GitHub Actions. See the README.
* Added bin/kirjuri, a command line tool: doctor (environment and configuration checks), migrate, user management and password resets, error and event log viewers, audit log decryption and cache clearing. See the README.
* Database changes are now versioned migrations (lib/migrations.php), applied automatically after an upgrade. Rerunning install.php is no longer needed. New indexes speed up case, device, attachment and message lookups.
* Errors are logged to logs/error.log with a stack trace and a request ID, which is shown on the error page and sent in the X-Request-Id header. Database errors are no longer shown to visitors.
* Restructured the code: shared functions moved from include_functions.php into lib/, and submit.php's actions into actions/. PHPStan (level 5) runs in CI.
* Security fixes:
* - import_krf.php required no login and built SQL from unchecked keys in the uploaded file. It now requires a login and a CSRF token, and every key is validated before anything is written.
* - print_sticker.php, request.php and progress_bar_static.php showed case data without a login.
* - Adding a case, changing device status/location and message actions now check the logged in user.
* - LDAP: an empty password is refused (it caused an unauthenticated bind), and the username is escaped in the search filter.
* - Password hashes are no longer stored in every session's user list, and API keys are compared in constant time.
* - The API respects case access groups and can no longer move items between cases or change access groups.
* - Attachment uploads check the CSRF and case tokens and the case access group.
* - Failed logins are throttled per username (10 failures in 15 minutes). The old block file was deleted in the same request and did nothing.
* - Session cookies are HttpOnly and SameSite=Lax, and the session ID is regenerated at login.
* - Admin session handling, the backup, settings and audit viewer no longer accept path or shell metacharacters.
* - conf/, logs/ and cache/ include .htaccess files denying direct web access on Apache.
* Bug fixes:
* - The front page crashed with "Invalid parameter number" on current PHP/MySQL versions.
* - Installer: on PHP 8.1+ an existing database aborted the install halfway and left a credentials file behind, which blocked rerunning it.
* - PHP 8.1 returns integer columns as ints, which broke every access level check (admins were not treated as admins and add-only users saw the case list).
* - api.php crashed after every add/update (undefined logline()) and used a two digit year for its date range.
* - Deleting a user never protected the built-in accounts, and reported success when nothing was deleted.
* - Uploading an empty attachment crashed with a division by zero, and upload errors were not reported.
* - The login form's auth type check was never evaluated because of a misplaced parenthesis.
* - The message subject prefill always showed "1".
* - The case timeline page loaded jQuery from a path that does not exist.
* - log.php could not redirect unauthenticated users, and the language editor was open to all access levels.
* - delete_directory() followed symbolic links and deleted their targets' contents.
* - Logging in without a User-Agent header, editing a user without ticking every flag and force logging out without a Referer header logged PHP warnings.
* - Twig deprecation notices from index.twig were shown to users as error messages.
* - Tool reservations with missing dates were saved, as the empty-field check tested strings that always contained a space.
* - Importing a KRF file when no cases existed yet that year raised a PHP deprecation shown to the user.
* - upload.php passed its 16MB limit to file_get_contents() as the include path flag. The max_attachment_size check before it is what enforces the limit.
* - Removed demo PHP scripts bundled with the vis and FullCalendar libraries, which could be requested without logging in.
* - Two cases created at the same moment could get the same case number. Case creation (web form, API and KRF import) now shares one locked function.
* - The front page recounted and rewrote every case's device count on every view. Counts are now kept up to date where devices change; a migration corrects existing counts once.
* - Moving a device to another case from its memo did not check access to the target case, or that the target was a case at all, and left both device counts wrong.
* - Imported cases showed 0 devices until the front page was opened.
* - The device form lost its input after a validation error.
* - A user's "modified at" note used the month where the minutes belong.
* - The front page showed the crime and suspect of cases restricted to users whose names contain the viewer's name (bob saw cases restricted to bobby).
* - verify_case_ownership() checked the access group of the row it was given, so a device UID was checked against the device's empty group instead of its case's.
* - The case page never showed its warning about other requests with the same case file number.
* - The statistics page ran one query per case per unit; it now uses a few grouped queries.
* - The sender of a message could archive or delete the recipient's copy, and the recipient the sender's.
* - Session files stored the language strings and every user's record: about 26 KB each, now about 450 bytes.
* Actions that change data are sent as POST, with no CSRF or case token in the URL, and logout needs the token. The logged in user's password hash is no longer kept in the session.
* The API's add operation returns the new case's UID and case number.
* Added a Docker setup (docker compose up) that installs itself, and `php bin/kirjuri install` for installing without a browser.
* Added deny rules for .git/, tests/, docker/ and build files (composer.json, Dockerfile, docker-compose.yml), so a git checkout in the web root does not expose the repository.
* Database connections no longer allow several SQL statements in one query.
* Updated the following dependencies:
* - twig/twig (v2.4.6 => v3.29.0)
* - ezyang/htmlpurifier (v4.10.0 => v4.19.1)
* - picqer/php-barcode-generator (v0.2.2 => v2.4.2)
* - tinymce/tinymce (4.7.9 => 7.9.3). TinyMCE 7 is licensed under GPLv2+.
* - twbs/bootstrap (v3.3.7 => v3.4.1)
* - jQuery (3.1.1 => 3.7.1) and jQuery UI (1.12.1 => 1.13.3)

2018-03 Version 0.9.2

* A few fixes as requested and notified by the users of Kirjuri.
* Merged good work on the LDAP authentication parts from the good people of Missouri S&T
* Added a Spanish language file courtesy of QuiQue CiBeRs, thanks!
* Fixed a bug causing the statistics page to ignore the last day of the year.
* Updated the following dependencies:
* - picqer/php-barcode-generator (v0.2.1 => v0.2.2)
* - ezyang/htmlpurifier (v4.9.0 => v4.10.0)
* - symfony/polyfill-mbstring (v1.3.0 => v1.7.0)
* - twig/twig (v2.1.0 => v2.4.6)
* - tinymce/tinymce (4.5.2 => 4.7.9)

2017-09

* Fixed a few bugs
* Last release, I will no longer actively develop this project as I don't have time for it anymore. If you are interested in taking the lead in developing this project, please contact me at antti.kurittu@gmail.com.

2017-01    Version 0.9.1

* Made some tweaks to the user interface on user settings.
* Added a language file editor to easily localize Kirjuri. You can access it from settings -> lang -> click the "Lang"-title link. Language files are stored as JSON files. Older .conf files will be loaded if no .JSON file is present, but saved as JSON on first edit.
* Added a reservation calendar for the tools.
* Branched the Kirjuri git project to "develop" and "master", "develop" contains the bleeding edge changes which are still untested. These changes will be merged to master after enough testing has been done to make sure nothing breaks.
* Some bugfixes.

2017-01-22 Version 0.9.0:

* Dropped support for PHP5 - dependencies require this. Upgrade to PHP7.
* Updated dependencies: Twig v2.1.0, HTMLPurifier v4.8.0, php-barcode-generator v0.2.1., TinyMCE v4.5.2
* Greatly improved logging - Kirjuri logs events to logs/kirjuri.log and case events to case folders in the logs/-folder. Logging to database is deprecated, all logs now go to log files.
* Case timeline - A neat visualization of case events.
* Audit trail. All POST and GET requests that contain changes to requests or devices are saved locally. This enables auditing changes to requests and in some cases, restoring data to the database from previous POST requests. Audit logs are encrypted with a auto-generated key. You can change this key manually. You can access the audit logs and case timeline by clicking the green calendar icon in the case log.
* You can now export and import individual cases in the form of .krf files. These files include the examination request, all devices and all attachments. The file is a JSON object compressed with gzencode(), so importing and exporting large cases might take a while.
* LDAP authentication support, currently supports authenticating against Active Directory with the ldap_bind()-function. AD authentication creates a local account on first login. Authentication to this account is only allowed over LDAP. You can still use local accounts as usual, and disable this feature from the settings. If the feature is enabled, domain authentication is the default setting.
* A cool new background image from Subtle Patterns! https://subtlepatterns.com/
* Files are now uploaded to the database to simplify backup, to enable auditing file uploads & downloads and mitigate file inclusion vulnerabilities. If you have local files stored at the attachments/ subfolders, you can still access these, but uploading or removing local files is now deprecated. File size is limited to 16MB by database design to keep the database size reasonable, and files are compressed prior to writing to the database. Kirjuri is not designed to be used as a file storage, so this functionality is offered mainly for storing forensic reports, documents, images or other small files related to the case. Large files like drive images should be stored elsewhere.
* Started working on a RESTful API. The rest_api.php script is disabled by default, as it can only read information for now.
* Moved from incrementing release numbers to versioning.
* Renamed views/\*.html files to .twig
* A lot of small bugfixes.

2017-01-01 Release 133:

* Made the user session management a bit more sane.
* Handed the task of checking for a valid session to unreadcounter.php
* Added checking for session timestamp to determine whether session is online or not.
* Other tweaks and fixes.
* Fixed a typo in the Finnish language file that made the setting fail.

2016-12-29 Release 132:

* Made handling settings better - instead of editing a text file, fields are presented and settings file parsed from that.
* More tweaks, bugfixes and other betterments.
* Gave an input form to set the admin password on install time instead of defaulting to "admin".
* Removed adding extra examiners from settings file. Create users for this.
* Switched some glyphicons to Font Awesome icons. Gotta finish this.
* Ending an users session will force-logout after a while.

2016-12-27 release 131:

* Enhanced the request front page with a more concise layout.
* Added session information and administrator ability to terminate single sessions for users.
* Global black/whitelists for login IP addresses, configurable in conf/access_list.php.
* Tweaks, bugfixes, etc.

2016-12-25 release 130:

* Enhanced user management - force logout, delete user permanently
* Enhanced security features with per-user IP black/whitelisting. This will not restrict existing sessions but sets limits to new sessions.
* Bugfixes and performance tweaks.

2016-12-22 release 129:

* Big feature update!
* You can now set which index page columns are shown or not. The file to configure this behaviour is conf/index_columns.conf.
* Per-case, per user access control added. Users can restrict access to examination requests and users are not able to view or edit requests where they don't belong to the access group. Administrators can see and set any case access group. Access groups are independent of user privilege level.
* Case event logging; log files are now written for each case, and they are displayed on the examination request page to keep track of the investigation. Non-case related logs are written to kirjuri_case_0.log and errors to error.log. The logs are stored in kirjuri_errors.log and kirjuri_events.log when stored & cleared. All log events are also written to the database for audit purposes.
* CSRF protection enhanced, now works as per-session to enable a tabbed workflow.
* Implemented internal protections with case access tokens.
* User passwords are now stored with the standard password_hash() function, not sha256 (what was I even thinking?)
*

2016-12-18 release 128:

* Added CSRF protection to all pages, even though you shouldn't deploy Kirjuri on a network where this is an issue.
* Updated the German language file with the new language directives. Translation courtesy of Dennis Schreiber, thank you very much!
* Shuffled the message buttons around so that the tooltip doesn't cover the essential buttons.
* Added a possibility to create a device memo report template, so that you can add your own "fields" to it. Currently it has rows for imaging details. You can edit this file at conf/report_notes.template. HTML is allowed, the file is sanitized before presentation. You can copy the file to report_notes.local to preserver your local changes through updates.
* The "add-only" user now only sees the "Add a new request"-page.
* Message of the day will be shown at the login screen.

2016-12-08 release 127:

* Added a passwords field to the "new request"-page. The investigator can now add all known passwords to the request at the time of submission, and they will be displayed in the private notes section. Fixed a few minor annoyances.
* Statistics page will not load if no cases present.
* This update does not alter database structures.

2016-11-15:

* Fixed an error on newest MySQL (at least Ver 14.14 Distrib 5.7.16) where inserting a value of '' no longer populates an INT field with "0" but instead fails. Some dirty workarounds on submit.php to fix this. No need to update if your version works fine.

2016-10-11:

* Added two new data graphs to the statistics; amount of data per requesting unit and amount of devices per requesting unit.
* Small bugfixes.

2016-09-30:

* Added HTMLPurifier processing for strings presented as raw to prevent XSS.

2016-09-29:

* Upgraded the included TinyMCE from 4.2.7 to 4.4.3

2016-09-28:

* Implemented a simple internal messaging system. This update requires running ```install.php``` to create the necessary tables.

2016-09-27:

* THIS RELEASE CREATES AN ADDITIONAL DATABASE TABLE. RE-RUN ```install.php``` to auto-create the necessary tables. Back up your database before making any changes to your existing installation.
* Added support for managing your forensic tools and adding them to the case via a tool registry. The tool registry can be used to keep track of software and hardware versions in use complete with a version / update history. You can add information about used tools to a device by using the "Add a tool..."-dropdown in the device memo and saving the data. This will add a line to the examiners notes about the tools used. This information will not be printed to the report.
* Added support for using an IMEI database to automatically look up device details based on the TAC identified in the IMEI code. The database is not shared but you can request a copy from GSMA if you are eligible for one. The database format is as follows: "TAC|Marketing Name|Internal Model Name|Manufacturer|Bands|Allocation Date|Country Code|Fixed Code|Manufacturer Code|Radio Interface|Brand Name|Model Name|Operating System|NFC|Bluetooth|WLAN|Device Type". The administrator can upload an IMEI database via the settings or move it manually to ```conf/imei.txt```.

2016-09-23:

* Added support for printing and reading barcodes. Device and case stickers now have a barcode, which you can read to the "Search" box and jump straight to that device. The label printer has been tested with a Dymo LabelWriter 450. Support for barcodes has been built with https://github.com/picqer/php-barcode-generator
* Some visual changes to better handle limited screen real estate, stacked the action / status droplists on top of each other to make room for device information.
* Changed how enter behaves when filling forms from saving form to jumping to next field.
* Started working on an API. This is still very heavily a work in progress, and is at this time undocumented. I'm still including it in this update so that it can be tried out. Do not expect it to remain unchanged over future updates!
* Added a warning after three months if confiscation date is nearing +4 months (if this option is turned on, default off)
* Removed the timeout for open browser windows logging the user out.
* Added the ability to dump the database into a file for backing up from the browser. The link is under settings, available to admin accounts. Set your mysqldump binary location in the source if it doesn't work right away.
* Fixed some coding standards which jumbled a lot of the files around.
* Fixed a few minor bugs.


2016-09-14:

* Updated Twig to version 1.24.3 (https://github.com/twigphp/Twig)
* Updated Font Awesome to version 4.6.3 (http://fontawesome.io/)

2016-09-09:

* Big update!
* Implemented user management.
  * Passwords are saved as simple sha256 hashes, which should be enough for this use case and don't require dependencies
  * Users are stored in the database
* Simplified installation with an install script.
  * Run /install.php to rebuild database and add user tables
* Improved error / info message system.
* Removed clumsiness and utilized the session variable more.
* Streamlined template rendering a bit.
* Added comments to the code.
* Please notify me of any bugs you find, I've tested the new version but some might have slipped by.

2016-09-01:

* Fixed some finnish variable names. Tested Kirjuri with PHP7, seems to work fine.

2016-09-01:

* Streamlined the handling of settings - settings now take effect right away on any pageload.
* Kirjuri refuses to accept a device is type, action status and location have not been set.
* Slight tweaks here and there.
* Kirjuri triggers an error if default settings gets a new directive that is not found in the local settings in either /conf/settings.local or /etc/kirjuri/settings.local
* Added some comments to the code.

2016-08-31:

* Cleaned up submit.php and got rid of the clumsy return.php.
* Created a custom error handler that shows and/or logs errors. You can set the error level from your php.ini.
* Fixed a bunch of undefined indexes that created unnecessary notices and errors.
* Split up help.php to settings.php and help.php, so that access to settings can be limited with basic HTTP auth
* Made some small adjustments here and there.

2016-08-28:

* German translation added, thank you Dennis Schreiber for the language file!

2016-08-26:

* Added support for attaching files to examination requests. Script upload is prevented by renaming text files not ending in ```.txt```.

2016-08-25:

* Fixed page titles.
* Added removed items to CSV download.
* Fixed the statistics page counting removed items and cases.
* Slight UI enhancements.

2016-08-24:

* Changed licensing to the simpler MIT license.
* More elegant handling of mysql credentials, you can use an optional ignored file which will allow pulling/pushing changes.
* Made the device action and device location jump lists dynamic - choosing a value automatically saves it. This works in both device memo and devices overview.
* Added examiners private notes as an editable field in the device memo

2016-08-23:

* Moved the listings from settings to the language files where they belong.
* Translated some finnish named variables to english.
* Made it possible to copy ```settings.conf``` to ```settings.local``` and added it to gitignore so that pulling changes doesn't reset settings.

2016-08-22:

* Fixed the language file to use natural language variables for more readable code.
* Moved the MySQL credentials from the config file to source code.
* Cleaned up the inconsistent use of Twig brackets.
* Fixed page titles.
* Removed unused files.
* Added an icon to stalled cases (counting from latest update), stall threshold can be set from settings.
* Added possibility to download case data as a CSV file.
* Made the device listing droplists dynamic - no need to save one by one, changes effect immediately.
* Other small bugfixes.
