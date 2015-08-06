<?php
/* This scripts searches for legacy file links in labels and
 * relinks them back
 *
 * Written by Viettrung Luong 2015
 */
define('CLI_SCRIPT', 1);
require_once('../../config.php');

/* Abstract Template Class
 * 
 */
abstract class MoveLegacyFilesAbstract
{
    // Must Override
    abstract function build_link_record($old_url, $new_url, $activity, $activity_area, $check_url);

    /* Run SQL query for list of activities with legacy files.
     * @param string $table sql table
     * @param string $field sql field
     * @return array $results sql results
     */
    public function search_activity($table, $field)
    {
        global $DB;

        $results = '';
        $sql = 'SELECT * FROM {' . $table . '} WHERE ' . $field . ' LIKE ?';
        // Run query and return results
        $results = $DB->get_records_sql($sql, array('%file.php%'));

        return($results);
    } // End search_activity

    /* Search activity for legacy files.
     * @param array $sql_results sql results
     * @param string $activity_name activity name
     * @param string $area activity area
     */
    public function search_activity_files($sql_results, $activity_name, $area)
    {
        foreach($sql_results as $key=>$activity)
        {
            $matches[] = '';
            // Find where file.php pops up
            $regexp = "\/file.php\/([^\"]*)\"";
            preg_match_all("/$regexp/siU", $activity->{$area}, $matches, PREG_SET_ORDER);

            foreach($matches as $match)
            {
		// Trim out anything after '?'
                $search_match = array_shift(explode('?', $match[1]));
                echo "Matched Search: " . $search_match . "\n";

                // Get the relative url
                $relative_url = '/file.php/' . $search_match;

                // Get filename
                $filename = basename($search_match);
                echo "Found file: " . $filename . "\n";
                echo "Relative URL: " . $relative_url . "\n";

                // Call function to move the file to mod activity
                $this->move_legacy_file_to_activity($search_match, $relative_url, $filename, $activity, $activity_name, $area);
            }
        }
    } // End search_activity_files

    /* Move Legacy file to activity area
     * @param string $relativepath relative path
     * @param string $relative_url relative url
     * @param string $filename file name
     * @param array $activity activity
     * @param string $activity_name activity name
     * @param string $activity_area activity area
     */
    private function move_legacy_file_to_activity($relativepath, $relative_url, $filename, $activity, $activity_name, $activity_area)
    {    
	// Build the pathnamehash to locate the legacy file
	$args = explode('/', $relativepath);
	$rpath = array_slice($args, 1);
	$final_path = implode('/', $rpath);
    
	// Retrieve the file and store it temporarily
	$context = context_course::instance($activity->course);
	$fs = get_file_storage();
	$fullpath = "/$context->id/course/legacy/0/" . $final_path;
    
	// Double Check if legacy file is still there first
	$legacy_file_check = $fs->file_exists_by_hash(sha1($fullpath));
     
	if(!$legacy_file_check)
	{
	    echo "No legacy file found, skipping...\n\n";
	}
	else
	{
	    $old_file = $fs->get_file_by_hash(sha1($fullpath));
	    echo "Found Legacy file with pathnamehash: " . $old_file->get_pathnamehash() . "\n";
	    echo "Full path to Legacy File: " . $fullpath . "\n";
    
	    // Call the function to build the file record
	    $file_record = $this->build_file_record($filename, $context, $activity_name, $activity_area);
    
	    // check if file already in the proper location, if so, just update the url.
	    if($fs->file_exists($file_record->contextid, $file_record->component,
		    $file_record->filearea, $file_record->itemid,
		    $file_record->filepath, $file_record->filename))
	    {
		echo "File exists with pathnamehash: " . $file_record->pathnamehash .  " just replacing the urls\n";
		$this->update_file_link($relative_url, $activity, $file_record, $activity_name, $activity_area);
	    }
	    else
	    {
		// Create the file in the new location
		$sf = $fs->create_file_from_storedfile($file_record, $old_file);
		echo "Copied file with pathnamehash: " . sha1($fullpath) . " to new location with new pathnamehash:" . $sf->get_pathnamehash() . "\n";
		$this->update_file_link($relative_url, $activity, $file_record, $activity_name, $activity_area);
		$this->remove_legacy_file($old_file);
	    }
	}    
    } // End move_leagcy_file_to_activity
    
    /* Delete Legacy file
     * @param stdClass $fs file storage 
     * @param stdClass $old_file legacy file
     */
    private function remove_legacy_file($old_file)
    {
        // Double Check file is still there before deleting
        if($old_file->get_pathnamehash() !== '')
        {
            $old_file->delete();
            echo "Removed legacy file \n\n";
        }
    } // End remove_legacy_file

