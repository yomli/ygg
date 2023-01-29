<?php
// Ygg - File explorer for git projects hosted on your own server.
// Copyright (c) 2023 yomli https://dev.yom.li/
// This file may be used and distributed under the terms of the public license.

// =============================
// Configuration
// =============================

$config = [
	'title' => '{{dir}}',
	'description' => 'File explorer for git projects hosted on your own server. - https://dev.yom.li/projects/ygg',
	'nav' => [
		// Browseable subdirectories
		// The first existing subdir will be used as index
		'browseable' => [
			'source' => 'master',
			'releases' => 'releases'
		],
		// Navigation links
		// Can be subdirectories containing their own index
		'links' => [
			'docs' => 'docs',
			'Fork on Github' => 'https://github.com/yomli/ygg'
		]
	],
	'robots' => 'noindex, nofollow', // Avoid robots by default (it's mainly duplicate content anyway)
	'allow_download' => true,
	'show_footer' => true, // Display the "Powered by" footer
	'override_css' => '', // Path to a css file to apply
	'override_js' => '', // Path to a js file to apply
	'custom_favicon' => false, // Set to true if you have a favicon.ico in your root folder
	'parsedown' => '', // Path to the Parsedown.php parser (for readme parsing)
	'syntax_highlighter' => [
		'css' => '', // Path to the css of the syntax highlighter you use
		'js' => '', // Path to the js of the syntax highlighter you use
		'strip_numbers' => false // Do not show the numbers (ie, if using a Prism.js' plugin)
	]
];

// =============================
// Global vars
// =============================

// We use getcwd and not __DIR__ so the file can be included
// and paths will NOT be relative to this file
$_root = getcwd() . DIRECTORY_SEPARATOR;
$_webroot = dirname($_SERVER['PHP_SELF']) . '/';
$_path = urldecode(trim(str_replace($_webroot, '', $_SERVER['REQUEST_URI']), '/'));
$_index = trim(reset($config['nav']['browseable']), '/');
$_parent = reset(explode('/', $_path));

if (!$_index) {
	$_index = $_webroot;
}

// Prevents from viewing a file in root
if (!is_dir($_root . $_parent)) {
	$_parent = '';
}

// =============================
// Init
// =============================

ob_start();

// Merge config with the content of config.json
if (is_file($_root . 'config.json')) {
	$json = json_decode(file_get_contents('config.json'), 1);
	if ($json !== false) {
		$config = array_merge($config, $json);
	}
}

// Replace {{path}} and {{dir}} in title and description
$config['title'] = str_replace(array('{{path}}', '{{dir}}'), array($_webroot, basename($_root)), $config['title']);
$config['description'] = str_replace(array('{{path}}', '{{dir}}'), array($_webroot, basename($_root)), $config['description']);
// Replace URL in description by links
$config['description'] = preg_replace('#(\S+://\S+)#', '<a href="$1">$1</a>', $config['description']);

// Get alert
$alert = file_get_contents('alert.txt');


// .htaccess
if (!file_exists('./.htaccess')) {
	$htaccess = '# Prevent file browsing' . PHP_EOL .
		'Options -Indexes -MultiViews' . PHP_EOL .
		'# Turn on rewriting' . PHP_EOL .
		'RewriteEngine On' . PHP_EOL .
		'# Rewrite ALL queries to the index file so subfolders are parsed too' . PHP_EOL;
	foreach ($config['nav']['browseable'] as $dir) {
		$htaccess .= 'RewriteRule ^' . $dir . '/(.*)$ index.php [NC,QSA]' . PHP_EOL;
	}
	file_put_contents('./.htaccess', $htaccess);
}

// Disable src/.htaccess just in case
if (file_exists($_index . '/.htaccess')) {
	rename($_index . '/.htaccess', $_index . '/htaccess');
}

// =============================
// Queries
// =============================

if (isset($_GET['zip'])) {
	// Download zip
	$files = totalFiles($_root . $_index);
	$zip_name = basename($_root) . '.zip';
	if (archiveThis($files['files'], $zip_name)) {
		ob_clean();
		downloadFile($zip_name, true);
	}
	exit;
} elseif (isset($_GET['s'])) {
	// Search
	$search_items = array();
	if (!empty($_GET['s'])) {
		$term = strtolower($_GET['s']);
		$files = totalFiles($_root . $_index);
		$search_items = array('term' => $term, 'files' => fuzzySearch($term, $files['files']));
	}
} elseif (isset($_GET['d']) || isset($_GET['raw'])) {
	// File download and raw
	ob_end_clean();
	$file = str_replace('?raw', '', $_root . $_path);
	$file = str_replace('?d', '', $file);
	if (empty($_parent) || !file_exists($file)) {
		get404();
	}
	if (isset($_GET['raw'])) {
		flush();
		$file_array = file($file);
		echo '<pre>';
		echo htmlspecialchars(implode($file_array));
		echo '</pre>';
	} elseif (isset($_GET['d'])) {
		downloadFile($file);
	}
	exit;
} elseif (isset($_GET['feed'])) {
	// RSS feed
	// If no feed.rss or
	// last-modified feed.rss < last-modified file,
	// generate the feed and serve it
	ob_clean();
	$files = totalFiles($_root . $_index);
	$last_update = $files['mtime'];
	if (!is_file($_root . 'feed.rss') || filemtime($_root . 'feed.rss') < $last_update) {
		$url = (!empty($_SERVER['HTTPS'])) ? 'https' : 'http';
		$url .= '://' . $_SERVER['HTTP_HOST'] . $_webroot;

		$xml = '<?xml version="1.0" encoding="UTF-8"?>';
		$xml .= '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">';
		$xml .= '<channel>';
		$xml .= '<title>' . $config['title'] . '</title>';
		$xml .= '<description>' . $config['description'] . '</description>';
		$xml .= '<link>' . $url . '</link>';
		$xml .= '<atom:link href="' . $url . '?feed" rel="self" type="application/rss+xml" />';
		$xml .= '<pubDate>' . date(DATE_RSS) . '</pubDate>';
		$xml .= '<lastBuildDate>' . date(DATE_RSS, filemtime('feed.rss')) . '</lastBuildDate>';
		$xml .= '<item>';
		$xml .= '<title>' . $config['title'] . ' got an update</title>';
		$xml .= '<description>' . $config['title'] . ' has been updated! Check this out: ' . $url . '</description>';
		$xml .= '<pubDate>' . date(DATE_RSS, $last_update) . '</pubDate>';
		$xml .= '<link>' . $url . '</link>';
		$xml .= '<guid isPermaLink="true">' . $url . '</guid>';
		$xml .= '</item>';
		$xml .= '</channel>';
		$xml .= '</rss>';

		file_put_contents($_root . 'feed.rss', $xml);
	}
	header('Content-Type: application/rss+xml; charset=utf-8');
	readfile($_root . 'feed.rss');
	exit;
}

