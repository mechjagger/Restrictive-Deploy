<?php
/**
 * Simple concept, take the input (get, post, server, etc)
 * dump to a log file, or email results.
 *
 * Why? Because I need to test incoming stuff sometimes without
 * being able to provide additional inputs.
 */

define('LOG_TO_SCREEN', true);

define('LOG_TO_DISK', true);
define('LOG_NAME', 'post.txt');

define('LOG_TO_EMAIL', false);
define('EMAIL', '');

$data =
    "## POST ".print_r($_POST, true).
    "## GET ".print_r($_GET, true).
    "## COOKIE ".print_r($_COOKIE, true).
    "## SERVER ".print_r($_SERVER, true);

if (LOG_TO_SCREEN){
    echo '<pre>'.$data.'</pre>';
}

if (LOG_TO_DISK) {
    file_put_contents(LOG_NAME, $data);
}

if (LOG_TO_EMAIL) {
    mail(EMAIL, 'Script Received', $data);
}

