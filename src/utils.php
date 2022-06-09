<?php

/**
 * Fixes a video slug for usage in creating a CDN URL.
 * @param string $videoSlug Video slug.
 * @param string $courseId [optional] Course ID.
 * @return string Returns the fixed slug for the CDN URL.
 */
function fixVideoSlug(string $videoSlug, string $courseId = ''): string
{
	// TODO: This is missing a lot of things:
	// https://github.com/laravel/framework/blob/6.x/src/Illuminate/Support/Str.php#L497

	if(!empty($courseId))
	{
		$videoSlug = "$courseId-$videoSlug";
	}

	$videoSlug = strtolower($videoSlug);
	$videoSlug = str_replace(array(' ', '_', '/'), array('-', '-', ''), $videoSlug);
	$videoSlug = trim($videoSlug);
	$videoSlug = urlencode($videoSlug);

	return $videoSlug;
}

/**
 * @param string $filename The filename to be sanitized.
 * @return string Returns the sanitized filename.
 */
function sanitizeFilename(string $filename): string
{
	return str_replace(['\\', '/', ':', '*', '?', '"', '<', '>', '|'], '', $filename);
}

/**
 * Converts html to an XPath.
 * @param string $content The HTML content.
 * @return DOMXPath Returns the XPath result.
 */
function xpathFromContent(string $content): DOMXPath
{
	$doc = new DOMDocument();
	@$doc->loadHTML($content);

	return new DOMXPath($doc);
}

function isWindows(): bool
{
	return stripos(PHP_OS_FAMILY, 'Win') === 0;
}

function openExplorer(string $downloadFolder): void
{
	if(isWindows())
	{
		exec(escapeshellcmd("explorer.exe \"$downloadFolder\""));
	}
}