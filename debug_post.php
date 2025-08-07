<?php file_put_contents("/var/www/novacents/tools/post_debug.log", date("Y-m-d H:i:s") . " POST: " . json_encode($_POST) . PHP_EOL, FILE_APPEND); ?>
