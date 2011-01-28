<?php

require_once(dirname(__FILE__).'/lib/WikiParser.class.php');

$parser = new WikiParser();
$text = file_get_contents(dirname(__FILE__).'/example.txt');
echo $parser->parse($text);