    /* Update link
     * @param string $old_path old path
     * @param array $activity activity
     * @param stdClass $file file
     * @param string $activity_name
     * @param string $activity_area
     */
    private function update_file_link($old_path, $activity, $file, $activity_name, $activity_area)
    {
        global $DB, $CFG;

        // Build new URL
        $new_url = $CFG->wwwroot . '/pluginfile.php/' . $file->contextid . '/' . $file->component . '/' . $file->filearea . '/' . $file->filename;
        $old_url = $CFG->wwwroot . $old_path;

        echo "New URL: " . $new_url . "\n";

        /* Call function to build the link record to be saved to DB. 
         * The build_link_record function is to be overriden by the relevant class.
         */
        $check_url = $CFG->wwwroot . '/pluginfile.php/' . $file->contextid . '/' . $file->component . '/intro/' . $file->filename;
        $link_record = $this->build_link_record($old_url, $new_url, $activity, $activity_area, $check_url);

        // Update DB with new record
        $DB->update_record($activity_name, $link_record, false);
    } // End update_file_link

    /* Build the file record for saving to the DB
     * @param string $filename file name
     * @param array $context context
     * @param string $activity_name activity name
     * @param string $activity_area activity area
     * @return stdClass
     */
    private function build_file_record($filename, $context, $activity_name, $activity_area)
    {
        //Create file record for new file
        $file_record = new stdClass();
        $file_record->userid = 2;  // Admin account
        $file_record->contextid = $context->id;
        $file_record->component = 'mod_' . $activity_name;
        $file_record->filearea = $activity_area;
        $file_record->itemid = 0;
        $file_record->filepath = '/';
        $file_record->licence = 'cc';   // Creative Commons
        $file_record->filename = $filename;
        $filee_record->source = '';
        $file_record->timecreated = time();
        $file_record->timemodified = time();

        return($file_record);
    }
} // End Abstract Class


// Move Legacy Label Files Class
class MoveLegacyLabelFiles extends MoveLegacyFilesAbstract
{

    public function build_link_record($old_url, $new_url, $label, $activity_area, $check_url)
    {
        $updated_content = str_replace($old_url, $new_url, $label->{$activity_area});

        // echo $updated_content . "\n";
        $label->{$activity_area} = $updated_content;
        $record = new stdClass();
        $record->id = $label->id;
        $record->course = $label->course;
        $record->name = $label->name;
        $record->{$activity_area} = $updated_content;
        $record->introformat = $label->introformat;
        $record->timemodified = time();

        return ($record);
    }
} // End MoveLegacyLabelFiles

// Move Legacy Assignment Files class
class MoveLegacyAssignmentFiles extends MoveLegacyFilesAbstract
{

    public function build_link_record($old_url, $new_url, $assign, $activity_area, $check_url)
    {
        $updated_content = str_replace($old_url, $new_url, $assign->{$activity_area});

        // echo $updated_content . "\n";
        $assign->{$activity_area} = $updated_content;
        $record = new stdClass();
        $record->id = $assign->id;
        $record->course = $assign->course;
        $record->name = $assign->name;
        $record->{$activity_area} = $updated_content;
        $record->introformat = $assign->introformat;
        $record->timemodified = time();

        return ($record);
    }
} // End MoveLegacyAssignmentFiles

// Move Legacy Page Files class
class MoveLegacyPageFiles extends MoveLegacyFilesAbstract
{

    public function build_link_record($old_url, $new_url, $page, $activity_area, $check_url)
    {
	// Check if the there's already an file plus updated link in intro area. If so, just copy

	echo $check_url . "\n";

	if($check_url === $new_url)
	{
	    $activity_area = 'intro';
	}
	
        $updated_area = str_replace($old_url, $new_url, $page->{$activity_area});
        $page->{$activity_area} = $updated_area;

        $record = new stdClass();
        $record->id = $page->id;
        $record->course = $page->course;
        $record->name = $page->name;
        $record->{$activity_area} = $updated_area;
        $record->introformat = $page->introformat;
        $record->contentformat = $page->contentformat;
        $record->timemodified = time();

        return ($record);
    }
} // End MoveLegacyPageFiles


// Start the Script
function start()
{

    echo "Searching Labels for Legacy Files\n";
    $labelFiles = new MoveLegacyLabelFiles();
    $sql_results = $labelFiles->search_activity('label', 'intro');
    $labelFiles->search_activity_files($sql_results, 'label', 'intro');

    echo "Searching Assignments for Legacy Files\n";
    $assignFiles = new MoveLegacyAssignmentFiles();
    $sql_results = $assignFiles->search_activity('assign', 'intro');
    $assignFiles->search_activity_files($sql_results, 'assign', 'intro');

    echo "Searching Pages for Legacy Files\n";
    $pageFiles = new MoveLegacyPageFiles();
    echo "Searching Intro Area of Pages\n";
    $sql_intro_results = $pageFiles->search_activity('page', 'intro');
    $pageFiles->search_activity_files($sql_intro_results, 'page', 'intro');

    echo "Searching Content Area of Pages\n";
    $sql_content_results = $pageFiles->search_activity('page', 'content');
    $pageFiles->search_activity_files($sql_content_results, 'page', 'content');
}


start();

?>

