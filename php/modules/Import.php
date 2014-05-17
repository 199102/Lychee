<?php

###
# @name			Upload Module
# @author		Tobias Reich
# @copyright	2014 by Tobias Reich
###

if (!defined('LYCHEE')) exit('Error: Direct access is not allowed!');

class Import extends Module {

	static function photo($database, $plugins, $settings, $path, $albumID = 0, $description = '', $tags = '') {

		$info	= getimagesize($path);
		$size	= filesize($path);
		$photo	= new Photo($database, $plugins, $settings, null);

		$nameFile					= array(array());
		$nameFile[0]['name']		= $path;
		$nameFile[0]['type']		= $info['mime'];
		$nameFile[0]['tmp_name']	= $path;
		$nameFile[0]['error']		= 0;
		$nameFile[0]['size']		= $size;

		if (!$photo->add($nameFile, $albumID, $description, $tags)) return false;
		return true;

	}

	static function url($urls, $albumID = 0) {

		$error = false;

		# Parse
		$urls = str_replace(' ', '%20', $urls);
		$urls = explode(',', $urls);

		foreach ($urls as &$url) {

			if (@exif_imagetype($url)===false) {
				$error = true;
				continue;
			}

			$pathinfo	= pathinfo($url);
			$filename	= $pathinfo['filename'] . '.' . $pathinfo['extension'];
			$tmp_name	= LYCHEE_DATA . $filename;

			if (@copy($url, $tmp_name)===false) $error = true;

		}

		$import = Import::server($albumID, LYCHEE_DATA);

		if ($error===false&&$import===true) return true;
		else return false;

	}

	static function move($database, $path) {

		# Determine OS type and set move cmd (Windows untested!)
		$myos = substr(PHP_OS,0,3);
		$myos = strtoupper($myos);

		if ($myos==='WIN') $osmv = 'MOVE';
		else $osmv = 'mv';

		# Generate tmp dir name by hashing epoch time & random number
		$tmpdirname = md5(time() . rand());

		# Make temporary directory
		if (@mkdir(LYCHEE_DATA . $tmpdirname)===false) {
			Log::error($database, __METHOD__, __LINE__, 'Failed to create temporary directory');
			return false;
		}

		# Get list of files and move them to tmpdir
		$files = glob($path . '*');
		if (isset($files)) {

			foreach ($files as $file) {

				# Prevent index.html from being moved
				if (basename($file)==='index.html') continue;

				$out = '';
				$ret = '';
				$file = escapeshellarg($file);
				$cmd = $osmv . " $file " . LYCHEE_DATA . $tmpdirname;

				@exec($cmd, $out, $ret);

				if (isset($ret)&&($ret>0)) Log::error($database, __METHOD__, __LINE__, "Failed to move directory or file ($ret):" . $file);

			}

		}

		# If no files could be copied to the temp dir, remove
		$files = glob(LYCHEE_DATA . $tmpdirname . '/*');
		if (count($files)===0) {
			rmdir(LYCHEE_DATA . $tmpdirname);
			Log::error($database, __METHOD__, __LINE__, 'Import failed, because files could not be temporary moved to ' . LYCHEE_DATA);
			return false;
		}

		# Set new path
		$path = LYCHEE_DATA . $tmpdirname;

		return $path;

	}

	static function server($albumID = 0, $path, $useTemp = false) {

		global $database, $plugins, $settings;

		if (!isset($path)) $path = LYCHEE_UPLOADS_IMPORT;

		if ($useTemp===true) {
			$path = Import::move($database, $path);
			if ($path===false) {
				Log::error($database, __METHOD__, __LINE__, 'Failed to move import to temporary directory');
				return false;
			}
		}

		$error				= false;
		$contains['photos']	= false;
		$contains['albums']	= false;

		# Get all files
		$files = glob($path . '/*');

		foreach ($files as $file) {

			# It is possible to move a file because of directory permissions but
			# the file may still be unreadable by the user
			if (!is_readable($file)) {
				$error = true;
				Log::error($database, __METHOD__, __LINE__, 'Could not read file or directory: ' . $file);
				continue;
			}

			if (@exif_imagetype($file)!==false) {

				# Photo

				if (!Import::photo($database, $plugins, $settings, $file, $albumID)) {
					$error = true;
					Log::error($database, __METHOD__, __LINE__, 'Could not import file: ' . $file);
					continue;
				}
				$contains['photos'] = true;

			} else if (is_dir($file)) {

				# Folder

				$name		= mysqli_real_escape_string($database, basename($file));
				$album		= new Album($database, null, null, null);
				$newAlbumID	= $album->add('[Import] ' . $name);

				if ($newAlbumID===false) {
					$error = true;
					Log::error($database, __METHOD__, __LINE__, 'Could not create album in Lychee (' . $newAlbumID . ')');
					continue;
				}

				if (Import::server($newAlbumID, $file . '/', false)==='Warning: Folder empty or no readable files to process!') {
					$error = true;
					Log::error($database, __METHOD__, __LINE__, 'Could not import folder. Function returned error');
					continue;
				}

				$contains['albums'] = true;

			}

		}

		# Delete tmpdir if import was successful
		if ($error===false&&$useTemp===true&&file_exists(LYCHEE_DATA . $tmpdirname)) {
			if (@rmdir(LYCHEE_DATA . $tmpdirname)===false) Log::error($database, __METHOD__, __LINE__, 'Could not delete temp-folder (' . LYCHEE_DATA . $tmpdirname . ') after successful import');
		}

		if ($contains['photos']===false&&$contains['albums']===false)	return 'Warning: Folder empty or no readable files to process!';
		if ($contains['photos']===false&&$contains['albums']===true)	return 'Notice: Import only contains albums!';
		return true;

	}

}

?>
