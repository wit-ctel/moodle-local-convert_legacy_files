<?php

/* This scripts searches for legacy file links in labels and
 * relinks them back
 *
 * Written by Viettrung Luong 2015
 */

define('CLI_SCRIPT', 1);

require_once('../config.php');


class Convert_legacy_files 
{

    public function __construct()
    {
	// Default constructor
    }

    // run sql query to search for labels with file.php
    public function search_labels()
    {
	global $DB;

	//$results = $DB->get_records_sql('SELECT course, intro FROM {label} WHERE intro LIKE ?', array('%moodle.wit.ie/file.php%'));

	$results = $DB->get_records_sql('SELECT * FROM {label} WHERE intro LIKE ? AND course = ?', array('%moodle.wit.ie/file.php%', '2'));

	// Run query and return results
	return($results);
    }

    // Search content of labels and grab file
    public function search_files($data)
    {
	global $CFG;

	foreach($data as $key=>$value)
	{

	    $matches = [];
	    // Find where file.php pops up
	    $regexp = "\/file.php\/([^\"]*)\"";
	    preg_match_all("/$regexp/siU", $value->intro, $matches, PREG_SET_ORDER);
 
	    foreach($matches as $match) 
	    {
	    // Get the relative url
	    $relative_url = '/file.php/' . $match[1];
	    // Get filename
	    $filename = array_shift(explode('?', basename($relative_url)));
	    // echo "Filename: " . $filename . "\t : \t" . "Course id: " . $value->course ."\n";
    
	    // Call function to move the file to mod_label
	    $this->legacy_to_mod_label($match[1], $relative_url, $filename, $value);
	    }   
	}
    }

    // Move file to new location
    public function legacy_to_mod_label($relativepath, $relative_url, $filename, $label)
    {

	// Build the pathnamehash to locate the legacy file
	$args = explode('/', $relativepath);
	$rpath = array_slice($args, 1);
	$final_path = implode('/', $rpath);
	// echo $final_path . "\n";

	// Retrieve the file and store it temporarily
	$context = context_course::instance($label->course);
	$fs = get_file_storage();
	$fullpath = "/$context->id/course/legacy/0/" . $final_path;
	$old_file = $fs->get_file_by_hash(sha1($fullpath));
	// echo $fullpath . "\n";
	// echo sha1($fullpath) . "\n";
 

	//Create file record for new file
	$file_record = new stdClass();
	$file_record->userid = 2;  // Admin account
	$file_record->contextid = $context->id;
	$file_record->component = 'mod_label';
	$file_record->filearea = 'intro';
	$file_record->itemid = 0;
	$file_record->filepath = '/';
	$file_record->licence = 'cc';	// Creative Commons
	$file_record->filename = $filename;
	$filee_record->source = '';
	$file_record->timecreated = time();
	$file_record->timemodified = time();

	// check if file already in the proper location, if so, just update the url.
	if($fs->file_exists($file_record->contextid, $file_record->component, 
			    $file_record->filearea, $file_record->itemid, 
			    $file_record->filepath, $file_record->filename))
	{
	    echo "File exists, just replacing the urls\n";
	    $this->update_file_link($relative_url, $label, $file_record);
	    $this->remove_legacy_file($old_file);
	}
	else
	{
	    $sf = $fs->create_file_from_storedfile($file_record, $old_file);
	    echo "Copied file with pathnamehash: " . sha1($fullpath) . " to new location with new pathnamehas:" . $file_record->pathnamehash . "\n";
	    $this->update_file_link($relative_url, $label, $file_record);
	    $this->remove_legacy_file($old_file);
	}
 
    }

    // Update link
    public function update_file_link($old_path, $label, $file)
    {
	global $DB, $CFG;

	// $new_url = moodle_url::make_file_url('/pluginfile.php', array($file->contextid, $file->component, $file->filearea,
	//				    $file->itemid, $file->filepath, $file->filename));

	$new_url = $CFG->wwwroot . '/pluginfile.php/' . $file->contextid . '/' . $file->component . '/' . $file->filearea . '/' . $file->filename;
	$old_url = "http://moodle.wit.ie" . $old_path;
	$updated_label = str_replace($old_url, $new_url, $label->intro) ;
	// echo $updated_label . "\n";
	$label->intro = $updated_label;

	$record = new stdClass();
	$record->id = $label->id;
	$record->course = $label->course;
	$record->name = $label->name;
	$record->intro = $updated_label;
	$record->introformat = $label->introformat;
	$record->timemodified = time();

	//print_r($record);
	$DB->update_record('label', $record, false); 

    }

    // Delete Legacy file
    public function remove_legacy_file($old_file)
    {
	// Double Check file is still there
	if($old_file)
	{
	    $old_file->delete();
	    echo "Removed legacy file \n";
	}
    }

    public function start()
    {
	$result =$this-> search_labels();
	$this->search_files($result);
    }
} // End Class

$start_conversion = new Convert_legacy_files();
$start_conversion->start();
?>

