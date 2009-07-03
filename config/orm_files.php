<?php

$upload_dir = Kohana::config('upload.directory', TRUE);

foreach(Kohana::config('database') as $group => $settings)
{
	$config[$group] = $upload_dir.$settings['connection']['database'];
}
