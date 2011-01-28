<?php

require_once(dirname(__FILE__).'/lib/WikiParser.class.php');

$options = array(
  'clean_html' => false,
);
$parser = new WikiParser($options);
$text = file_get_contents(dirname(__FILE__).'/example.txt');
echo $parser->parse($text);
