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
    /* Must Override
     * @param string $old_url Old URL
     * @param string $new_url New URL
     * @param array $activity Activity
     * @param string $activity_area Activity Area
     * @param string $found_filearea Found Filearea
     */
    abstract function build_link_record($old_url, $new_url, $activity, $activity_area, $found_filearea);

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
		$matched = (explode('?', $match[1]));
                $search_match = array_shift($matched);
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
	global $DB;
	
        // Build the pathnamehash to locate the legacy file
        $args = explode('/', $relativepath);
        $rpath = array_slice($args, 1);
        $final_path = implode('/', $rpath);

        // Retrieve the file and store it temporarily
        $context = context_course::instance($activity->course);
        $fs = get_file_storage();
        $fullpath = "/" . $context->id . "/course/legacy/0/" . $final_path;

	echo "Fullpath: " . $fullpath . "\n";

        //Check for instance of file via filename, ignoring draftfiles
	$file_instance = $DB->get_record_sql('SELECT * FROM {files} WHERE pathnamehash LIKE ? AND filearea <> ? ', array(sha1($fullpath), 'draft'));
        
	// Check which area of the activity that the file is in
        switch ($file_instance['filearea'])
        {
	    case 'intro':
	    case 'content':
		// Code block for matching intro or content
		echo "File already exists in non-legacy area: " . $file_instance->filearea . " : Updating link to match...\n";
		$old_file = $fs->get_file_by_hash($file_instance['pathnamehash']);
		
		// Call the function to build the file record
		$file_record = $this->build_file_record($old_file, $context, $activity_name, $activity_area);
		$this->update_file_link($relative_url, $activity, $file_record, $activity_name, $activity_area, $file_instance['filearea']);
		break;
		// End code block
	    case 'legacy':
		// Code Block for matching legacy
		echo "Found Legacy file, relocating...\n";
		$old_file = $fs->get_file_by_hash($file_instance['pathnamehash']);
		
		// Call the function to build the file record
		$file_record = $this->build_file_record($old_file, $context, $activity_name, $activity_area);
		
		// Create the file in the new location
		$sf = $fs->create_file_from_storedfile($file_record, $old_file);
		echo "Copied file with pathnamehash: " . $file_instance['pathnamehash'] . " to new location with new pathnamehash:" . $sf->get_pathnamehash() . "\n";
		$this->update_file_link($relative_url, $activity, $file_record, $activity_name, $activity_area, $file_instance['filearea']);
		$this->remove_legacy_file($old_file);
		break;
		// End code block
	    default:
		echo "File not found in expected filearea of intro, content or legacy: " . $file_instance['filearea'] . " Skipping...\n\n";
        }
    } // End move_leagcy_file_to_activity

    
    /* Delete Legacy file
     * @param stdClass $fs file storage
     * @param stdClass $old_file legacy file
     */
    private function remove_legacy_file($old_file)
    {
        // Double Check file is still there before deleting
        if($old_file->get_pathnamehash())
        {
            $old_file->delete();
            echo "Removed legacy file.\n\n";
        }
    } // End remove_legacy_file

    
    /* Update link
     * @param string $old_path old path
     * @param array $activity activity
     * @param stdClass $file file
     * @param string $activity_name
     * @param string $activity_area
     */
    private function update_file_link($old_path, $activity, $file, $activity_name, $activity_area, $found_filearea)
    {
        global $DB, $CFG;

        // Build new URL
        $new_url = $CFG->wwwroot . '/pluginfile.php/' . $file->contextid . '/' . $file->component . '/' . $file->filearea . $file->filepath . $file->filename;
        $old_url = $CFG->wwwroot . $old_path;

        echo "New URL: " . $new_url . "\n";

        /* Call function to build the link record to be saved to DB.
         * The build_link_record function is to be overriden by the relevant class.
         */
        $link_record = $this->build_link_record($old_url, $new_url, $activity, $activity_area, $found_filearea);

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
    private function build_file_record($file, $context, $activity_name, $activity_area)
    {
        //Create file record for new file
        $file_record = new stdClass();
        $file_record->userid = 2;  // Admin account
        $file_record->contextid = $context->id;
        $file_record->component = 'mod_' . $activity_name;
        $file_record->filearea = $activity_area;
        $file_record->itemid = 0;
        $file_record->filepath = $file->get_filepath();
        $file_record->author = $file->get_author();
        $file_record->license = $file->get_license();
        $file_record->filename = $file->get_filename();
        $file_record->mimetype = $file->get_mimetype();        
        $file_record->source = '';
        $file_record->timecreated = time();
        $file_record->timemodified = time();

        return($file_record);
    }
} // End Abstract Class


// Move Legacy Label Files Class
class MoveLegacyLabelFiles extends MoveLegacyFilesAbstract
{
    public function build_link_record($old_url, $new_url, $label, $activity_area, $found_filearea)
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
    public function build_link_record($old_url, $new_url, $assign, $activity_area, $found_filearea)
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
    public function build_link_record($old_url, $new_url, $page, $activity_area, $found_filearea)
    {
        // Check if the there's already a file plus updated link in intro area. If so, just copy
        if($found_filearea === 'intro' && $activity_area === 'content')
        {
            echo "Found file in another file area: " . $found_filearea . "\n";
            $new_url = str_replace($activity_area, $found_filearea, $new_url);
	    echo "Updating URL to existing one: " . $new_url . "\n\n";
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
    echo "Searching Labels for Legacy Files...\n";
    $labelFiles = new MoveLegacyLabelFiles();
    $sql_results = $labelFiles->search_activity('label', 'intro');
    $labelFiles->search_activity_files($sql_results, 'label', 'intro');

    echo "Searching Assignments for Legacy Files...\n";
    $assignFiles = new MoveLegacyAssignmentFiles();
    $sql_results = $assignFiles->search_activity('assign', 'intro');
    $assignFiles->search_activity_files($sql_results, 'assign', 'intro');

    $pageFiles = new MoveLegacyPageFiles();
    echo "Searching Intro Area of Pages for Legacy Files...\n";
    $sql_intro_results = $pageFiles->search_activity('page', 'intro');
    $pageFiles->search_activity_files($sql_intro_results, 'page', 'intro');

    echo "Searching Content Area of Pages...\n";
    $sql_content_results = $pageFiles->search_activity('page', 'content');
    $pageFiles->search_activity_files($sql_content_results, 'page', 'content');
}


// Start everything
start();

?>
