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
    abstract function build_link_record($old_url, $new_url, $activity, $activity_area, $found_filearea, $positions_array);

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
     * @param string $activity_area activity area
     */
    public function search_activity_files($sql_results, $activity_name, $activity_area)
    {
        foreach($sql_results as $key=>$activity)
        {
            $matches[] = '';
            // Find where file.php pops up
            $regexp = "\/file.php\/([^\"]*)\"";
            preg_match_all("/$regexp/siU", $activity->{$activity_area}, $matches, PREG_OFFSET_CAPTURE);

            for($i=1; $i < count($matches); $i++)
            {
                // Iterate through each match and group them together into a results table
                $result = array();
                foreach($matches[$i] as $match)
                {
                    $relative_path = $match[0];
                    if(isset($result[$relative_path]))
                    {
                        // Append to an existing match array
                        $result[$relative_path][] = $match;
                    }
                    else
                    {
                        // Create a new match array
                        $result[$relative_path] = array($match);
                    }
                }

                // Process each of the results in the table
                foreach($result as $relative_path=>$positions_array)
                {
                    // Get the relative url and clean the filename
                    $relative_url = '/file.php/' . $relative_path;
                    $filename = urldecode(basename($relative_path));
                    $filename = strtok($filename, "?");

                    echo "Found file: " . $filename . "\n";
                    echo "Relative URL: " . $relative_url . "\n";

                    // Call function to move file and add links.
                    $this->move_legacy_file_to_activity($relative_path, $relative_url, $filename, $activity, $activity_name, $activity_area, $positions_array);
                }
            }
        }
    } // End search_activity_files


    /* Move Legacy file to activity area
     * @param string $relative_path relative path
     * @param string $relative_url relative url
     * @param string $filename file name
     * @param array $activity activity
     * @param string $activity_name activity name
     * @param string $activity_area activity area
     */
    private function move_legacy_file_to_activity($relative_path, $relative_url, $filename, $activity, $activity_name, $activity_area, $positions_array)
    {
        global $DB;

        // Build the pathnamehash to locate the legacy file
        $args = explode('/', $relative_path);
        $rpath = array_slice($args, 1);
        $final_path = implode('/', $rpath);
        $context = context_course::instance($activity->course);
        $fullpath = "/" . $context->id . "/course/legacy/0/" . urldecode($final_path);
        $legacy_file_pathnamehash = sha1(strtok($fullpath, "?"));
        echo $legacy_file_pathnamehash ."\n";

        // Prepare legacy file storage
        $fs = get_file_storage();

        // Check if file have already between moved from a different area
        $areas = array( "intro",
                "content"
        );

        foreach($areas as $area)
        {
            //Check for instance of file via filename, ignoring draftfiles
            $file_instance = $DB->get_record_sql('SELECT * FROM {files} WHERE filename LIKE ? AND filearea = ? AND component = ? ', array($filename, $area, "mod_" . $activity_name));

            if(!$file_instance)
            {
                echo "Nothing found under $area \n";
                $found_filearea[$area] = 0;
            }
            else
            {
                echo "Found existing file under $area \n";
                $found_filearea[$area] = 1;
            }
        }

        // Double check if legacy file actually exists, sometimes there's a typo!
        if($fs->file_exists_by_hash($legacy_file_pathnamehash))
        {
            // Check if the file has already been moved to an existing area
            if($found_filearea['intro'] || $found_filearea['content'] !== 1)
            {
            // Proceeding with moving leagacy file coz nothing found in either area
            $old_file = $fs->get_file_by_hash($legacy_file_pathnamehash);
            $old_url = '';

            // Call the function to build the file record
            $file_record = $this->build_file_record($old_file, $context, $activity_name, $activity_area);
//            $link_record = $this->build_link_record($old_url, $relative_path, $activity, $activity_area, $found_filearea, $positions_array);

            // Create the file in the new location
            $sf = $fs->create_file_from_storedfile($file_record, $old_file);
            echo "Copied file with pathnamehash: " . $legacy_file_pathnamehash . " to new location with new pathnamehash:" . $sf->get_pathnamehash() . "\n";
            // Update all the file links
            $this->update_file_link($relative_url, $activity, $file_record, $activity_name, $activity_area, "legacy", $positions_array);
            //$this->remove_legacy_file($old_file);
            }
            else
            {
                echo "Re-linking...\n";
                foreach($areas as $area)
                {
                    if($found_filearea[$area] === 1)
                    {
                        $this->update_file_link($relative_url, $activity, $file_record, $activity_name, $activity_area, $area, $positions_array);
                    }

                }
            }
        }
        else
        {
            echo "Missing legacy file, skipping...\n";
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
    private function update_file_link($old_path, $activity, $file, $activity_name, $activity_area, $found_filearea, $positions_array)
    {
        global $DB, $CFG;

        // Build new replacement string
        $new_path = '/pluginfile.php/' . $file->contextid . '/' . $file->component . '/' . $file->filearea . $file->filepath . $file->filename;
        $old_url = $CFG->wwwroot . $old_path;

        echo "New path: " . $new_path . "\n";

        /* Call function to build the link record to be saved to DB.
         * The build_link_record function is to be overriden by the relevant class.
         */
        $link_record = $this->build_link_record($old_url, $new_path, $activity, $activity_area, $found_filearea, $positions_array);

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
    public function build_link_record($old_url, $new_url, $label, $activity_area, $found_filearea, $positions_array)
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
    public function build_link_record($old_url, $new_url, $assign, $activity_area, $found_filearea, $positions_array)
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
    public function build_link_record($old_url, $new_url, $page, $activity_area, $found_filearea, $positions_array)
    {
        foreach($positions_array as $positions)
        {
//          // Check if the there's already a file plus updated link in intro area. If so, just copy link
//          if($found_filearea === 'intro' && $activity_area === 'content')
//          {
//              echo "Found file in another file area: " . $found_filearea . "\n";
//              $new_url = str_replace($activity_area, $found_filearea, $new_url);
//              echo "Updating URL to existing one: " . $new_url . "\n\n";
//          }

            $position = $positions[1] - strlen('/file.php/');
            echo "Position: $position \n";
            $updated_area = substr_replace($page->{$activity_area}, $new_url, $position, strlen($new_url));
        }

        echo $updated_area . "\n\n";

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
//     echo "Searching Labels for Legacy Files...\n";
//     $labelFiles = new MoveLegacyLabelFiles();
//     $sql_results = $labelFiles->search_activity('label', 'intro');
//     $labelFiles->search_activity_files($sql_results, 'label', 'intro');

//     echo "Searching Assignments for Legacy Files...\n";
//     $assignFiles = new MoveLegacyAssignmentFiles();
//     $sql_results = $assignFiles->search_activity('assign', 'intro');
//     $assignFiles->search_activity_files($sql_results, 'assign', 'intro');

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