// =============================
// Functions
// =============================

/**
 * Iterate through an array of folders,
 * listing all files and subdirectories.
 *
 * @param  array  $folders   Folders to list
 * @param  array  $filter    Files to filter
 * @return array
 * 'files' = array,
 * 'size' = total size,
 * 'number' = number of files
 * 'extensions' = all the files' extensions sorted desc
 * 'mtime' = modified time of the freshest file
 */
function totalFiles($folder, $filter = array('.', '..', '.git')) {
	$source = array();
	$size = 0;
	$ext = array();
	$mtime = 1;
	if (is_dir($folder)) {
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder), \RecursiveIteratorIterator::SELF_FIRST);
		foreach ($files as $file) {
			$filepath = str_replace($folder . DIRECTORY_SEPARATOR, '', $file->getPathname());
			$first_dir = explode(DIRECTORY_SEPARATOR, $filepath);
			if ($file->getFilename() == '.' || $file->getFilename() == '..' || $file->isLink() || in_array($file->getFilename(), $filter) || in_array($first_dir[0], $filter)) {
					continue;
			}
			if (!in_array($filepath, $source)) {
				$source[] = $filepath;
				$size = $size + $file->getSize();
				$pathinfo = pathinfo($file->getFilename(), PATHINFO_EXTENSION);
				if (!empty($pathinfo)) {
					$ext[$pathinfo] = $ext[$pathinfo] + $file->getSize();
				}
				if ($file->getMTime() > $mtime) {
					$mtime = $file->getMTime();
				}
			}
		}
	}
	arsort($ext);
	return array('files' => $source, 'size' => $size, 'number' => count($source), 'extensions' => $ext, 'mtime' => $mtime);
}

/** GLOBALS
 * Archive multiple folders recursively.
 * Note that the date and extension will be added
 * to the $archive name.
 *
 * @param  array   $files    Files to archive
 * @param  string  $archive  Name of the archive
 * @return bool
 */
function archiveThis($files, $archive = 'master.zip') {
	global $_index;
	if (extension_loaded('zip')) {
		// If the zip file already exists, do not recreate it
		if (count($files) && !file_exists($archive)) {
			$zip = new \ZipArchive();
			if (!$zip->open($archive, \ZIPARCHIVE::CREATE)) {
				return false;
			}
			foreach ($files as $file) {
				$file = $_index . DIRECTORY_SEPARATOR . $file;
				if (is_dir($file)) {
					$zip->addEmptyDir($file);
				} else {
					$zip->addFile($file);
				}
			}
			$zip->close();
			// Make sure the file exists
			return file_exists($archive);
		} else {
			return true;
		}
	} else {
		return false;
	}
}

/**
 * Downloads a file
 * Pretty self-explanatory.
 */
function downloadFile($file, $delete_after = false) {
	if (!file_exists($file)) {
		get404();
		exit;
	}
	// Determine the mimetype
	$finfo = finfo_open(FILEINFO_MIME);
	if (!$finfo) {
		return false;
	}
	$type = finfo_file($finfo, $file);
	$type = reset(explode(';', $type));
	finfo_close($finfo);

	ob_clean();
	header('Content-Type:' . $type);
	header('Content-Disposition: attachment; filename="' . basename($file) . '";');
	header('Content-Transfer-Encoding: binary');
	header('Expires: 0');
	header('Cache-Control: must-revalidate');
	header('Pragma: public');
	header('Content-Length: ' . filesize($file));
	flush();
	readfile($file);

	if ($delete_after === true) {
		unlink($file);
	}

	exit;
}

/**
 * Fuzzy search
 *
 * Use Levenshtein's algo on Metaphone
 * to get a good cost.
 *
 * @return $search_items array [string found] => cost
 * sorted by cost
 */
function fuzzySearch($needle, $haystack) {
	$search_items = array();
	$metaphone1 = metaphone($needle);
	foreach ($haystack as $stem) {
		$metaphone2 = metaphone(strtolower(basename($stem)), strlen($metaphone1));
		$cost = levenshtein($metaphone1, $metaphone2);
		if ($cost < 1) {
			$search_items[$stem] = $cost;
		}
	}
	asort($search_items);
	return $search_items;
}

/**
 * Search $files in $dir directory
 * for a file named $name when you do not
 * know its extension.
 *
 * Yes, we could search the dir stripping
 * extensions, but we have a $files array
 * already set in memory, so let's use it.
 *
 * $files must be an array like this:
 *    $files[lowercase_name]['name']
 *
 * @param string $name  The search
 * @param array  $files Files to search
 * @param array  $ext   Possible extensions
 * @param string $dir   In which directory?
 */
function searchFile($name, $files, $ext = array(''), $dir = '') {
	$name = strtolower($name);
	$result = '';
	while (count($ext) > 0) {
		$search = $name . array_shift($ext);
		if (isset($files[$search])) {
			$file = trim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $files[$search]['name'];
			if (file_exists($file)) {
				$result = $file;
			}
		}
	}
	return $result;
}

/**
 * Sort by name
 *
 * With directories first, called by
 * a *sort() function.
 */
function sortByName(array $a, array $b) {
	return ($a["isdir"] == $b["isdir"] ? $a["name"] > $b["name"] : $a["isdir"] < $b["isdir"]);
}

/**
 * From https://github.com/lorenzos/Minixed
 * 37.6 MB is better than 39487001
 *
 * @param string $val   Value to humanize
 * @param int    $round Precision to round the value
 */
function humanizeFilesize($val, $round = 0) {
	$unit = array("","K","M","G","T","P","E","Z","Y");
	do {
		$val /= 1024;
		array_shift($unit);
	} while ($val >= 1000);
	return sprintf("%.".intval($round)."f", $val) . " " . array_shift($unit) . "B";
}

