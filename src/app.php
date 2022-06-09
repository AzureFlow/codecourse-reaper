<?php

use Dariuszp\CliProgressBar;

require __DIR__ . '/../vendor/autoload.php';


if(isset($argv[1]))
{
	$input = $argv[1];

	if(!filter_var($input, FILTER_VALIDATE_URL))
	{
		echo "Invalid URL! Make sure it's a full URL." . PHP_EOL;
		exit(1);
	}

	$parts = parse_url($input);
	if($parts['host'] !== 'codecourse.com')
	{
		echo 'Not a valid Codecourse.com page!' . PHP_EOL;
		exit(1);
	}

	// Strip query
	$courseUrl = $parts['scheme'] . '://' . $parts['host'] . ($parts['path'] ?? '');
}
else
{
	echo 'Missing course URL!' . PHP_EOL;
	exit(1);
}

try
{
	$courseInfo = getCourseInfo($courseUrl);
	$courseTitle = $courseInfo['courseInfo']['courseTitle'];

	foreach($courseInfo['videos'] as $video)
	{
//		$videoId = $video['videoId'];
//		$videoOrder = $video['videoOrder'];
//		$videoSlug = $video['videoSlug'];
//		$videoTitle = $video['videoTitle'];
//		$videoUrl = $video['videoUrl'];
//		echo "$videoOrder. $videoTitle (slug:$videoSlug, id:$videoId)\n\tURL: $videoUrl\n";

		if(!downloadVideo($video, $courseTitle))
		{
			echo 'Failed!' . PHP_EOL;
			break;
		}
	}

	$location = LIBRARY_ROOT . DIRECTORY_SEPARATOR . sanitizeFilename($courseTitle);
	echo PHP_EOL . "Downloaded to $location" . PHP_EOL;

	if(OPEN_EXPLORER_WHEN_DONE)
	{
		openExplorer($location);
	}
}
catch(JsonException $e)
{
}


/**
 * Extracts the course and video info from a Codecourse.com URL.
 * @param string $courseUrl Codecourse.com URL.
 * @return array
 * @throws JsonException
 * @throws InvalidArgumentException
 * @throws RuntimeException
 */
function getCourseInfo(string $courseUrl): array
{
	if(parse_url($courseUrl)['host'] !== 'codecourse.com')
	{
		throw new InvalidArgumentException('Must be a codecourse.com page!');
	}

	$xpath = xpathFromContent(file_get_contents($courseUrl));
	$query = $xpath->query('//div[@id="app"]/@data-page');
	if($query->length === 0)
	{
		throw new RuntimeException('Error parsing page!');
	}

	$courseInfo = json_decode($query->item(0)->textContent, true, 512, JSON_THROW_ON_ERROR);
	if($courseInfo['component'] !== 'Course' && $courseInfo['component'] !== 'Watch')
	{
		throw new RuntimeException('Not a course or watch page!');
	}

	$courseId = $courseInfo['props']['course']['data']['id'];
	$courseSlug = $courseInfo['props']['course']['data']['slug'];
	$courseTitle = $courseInfo['props']['course']['data']['title'];
	$videos = $courseInfo['props']['parts']['data'];

	$results = [];
	$results['courseInfo']['courseId'] = $courseId;
	$results['courseInfo']['courseSlug'] = $courseSlug;
	$results['courseInfo']['courseTitle'] = $courseTitle;

	foreach($videos as $video)
	{
		$videoId = $video['id'];
		$videoOrder = $video['order'];
		$videoTitle = $video['title'];
		$videoSlug = $video['slug'];
		$videoUrl = generateVideoUrl($courseId, $videoId, $videoOrder, $courseSlug, $videoSlug);

		$results['videos'][] = [
			'videoId' => $videoId,
			'videoOrder' => $videoOrder,
			'videoTitle' => $videoTitle,
			'videoSlug' => $videoSlug,
			'videoUrl' => $videoUrl,
		];
	}

	return $results;
}

/**
 * Downloads a video from a video object.
 * @param array $video Video object.
 * @param string $courseTitle Title of the course.
 * @return bool Returns true if successful and false if failed.
 */
