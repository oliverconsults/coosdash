<?php
// Read UTF-8 text from STDIN and output base64 (single line).
$in = stream_get_contents(STDIN);
if ($in === false) $in = '';
echo base64_encode($in);