/**
 * Return a more friendly date like
 * 'a minute ago', which is better when
 * reading projects.
 * Not the most exquisite code,
 * but the fastest…
 *
 * @param string $date Unix timestamp
 */
function friendlyDate($date) {
	$from = new DateTime();
	$now = new DateTime('now');
	$from->setTimestamp($date);
	$diff = $now->diff($from, true);

	if ($diff->y > 1) {
		return $diff->y . ' years ago';
	} elseif ($diff->m > 12) {
		return '1 year ago';
	} elseif ($diff->m > 1) {
		return $diff->m . ' months ago';
	} elseif ($diff->m == 1) {
		return '1 month ago';
	} elseif ($diff->days > 2) {
		return $diff->days . ' days ago';
	} elseif ($diff->days == 2) {
		return 'Yesterday';
	} elseif ($diff->h > 1) {
		return $diff->h . ' hours ago';
	} elseif ($diff->i >= 1) {
		return $diff->i . ' min ago';
	} else {
		return 'Just now';
	}
}

/** GLOBALS
 * Return the proper link of an item
 */
function getLink($item) {
	global $_index, $_path;

	// We are in home
	if (empty($_path)) {
		return $_index . '/' . $item;
	} else {
		return $item;
	}
}

/**
 * Prints a 404 error page
 */