function downloadVideo(array $video, string $courseTitle): bool
{
	$videoOrder = $video['videoOrder'];
	$videoTitle = $video['videoTitle'];
	$videoUrl = $video['videoUrl'];

	$downloadLocation = LIBRARY_ROOT . DIRECTORY_SEPARATOR . sanitizeFilename($courseTitle);
	$ext = pathinfo(basename(parse_url($videoUrl)['path']), PATHINFO_EXTENSION);
	$filename = sanitizeFilename($videoTitle);
	$filename = "$videoOrder. $filename.$ext";
	$fullPath = $downloadLocation . DIRECTORY_SEPARATOR . $filename;

	if(file_exists($fullPath) || (!file_exists($downloadLocation) && !mkdir($downloadLocation, 755, true) && !is_dir($downloadLocation)))
	{
		// Already exists, silently skip
		return true;
	}

	$fileSize = urlFilesize($videoUrl);
	if($fileSize === -1)
	{
		return false;
	}

	$freeSpace = disk_free_space(dirname($downloadLocation));
	if($fileSize >= $freeSpace)
	{
		die("===== NOT ENOUGH DISK SPACE - SHUTTING DOWN! =====\n=====   PLEASE FREE UP SPACE AND RUN AGAIN   =====\n");
	}

	return downloadFile($videoUrl, $fullPath);
}

/**
 * Downloads a file.
 * @param string $url What URL to download.
 * @param string $filename Where to download to.
 * @return bool Returns true if successful and false if failed.
 */
function downloadFile(string $url, string $filename): bool
{
	$fp = fopen($filename, 'wb+');
	$ch = curl_init();

	$bar = new CliProgressBar(0, 0, basename($filename));
	$bar->setColorToYellow();

	curl_setopt_array($ch, [
		CURLOPT_URL => $url,
		CURLOPT_FILE => $fp,
		CURLOPT_PROGRESSFUNCTION => static function($resource, $dlTotalBytes, $dlCurrentBytes, $ulTotalBytes, $ulCurrentBytes) use (&$bar) {
			if($dlTotalBytes === 0)
			{
				return 0;
			}

			if($bar->getSteps() === 0)
			{
				$bar->setSteps($dlTotalBytes);
			}

			$bar->setProgressTo($dlCurrentBytes);
			$bar->display();

			return 0;
		},
		CURLOPT_NOPROGRESS => false,
		CURLOPT_FOLLOWLOCATION => true,
//			CURLOPT_TCP_KEEPALIVE => 10,
//			CURLOPT_TCP_KEEPIDLE => 10,
	]);


	$downloadResult = curl_exec($ch);
	$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
	$success = $downloadResult === true && curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200 && $contentType !== 'application/xml';

	curl_close($ch);
	fclose($fp);

	if($success)
	{
		$bar->setColorToGreen();
	}
	else
	{
		// Delete on fail
		@unlink($filename);
		$bar->setColorToRed();
	}

	$bar->display();
	$bar->end();

	return $success;
}

/**
 * Generates a video download URL from a collection of parameters.
 * @param string $courseId Course ID.
 * @param string $videoId Video ID.
 * @param string $videoOrder Video order.
 * @param string $courseSlug Course slug.
 * @param string $videoSlug Video slug.
 * @return string Returns the video url.
 */
function generateVideoUrl(string $courseId, string $videoId, string $videoOrder, string $courseSlug, string $videoSlug): string
{
	// Prefixes the number. e.g. 1 = 01
	$videoOrder = sprintf('%02d', $videoOrder);
	$videoSlug = fixVideoSlug($videoSlug);

	$url = "https://videos-codecourse.ams3.digitaloceanspaces.com/$courseId/$videoId/$videoOrder-$courseSlug-$videoSlug-hd.mp4";
	echo "[-] DEBUG: $url" . PHP_EOL;

	return $url;
}

/**
 * Gets the size of an url.
 * @param string $url The URl.
 * @return int Returns the size of the download. -1 on failure.
 */
function urlFilesize(string $url): int
{
	$ch = curl_init();
	curl_setopt_array($ch, [
		CURLOPT_URL => $url,
		CURLOPT_NOBODY => true,
//			CURLOPT_HEADER => true,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
	]);

	$result = curl_exec($ch);
	$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
	$bytes = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
	// Returns -1 for no reason...

	return $result === true && $contentType !== 'application/xml' ? $bytes : -1;
}