function get404() {
	ob_end_clean();
	header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found', true, 404);
	die('
		<HTML><HEAD>
		<TITLE>404 Not Found</TITLE>
		</HEAD><BODY>
		<H1>Not Found</H1>
		<P>The requested URL ' . $_SERVER['REQUEST_URI'] . ' was not found on this
			server.</P>
		</BODY></HTML>
	');
}

/**
 * Get full URL of a given path
 */
function getURL($path) {
	global $_webroot;
	// Test if contains host or begins with '/'
	if (strpos($path, './') == false && strpos($path, '../') == false) {
		return $path;
	}
	return $_webroot . $path;
}

/** TEMPLATE
 * Show the source of a file or downloads
 * it depending its mimetype
 *
 * Yes, I know there is a show_source() alias
 * built-in, but it only works for PHP files…
 *
 * @param string $file Path of the file
 */
function showSource($file) {
	if (!file_exists($file)) {
		get404();
	}
	// Determine the mimetype
	$finfo = finfo_open(FILEINFO_MIME);
	if (!$finfo) {
		return 'Can\'t determine the mimetype.';
	}
	$type = finfo_file($finfo, $file);
	finfo_close($finfo);
	if (substr($type, 0, 4) == 'text') {
		$file_array = file($file);
		$html = '<code class="source-numbers no-select">';
		//$html.= implode('<a id="L1" href="L1"></a><br><a ', range(1, count($file_array))) . '</code>';
		foreach (range(1, count($file_array)) as  $nb) {
			$html .= '<a id="L'.$nb.'" href="#L'.$nb.'">'.$nb.'</a><br>';
		}
		$html .= '</code>';
		$html .= '<pre class="source-pre">';
		$html .= '<code class="language-' . pathinfo($file, PATHINFO_EXTENSION) . ' microlight">';
		$html .= htmlspecialchars(implode($file_array));
		$html .= '</code></pre>';
		return $html;
	} else {
		downloadFile($file);
		exit;
	}
}

/** GLOBALS TEMPLATE
 * Show a colorful language bar like
 * the one you could find on GitHub or GitLab.
 */
function showStats() {
	global $files;
	$width = array();
	foreach ($files['extensions'] as $ext => $number) {
		$ext = strtoupper($ext);
		$width[$ext] = ($number / array_sum($files['extensions'])) * 100;
	}
	$html = '<details><summary class="extensions-bar" style="display:flex;">';
	// Building summary extension bar
	foreach ($width as $ext => $percent) {
		$html .= '<span class="extensions-bar-color" aria-label="' . $percent ;
		$html .= '%" style="width:' . $percent . '%; background-color:#' . substr(crc32($ext), 2, 3);
		$html .= ';" title="' . $ext . ' (' . round($percent, 1) . '%)">';
		$html .= $ext . '</span>';
	}
	$html .= '</summary><ol class="extensions-list">';
	// Building details
	foreach ($width as $ext => $percent) {
		$html .= '<li><strong>' . $ext . '</strong> ' . round($percent, 1) . '%</li>';
	}
	$html .= '</ol></details>';
	return $html;
}

/** GLOBALS TEMPLATE
 * Show the README file in text or html
 * depending on $config['parsedown']
 */
function showREADME($file) {
	global $config;
	$readme = file_get_contents($file);
	if (empty($config['parsedown'])) {
		return '<pre style="white-space: pre-wrap;">' . htmlspecialchars($readme) . '</pre>';
	} else {
		include $config['parsedown'];
		$markdown = new Parsedown();
		return $markdown->text($readme);
	}
}

/** GLOBALS TEMPLATE
 * Show the nice breadcrumb you expect
 * to find on top of a file explorer
 */
function showBreadcrumb() {
	global $_root, $_webroot, $_path, $_parent;
	$crumbs = explode('/', trim($_path, '/'));
	$html = '<nav class="breadcrumb" role="navigation" label="Breadcrumb">';
	$html .= '<a href="' . $_webroot . '" class="breadcrumb-item">' . basename($_root) . '</a>';
	if ($crumbs[0] == $_parent) {
		$html .= ' / <a href="' . $_webroot . $_parent . '" class="breadcrumb-item">' . $_parent . '</a>';
	}
	$path = $_webroot . array_shift($crumbs);
	while (count($crumbs) > 0) {
		$crumb = array_shift($crumbs);
		$path .= '/' . $crumb;
		$html .= ' / <a href="' . $path . '" class="breadcrumb-item">' . $crumb . '</a>';
	}
	$html .= (is_dir($_path)) ? ' /' : '';
	$html .= '</nav>';
	return $html;
}

// =============================
// Core
// =============================

// Get the navigation items
// Note: if nav items are not existing dirs or url
// they will not be displayed
$nav_items = array();
$merge = array_merge($config['nav']['browseable'], $config['nav']['links']);
foreach ($merge as $name => $path) {
	if (is_dir($path) || preg_match('#(\S+://\S+)#', $path) === 1) {
		$path = trim($path, '/');
		$is_active = false;

		if ( ($path == $_parent && !empty($_parent)) || ($path == $_index && empty($_parent)) ){
			$is_active = true;
		}
		if ($path == $_index) {
			$path = $_webroot;
		} elseif (is_dir($path)) {
			$path = $_webroot . $path;
		}
		$nav_items[] = array('name' => ucfirst($name), 'path' => $path, 'isactive' => $is_active);
	}
}

// Get the files
// Use $filter to filter some unwanted directories
// $text_extensions list all known text extensions
// of license and readme files
$filter = ['.git', '.gitignore'];
$text_extensions = array('', '.txt', '.md');
$files_items = array();

if (empty($_path)) {
	$query_path = $_index;
} else {
	$query_path = $_path;
}

if (is_dir($query_path)) {
	// Get the files items
	foreach (new FilesystemIterator($_root . $query_path) as $item) {
		if (in_array($item->getFilename(), $filter)) {
			continue;
		}
		$files_items[strtolower($item->getFilename())] = array('name' => $item->getFilename(), 'isdir' => $item->isDir(), 'size' => $item->getSize(), 'mtime' => $item->getMTime());
	}
	uasort($files_items, 'sortByName');

	// Get README
	$readme_file = searchFile('readme', $files_items, $text_extensions, $query_path);
}

// Home mode navigation
// Get license and some stats
if (empty($_path)) {
	// Get license
	$license_file = searchFile('license', $files_items, $text_extensions, $_index);

	// Get stats
	$files = totalFiles($_root . $_index);
	$stats['total_size'] = humanizeFilesize($files['size'], 1);
	$stats['number'] = $files['number'];
	$stats['number'] .= ($files['number'] > 1) ? ' files' : ' file';
}

// =============================
// Template
// =============================
?>

<!DOCTYPE HTML>
<html lang="en-US">
<head>

	<meta charset="UTF-8">
	<meta name="robots" content="<?= htmlentities($config['robots']) ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">

	<title><?= htmlentities($config['title']) ?></title>
	<meta name="description" content="<?= htmlentities($config['description']) ?>" />

	<link rel="alternate" type="application/rss+xml" title="<?= htmlentities($config['title']) ?> activity" href="?feed">

	<?php if (!$config['custom_favicon']): ?>
		<link rel="icon" type="image/png" sizes="32x32" href="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAMAAABEpIrGAAAABGdBTUEAALGPC/xhBQAAAAFzUkdCAK7OHOkAAAByUExURUxpcXl5IXeiKY/BPY/CO3WeK2WIIq9jIrBjIo/CPHehKVp4HnWcKo/CPYxqJFt4Hq9jIpNPGpJQGpJQGZNQGZByJJmhMppWHIFbG2eKJXeiKZDCPWuRKJJRGo/CPa9jInehKZFPHH+sMJzLSp1WJIq6OcFAyc0AAAAedFJOUwAccT00/v2Ed3Xn+kzjDs3KaLVI48C6N3eotbGylpdCPygAAAAJcEhZcwAAAOwAAADsAXkocb0AAAE/SURBVDjLjZKLtoIgEEXRlIcaZpb2RND8/1+MGSzQq3c1q9XIOXtGBiHkx4jiOAqWRVws/EzrzBOx1jqeAaCk14KI/V6Q6EJTywe2vFmB5jS/KhtFTmkORBHU970Grbf+MAy9plT3QQ8EnG19+9OwCgB50/oLwB8us/kgXUd2aooEVsvoOk4SIZqmEQnhKwADAhMDn81MnN4TB/BRmwLfvXPFSNg8aXjKZU5Hpe4J9og41id3pUaal3D6ZTqm1O0din09tUZpgYd9TkEQMAqE3ZSaxMdnB67DFwg1AtOfz0LAc2deCIQahjFVLUNA1pUxwUEYiNPx6V7xPJ5QCC9UfUHRui/TIXypo+Vhy7ZyQNXKrav7mWIruAP4Pz5r2WGTmD4y3+rh/fUe/mPzvxfGDcB8Yqt3ciXNtsBX0i/xBuqkKvER90OkAAAAV3pUWHRSYXcgcHJvZmlsZSB0eXBlIGlwdGMAAHic4/IMCHFWKCjKT8vMSeVSAAMjCy5jCxMjE0uTFAMTIESANMNkAyOzVCDL2NTIxMzEHMQHy4BIoEouAOoXEXTyQjWVAAAAAElFTkSuQmCC">
	<?php endif; ?>

	<style type="text/css">
		/* Minimal CSS reset
		Inspired by
		https://alligator.io/css/minimal-css-reset/ */
		*,  *::before, *::after {
			box-sizing: inherit;
		}
		html {
			height: 100%;
			box-sizing: border-box;
			font-size: 16px;
		}
		body, h1, h2, h3, h4, h5, h6, p, pre, ol, ul {
			margin: 0;
			padding: 0;
			font-weight: normal;
		}
		ol, ul {
			list-style: none;
		}
		/* Opiniated Rebase */
		body {
			min-height: 100%;
			position: relative;
			font-family: -apple-system, system-ui, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", Helvetica, Arial, "Noto Sans", sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol", "Noto Color Emoji";
			font-size: 1rem;
			font-weight: 400;
			line-height: 1.5;
			color: #2e2e2e;
			background: #f7f7f7;
		}
		h1, h2, h3, h4, h5, h6 {
			margin-bottom: 0.5rem;
		}
		p, pre, table, nav, details {
			margin-bottom: 1rem;
		}
		a {
			color: steelblue;
			text-decoration: none;
		}
		img {
			max-width: 100%;
			height: auto;
			vertical-align: middle;
			border-style: none;
		}
		summary {
			display: list-item;
			cursor: pointer;
		}
		details summary::-webkit-details-marker {
  			display: none;
		}
		pre, code {
			font-family: SFMono-Regular, Menlo, "DejaVu Sans Mono", Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
			font-size: 87.5%;
			overflow-x: auto;
		}
		table {
			width: 100%;
		}
		td a {
			display: block;
			height: 100%;
		}
		input {
			-moz-appearance: none;
			-webkit-appearance: none;
			appearance: none;
			margin: 0;
			font-family: inherit;
			font-size: inherit;
			font-style: inherit;
			line-height: inherit;
			border: none;
		}
		.no-select {
			/* As of 08/2019, we still have to do this */
			-webkit-touch-callout: none; /* iOS Safari */
			-webkit-user-select: none; /* Safari */
			-khtml-user-select: none; /* Konqueror HTML */
			-moz-user-select: none; /* Firefox */
			-ms-user-select: none; /* Internet Explorer/Edge */
			user-select: none; /* Non-prefixed version, currently
                                  supported by Chrome and Opera */
		}
		/* Base */
		.container {
			margin: 0 auto;
			padding: 1em;
		}
		@media (min-width: 64rem) {
			.container {
				max-width: 62rem;
			}
		}
		nav ul,	nav ol {
			display: flex;
			justify-content: space-around;
			align-items: center;
			flex-wrap: wrap;
		}
		nav a, nav a:hover, nav a:focus {
			color: inherit;
		}
		.button {
			padding: 0.375em 0.75em;
			border-radius: 3px;
			box-shadow: 0 1px 2px rgba(0,0,0,.3);
			color: currentColor;
			cursor: pointer;
		}
		.button:hover, .button:focus {
			background: rgba(150,150,150,.1);
		}
		.button.primary {
			background: #28a745;
			color: white !important;
		}
		.button.primary:hover, .button.primary:focus {
			background: #269f42;
		}
		article {
			border: 1px solid rgba(128,128,128,.1);
			border-radius: 3px;
			margin-bottom: 3rem;
		}
		/* Main nav */
		.main-nav-list {
			justify-content: flex-start;
			margin-bottom: 2.5rem;
		}
		.main-nav-list li {
			opacity: 0.6;
			padding: 0;
		}
		.main-nav-list li a {
			display: block;
			padding: 0.5rem 1rem;
		}
		.main-nav-list li:hover,
		.main-nav-list li:focus {
			opacity: 1;
		}
		.main-nav-list .active {
			box-shadow: 0 3px 0 steelblue;
			opacity: 1;
		}
		/* Index nav */
		.index-nav {
			font-size: 14px;
		}
		.index-nav li {
			margin-bottom: 1rem;
		}
		@media (min-width: 25rem) {
			.index-nav li {
				margin-bottom: 0;
			}
		}
		/* Extensions bar */
		.extensions-bar {
			outline: none;
		}
		.extensions-bar-color {
			height: 0.5rem;
			font-size: 0px;
		}
		.extensions-bar-color:first-child {
			border-radius: 3px 0 0 3px;
		}
		.extensions-bar-color:last-child {
			border-radius: 0 3px 3px 0;
		}
		.extensions-list {
			display: flex;
			justify-content: space-around;
			flex-wrap: wrap;
			color: gray;
			padding: 1em;
		}
		.extensions-list strong {
			font-weight: normal;
			color: inherit;
		}
		/* Alert box */
		.alert {
			background: rgba(150,150,150,.1);
			border: 1px solid rgba(128,128,128,.1);
			border-radius: 3px;
			padding: 1rem;
			font-size: 14px;
		}
		/* Breadcrumb */
		.breadcrumb {
			color: gray;
			margin-bottom: 1.5rem;
		}
		.breadcrumb-item {
			color: steelblue;
		}
		.breadcrumb-item:hover,
		.breadcrumb-item:focus,
		.breadcrumb-item:last-child {
			color: inherit;
		}
		/* File explorer */
		.file-explorer {
			border: 1px solid rgba(128,128,128,.1);
			border-radius: 3px;
			border-collapse: collapse;
			color: gray;
		}
		.file-explorer thead {
			background: rgba(150,150,150,.1);
			font-size: 14px;
			border-bottom: 1px solid rgba(128,128,128,.1);
		}
		.file-explorer,
		.file-explorer thead,
		.file-explorer tbody {
			display: block;
		}
		.file-explorer tbody tr:hover {
			background: rgba(150,150,150,.1);
		}
		.file-explorer tr {
			display: grid;
			grid-template-columns: 50% auto auto;
			grid-template-rows: auto;
			grid-template-areas: "td td td";
			padding: 0.5rem;
			border-bottom: 1px solid rgba(128,128,128,.1);
		}
		@media (min-width: 42rem) {
			.file-explorer tr {
				grid-template-columns: 75% auto auto;
			}
		}
		.file-explorer tr:last-child {
			border-bottom: none;
		}
		.file-explorer td:last-child {
			text-align: right;
		}
		/* Articles */
		.readme-header,
		.source-header {
			background: rgba(150,150,150,.1);
			border-bottom: 1px solid rgba(128,128,128,.1);
			padding: 0.5rem;
		}
		.readme-header h1,
		.source-header h1 {
			font-size: 14px;
			margin: 0;
		}
		/* Readme */
		.readme-content {
			padding: 3rem;
		}
		/* Source */
		.source-content {
			display: flex;
			gap: 0 0.5rem;
		}
		.source-numbers {
			text-align: right;
			color: gray;
			padding: 0 0.5rem;
			border-right: 1px solid rgba(128,128,128,.1);
		}
		.source-numbers a {
			color: inherit;
		}
		.source-header {
			display: flex;
			justify-content: space-between;
			font-size: 14px;
		}
		.source-header .source-size {
			font-size: 12px;
		}
		.source-header .source-size::before {
			content:' | ';
			color: gray;
		}
		.source-actions {
			display: flex;
		}
		.source-actions li {
			margin-left: 1rem;
		}
		.source-pre {
			width: 100%;
		}
		/* Force some things because
		highlighters like to mess with this */
		pre[class*="language-"] {
			padding: 0 1rem  0.5rem 1rem !important;
			margin: 0 !important;
			font-family: SFMono-Regular, Menlo, "DejaVu Sans Mono", Monaco, Consolas, "Liberation Mono", "Courier New", monospace !important;
			font-size: 87.5% !important;
		}
		/* Search */
		.search {
			margin-bottom: 3rem;
		}
		.search-form {
			margin-bottom: 1rem;
			display: flex;
			align-items: center;
			color: gray;
		}
		.search-input {
			width: 100%;
			border-radius: 3px;
			flex: 1 1 auto;
			margin: 0 1rem 0 0.5rem;
			padding: 0.375rem;
		}
		.search-label {
			flex: none;
		}

		/* Footer */
		footer {
			position: absolute;
			bottom: 0;
			left: 0;
			right: 0;
			margin-top: 2rem;
			text-align: center;
		}

		/* Icons */
		/* From https://iconsvg.xyz/ */
		/* and https://icomoon.io/ */
		/* and https://octicons.github.com/ */
		/* Converted using https://yoksel.github.io/url-encoder/ */
		[class^='icon-']::before {
			display: inline-block;
			height: 1em;
			width: 1em;
			margin-right: 0.5em;
		}
		.icon-directory::before {
			/*content: '📁';*/
			content: '';
			background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='1em' height='1em' viewBox='0 0 24 20' fill='steelblue' stroke='none' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z'%3E%3C/path%3E%3C/svg%3E");
		}
		.icon-file::before {
			/*content:'📄';*/
			content:'';
			background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='1em' height='1em' viewBox='0 0 24 22' fill='none' stroke='gray' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M14 2H6a2 2 0 0 0-2 2v16c0 1.1.9 2 2 2h12a2 2 0 0 0 2-2V8l-6-6z'/%3E%3Cpath d='M14 3v5h5M16 13H8M16 17H8M10 9H8'/%3E%3C/svg%3E");
		}
		.icon-single-file::before {
			/*content:'📄';*/
			content:'';
			background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='1em' height='1em' viewBox='0 0 24 26' fill='none' stroke='gray' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M14 2H6a2 2 0 0 0-2 2v16c0 1.1.9 2 2 2h12a2 2 0 0 0 2-2V8l-6-6z'/%3E%3Cpath d='M14 3v5h5M16 13H8M16 17H8M10 9H8'/%3E%3C/svg%3E");
		}
		.icon-license::before {
			/*content:'⚖️';*/
			content:'';
			background-image: url("data:image/svg+xml,%3Csvg version='1.1' xmlns='http://www.w3.org/2000/svg' width='1em' height='1em' viewBox='0 0 64 48'%3E%3Cpath d='M47.352 42.926l-25.717-23.412 1.197-1.2c0.979-0.982 1.509-2.251 1.59-3.544 0.047-0.021 0.094-0.043 0.139-0.068l4.828-3.019c0.653-0.768 0.605-1.981-0.107-2.695l-8.396-8.419c-0.712-0.714-1.922-0.762-2.688-0.107l-3.011 4.841c-0.025 0.045-0.046 0.092-0.068 0.139-1.289 0.081-2.555 0.612-3.534 1.594l-4.567 4.58c-0.98 0.982-1.509 2.251-1.59 3.544-0.047 0.021-0.094 0.043-0.139 0.068l-4.827 3.019c-0.654 0.768-0.605 1.981 0.107 2.695l8.396 8.419c0.712 0.714 1.922 0.762 2.688 0.107l3.011-4.841c0.025-0.045 0.046-0.092 0.068-0.139 1.289-0.081 2.555-0.612 3.534-1.594l1.326-1.33 23.349 25.787c0.677 0.747 1.72 0.868 2.319 0.268l2.36-2.367c0.598-0.6 0.478-1.647-0.267-2.326z'%3E%3C/path%3E%3C/svg%3E");
		}
		.icon-find::before {
			/*content:'🔎';*/
			content:'';
			background-image: url("data:image/svg+xml,%3Csvg version='1.1' xmlns='http://www.w3.org/2000/svg' width='1em' height='1em' viewBox='0 0 64 48'%3E%3Cpath d='M46.512 40.847l-11.37-9.67c-1.175-1.058-2.432-1.543-3.448-1.497 2.684-3.144 4.305-7.222 4.305-11.68 0-9.941-8.059-18-18-18s-18 8.059-18 18 8.059 18 18 18c4.458 0 8.536-1.621 11.68-4.305-0.047 1.015 0.439 2.272 1.497 3.448l9.67 11.37c1.656 1.84 4.36 1.995 6.010 0.345s1.495-4.355-0.345-6.010zM18 30c-6.627 0-12-5.373-12-12s5.373-12 12-12 12 5.373 12 12-5.373 12-12 12z'%3E%3C/path%3E%3C/svg%3E");
		}
		.icon-download::before {
			/*content:'⬇️';*/
		}
		[class^='icon-nav-']::before {
			content:'🏷️';
		}
		.icon-nav-<?= crc32($nav_items[0]['name']) ?>::before {
			/*content:'🗄️';*/
			content:'';
			background-image: url("data:image/svg+xml,%3Csvg version='1.1' xmlns='http://www.w3.org/2000/svg' width='1em' height='1em' viewBox='0 0 16 12'%3E%3Cpath fill-rule='evenodd' d='M9.5 3L8 4.5 11.5 8 8 11.5 9.5 13 14 8 9.5 3zm-5 0L0 8l4.5 5L6 11.5 2.5 8 6 4.5 4.5 3z'%3E%3C/path%3E%3C/svg%3E");
		}
		.icon-nav-<?= crc32($nav_items[1]['name']) ?>::before {
			/*content:'📦';*/
			content:'';
			background-image: url("data:image/svg+xml,%3Csvg version='1.1' xmlns='http://www.w3.org/2000/svg' width='1em' height='1em' viewBox='0 0 16 12'%3E%3Cpath fill-rule='evenodd' d='M1 4.27v7.47c0 .45.3.84.75.97l6.5 1.73c.16.05.34.05.5 0l6.5-1.73c.45-.13.75-.52.75-.97V4.27c0-.45-.3-.84-.75-.97l-6.5-1.74a1.4 1.4 0 0 0-.5 0L1.75 3.3c-.45.13-.75.52-.75.97zm7 9.09l-6-1.59V5l6 1.61v6.75zM2 4l2.5-.67L11 5.06l-2.5.67L2 4zm13 7.77l-6 1.59V6.61l2-.55V8.5l2-.53V5.53L15 5v6.77zm-2-7.24L6.5 2.8l2-.53L15 4l-2 .53z'%3E%3C/path%3E%3C/svg%3E");
		}
		.icon-nav-<?= crc32($nav_items[2]['name']) ?>::before {
			/*content:'📚';*/
			content:'';
			background-image: url("data:image/svg+xml,%3Csvg version='1.1' xmlns='http://www.w3.org/2000/svg' width='1em' height='1em' viewBox='0 0 16 12'%3E%3Cpath fill-rule='evenodd' d='M3 5h4v1H3V5zm0 3h4V7H3v1zm0 2h4V9H3v1zm11-5h-4v1h4V5zm0 2h-4v1h4V7zm0 2h-4v1h4V9zm2-6v9c0 .55-.45 1-1 1H9.5l-1 1-1-1H2c-.55 0-1-.45-1-1V3c0-.55.45-1 1-1h5.5l1 1 1-1H15c.55 0 1 .45 1 1zm-8 .5L7.5 3H2v9h6V3.5zm7-.5H9.5l-.5.5V12h6V3z'%3E%3C/path%3E%3C/svg%3E");
		}
		.icon-nav-<?= crc32($nav_items[3]['name']) ?>::before {
			/*content:'🚀';*/
			content:'';
			background-image: url("data:image/svg+xml,%3Csvg version='1.1' xmlns='http://www.w3.org/2000/svg' width='1em' height='1em' viewBox='0 0 16 12'%3E%3Cpath fill-rule='evenodd' d='M12.17 3.83c-.27-.27-.47-.55-.63-.88-.16-.31-.27-.66-.34-1.02-.58.33-1.16.7-1.73 1.13-.58.44-1.14.94-1.69 1.48-.7.7-1.33 1.81-1.78 2.45H3L0 10h3l2-2c-.34.77-1.02 2.98-1 3l1 1c.02.02 2.23-.64 3-1l-2 2v3l3-3v-3c.64-.45 1.75-1.09 2.45-1.78.55-.55 1.05-1.13 1.47-1.7.44-.58.81-1.16 1.14-1.72-.36-.08-.7-.19-1.03-.34a3.39 3.39 0 0 1-.86-.63zM16 0s-.09.38-.3 1.06c-.2.7-.55 1.58-1.06 2.66-.7-.08-1.27-.33-1.66-.72-.39-.39-.63-.94-.7-1.64C13.36.84 14.23.48 14.92.28 15.62.08 16 0 16 0z'%3E%3C/path%3E%3C/svg%3E");
		}
		.icon-nav-<?= crc32($nav_items[4]['name']) ?>::before {
			/*content:'🔀';*/
			content: '';
			background-image: url("data:image/svg+xml,%3Csvg version='1.1' xmlns='http://www.w3.org/2000/svg' width='1em' height='1em' viewBox='0 0 16 16'%3E%3Cpath fill-rule='evenodd' d='M10 5c0-1.11-.89-2-2-2a1.993 1.993 0 0 0-1 3.72v.3c-.02.52-.23.98-.63 1.38-.4.4-.86.61-1.38.63-.83.02-1.48.16-2 .45V4.72a1.993 1.993 0 0 0-1-3.72C.88 1 0 1.89 0 3a2 2 0 0 0 1 1.72v6.56c-.59.35-1 .99-1 1.72 0 1.11.89 2 2 2 1.11 0 2-.89 2-2 0-.53-.2-1-.53-1.36.09-.06.48-.41.59-.47.25-.11.56-.17.94-.17 1.05-.05 1.95-.45 2.75-1.25S8.95 7.77 9 6.73h-.02C9.59 6.37 10 5.73 10 5zM2 1.8c.66 0 1.2.55 1.2 1.2 0 .65-.55 1.2-1.2 1.2C1.35 4.2.8 3.65.8 3c0-.65.55-1.2 1.2-1.2zm0 12.41c-.66 0-1.2-.55-1.2-1.2 0-.65.55-1.2 1.2-1.2.65 0 1.2.55 1.2 1.2 0 .65-.55 1.2-1.2 1.2zm6-8c-.66 0-1.2-.55-1.2-1.2 0-.65.55-1.2 1.2-1.2.65 0 1.2.55 1.2 1.2 0 .65-.55 1.2-1.2 1.2z'%3E%3C/path%3E%3C/svg%3E");
		}

		/* Dark mode */
		@media screen and (prefers-color-scheme: dark) {
			body {
				background: #121212;
			}
			body,
			nav ol, nav ul {
				color: rgba(255,255,255,.8);
			}
			[class^="icon-"]::before {
				-webkit-filter: invert(100%);
				filter: invert(100%);
			}
		}
</style>

	<?php // Link to the syntax highlighter's CSS ?>
	<?php if (!empty($config['syntax_highlighter']['css'])): ?>
		<link rel="stylesheet" href="<?= getURL($config['syntax_highlighter']['css']) ?>" />
		<?php if ($config['syntax_highlighter']['strip_numbers'] === true): ?>
			<style>
				.source-numbers { display: none; }
			</style>
		<?php endif; ?>
	<?php endif; ?>
	<?php // Link to the CSS override ?>
	<?php if (!empty($config['override_css'])): ?>
		<link rel="stylesheet" href="<?= $config['override_css'] ?>" />
	<?php endif; ?>

</head>

<body>
	<div class="container">
<header>
	<h1 class="main-title"><a href="<?= $_webroot ?>"><?= $config['title'] ?></a></h1>
	<p class="main-description"><?= $config['description'] ?></p>

	<nav role="navigation" aria-label="Main">
		<ol class="main-nav-list">
			<?php foreach ($nav_items as $item): ?>
				<li class="<?= ($item['isactive']) ? 'active': ''; ?>"><a href="<?= $item['path'] ?>" class="icon-nav-<?= crc32($item['name']) ?>"><?= $item['name'] ?></a></li>
			<?php endforeach; ?>
		</ol>
	</nav>
</header>

<?php if (empty($_path)): ?>
	<nav class="index-nav" role="navigation" aria-label="Source">
		<ul>
			<li><?= $stats["number"] ?></li>
			<li><?= $stats["total_size"] ?></li>
			<?php if (!empty($license_file)): ?>
				<li><a href="<?= $license_file ?>" class="icon-license button">License</a></li>
			<?php endif; ?>
			<li><a href="?s" class="icon-find button">Find file</a></li>
			<?php if ($config['allow_download']): ?>
				<li><a href="?zip" class="icon-download button primary">Download</a></li>
			<?php endif; ?>
		</ul>
	</nav>
	<?= showStats() ?>
	<?php if ($alert !== false): ?>
		<p class="alert"><?= $alert ?></p>
	<?php endif; ?>
<?php elseif (!isset($search_items)): ?>
	<?= showBreadcrumb() ?>
<?php endif; ?>

<?php if (!empty($files_items)): ?>
	<table class="file-explorer" role="main" summary="Files of <?= $config['title'] ?>">
		<thead>
			<tr>
				<td scope="col">Name</td>
				<td scope="col">Size</td>
				<td scope="col">Last update</td>
			</tr>
		</thead>
		<tbody>
		<?php foreach ($files_items as $key => $item): ?>
			<tr scope="row">
				<td><a href="<?= getLink($item['name']) ?>" class="icon-<?= $item['isdir'] ? 'directory' : 'file' ?>"><?= $item['name'] ?></a></td>
				<td><?= humanizeFilesize($item['size'], 1) ?></td>
				<td><time datetime='<?= date('c', $item['mtime']) ?>'><?= friendlyDate($item['mtime']) ?></time></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
<?php ## Search ?>
<?php elseif (isset($search_items)): ?>
	<div class="search" role="main">
		<form method="get" action="" class="search-form">
			<label for="s" class="search-label"><a href="<?= $_webroot ?>"><?= basename($_root) ?></a> / </label>
			<input type="text" name="s" id="s" value="<?= $search_items['term'] ?>" class="search-input" autofocus />
			<input type="submit" name="submit" value="Search" class="button" />
		</form>
		<?php if (isset($search_items['term'])): ?>
			<h2>Search results for <em><?= $search_items['term'] ?></em></h2>
			<ol>
				<?php foreach ($search_items['files'] as $item => $cost): ?>
					<li><a href="<?= $_index . '/' . $item ?>"><?= $item ?></a></li>
				<?php endforeach; ?>
			</ol>
		</div>
	<?php endif; ?>
<?php ## A single file ?>
<?php ### If trying to view a file in root ?>
<?php elseif (empty($_parent)): get404(); ?>
<?php ### Else, it's alright ?>
<?php elseif (!empty($_path)): ?>
	<article class="source" role="main">
		<header class="source-header">
			<h1 class="icon-single-file"><?= basename($_path) ?> <span class="source-size"><?= humanizeFilesize(filesize($_root . $_path), 1) ?></span></h1>
			<?php if ($config['allow_download']): ?>
				<ul class="source-actions">
					<li><a href="?raw" class="button">Raw</a></li>
					<li><a href="?d" class="button">Download</a></li>
				</ul>
			<?php endif; ?>
		</header>
		<div class="source-content">
			<?= showSource($_root . $_path) ?>
		</div>
	</article>
	<?php if (!empty($config['syntax_highlighter']['js'])): ?>
		<script src="<?= getURL($config['syntax_highlighter']['js']) ?>"></script>
	<?php elseif (!empty(pathinfo($_root . $_path, PATHINFO_EXTENSION))): ?>
		<script type="text/javascript">
			const hightlight = (code) => code
				// PHP & XML/HTML Tags
				.replaceAll(/(<|<\?)/g, '<span>$1</span>') // Mandatory, else innerHTML comments them with <!-- -->
				// Operators
				.replaceAll(/\b(var|const|function|typeof|new|return|if|for|in|while|break|do|continue|switch|case|try|catch)([^a-z0-9\$_])/g,
					'<span class="c-operator">$1</span>$2')
				// Types
				.replaceAll(/\b(RegExp|Boolean|Number|String|Array|Object|Function|this|true|false|NaN|undefined|null|Infinity)([^a-z0-9\$_])/g,
					'<span class="c-type">$1</span>$2')
				// Comments
				.replaceAll(/(\/\*[^]*?\*\/|(\/\/)[^\n\r]+)/gim,'<span class="c-comment">$1</span>')
				// Strings
				.replaceAll(/('.*?'|".*?")/g,'<span class="c-string">$1</span>')
				// Variables & Function names
				.replaceAll(/([a-z\_\$][a-z0-9_]*)(\s?([\(\)\[\];]|[=+\-\*,<]\s)|\s>)/gi,'<a id="var-$1" href="#var-$1" class="c-variable">$1</a>$2')
				// Braces
				.replaceAll(/(\{|\}|\]|\[|\|)/gi,'<span class="c-punctuation">$1</span>')
				// Numbers
				.replaceAll(/(0x[0-9a-f]*|\b(\d*\.)?([\d]+(e-?[0-9]*)?)\b)/gi,'<span class="c-atom">$1</span>')//|(0x[0-9abcdefx]*)
				// Tabs (2 spaces)
				//.replace(/\t/g,'  ')

			document.querySelectorAll('pre > code')
		    	.forEach((code) => {
		    		console.log(code.innerText);
		        	code.innerHTML=hightlight(code.innerText)
		    	});

		</script>
		<style>
			code .c-type {font-weight:700}
			code .c-variable, .c-type {color: #228}
			code .c-operator {color: #708}
			code .c-string {color:#a22}
			code .c-punctuation {color:#666}
			code .c-atom {color:#281}
			code .c-comment, .c-comment * {color: #A70!important}
			code *:target{background-color:#ff6}
			@media screen and (prefers-color-scheme: dark) {
				code .c-type {color:#DB9455;font-style:700}
				code .c-operator {color: #B194B4}
				code .c-variable {color: #83A1C1}
				code .c-string {color:#D7C467}
				code .c-atom {color: #B1BE59}
				code .c-punctuation {color:inherit}
				code .c-comment, .c-comment * {color: #999!important;opacity:.5}
			}
		</style>
	<?php endif; ?>
<?php endif; ?>

<?php ## README ?>
<?php if (!empty($readme_file)): ?>
	<article class="readme">
		<header class="readme-header">
			<h1 class="icon-single-file"><?= basename($readme_file) ?></h1>
		</header>
		<div class="readme-content">
			<?= showREADME($readme_file); ?>
		</div>
	</article>
<?php endif; ?>

<?php ## Override JS ?>
<?php if (!empty($config['override_js'])): ?>
	<script src="<?= getURL($config['override_js']) ?>"></script>
<?php endif; ?>

<?php if ($config['show_footer'] === true): ?>
	<footer>
		<p><small>Powered by <a href="https://github.com/yomli/ygg">Ygg</a> and cooked by <a href="https://dev.yom.li/">yomli</a>.</small></p>
	</footer>
<?php endif; ?>
	</div>
</body>
</html>
		<?php
ob_flush();
?>
