<?php
/* WikiParser
 *
 * Copyright 2011, Fabian Graßl
 *
 * derived from
 * Version 1.0
 * Copyright 2005, Steve Blinch
 * http://code.blitzaffe.com
 *
 * This class parses and returns the HTML representation of a document containing
 * basic MediaWiki-style wiki markup.
 *
 *
 * USAGE
 *
 * Refer to class_WikiRetriever.php (which uses this script to parse fetched
 * wiki documents) for an example.
 *
 *
 * LICENSE
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 */

class WikiParser
{
  protected $emphasis = array();

  protected $page_title = '';
  protected $redirect = false;

  protected $nowikis = array();
  protected $list_level_types = array();
  protected $list_level = 0;

  protected $deflist = false;
  protected $linknumber = 0;
  protected $suppress_linebreaks = false;

  protected $stop = false;
  protected $stop_all = false;

  protected $preformat = false;

  /**
   * Options - can be set by the constructor or by setOption().
   *
   * @var array
   * @see setOption
   */
  protected $options = array(
    'strip_internal_links'    => false,
    'internal_link_prefix'    => null,
    'strip_images'            => false,
    'reduce_images_to_title'  => false,
    'image_prefix'            => null,
    'image_parser_callback'   => null,
    'clean_html'              => true,
    'use_semantic_emphasis'   => false,
  );

  /**
   * Constructor.
   *
   * @param array  $options       An array of options
   * @see setOption
   */
  public function __construct($options = array())
  {
    foreach ($options as $key => $val)
    {
      $this->setOption($key, $val);
    }
  }

  /**
   * Get an option
   *
   * @param string  $name       Name of the option to get
   * @see setOption
   */
  public function getOption($name)
  {
    if (array_key_exists($name, $this->options))
    {
      return $this->options[$name];
    }
    else
    {
      throw new InvalidArgumentException("The Option '$name' does not exist!");
    }
  }

  /**
   * Set an option
   *
   * Available options:
   *
   *  * strip_internal_links:         Whether to strip all internal links
   *  * internal_link_prefix:         Prefix for all internal wiki links. I.e. http://en.wikipedia.org/wiki/
   *  * strip_images:                 Whether to strip image tags
   *  * reduce_images_to_title:       Whether to reduce all images to their (text) title
   *  * image_prefix:                 Prefix-URL for all Images
   *  * image_parser_callback:        Callback to be used instead of handle_image. See handle_image for arguments & return value.
   *  * clean_html:                   Clean HTML using DOMDocument
   *
   * @param string  $name       Name of the option to set
   * @param mixed  $value       Valueof the option to set
   */
  public function setOption($name, $value)
  {
    if (array_key_exists($name, $this->options))
    {
      return $this->options[$name] = $value;
    }
    else
    {
      throw new InvalidArgumentException("The Option '$name' does not exist!");
    }
  }

  /**
   * Parse wiki markup
   *
   *
   * @param string  $text       Text of the document
   * @param string  $title      Title of the document (used for the PAGENAME variable)
   */
  public function parse($text, $title = "")
  {
    $this->page_title = $title;

    $output = "";

    $text = preg_replace_callback('/<nowiki>([\s\S]*)<\/nowiki>/i',array($this,"handle_save_nowiki"),$text);

    $lines = explode("\n",$text);

    if (preg_match('/^\#REDIRECT\s+\[\[(.*?)\]\]$/',trim($lines[0]),$matches))
    {
      $this->redirect = $matches[1];
    }

    foreach ($lines as $k=>$line)
    {
      $line = $this->parse_line($line);
      $output .= $line;
    }

    $output = preg_replace_callback('/<nowiki><\/nowiki>/i', array($this,"handle_restore_nowiki"),$output);

    if ($this->getOption('clean_html'))
    {
      $output = $this->clean_html($output);
    }

    return $output;
  }

  protected function handle_sections($matches)
  {
    $level = strlen($matches[1]);
    $content = $matches[2];

    $this->stop = true;
    // avoid accidental run-on emphasis
    return $this->emphasize_off() . "\n\n<h{$level}>{$content}</h{$level}>\n\n";
  }

  protected function handle_newline($matches)
  {
    if ($this->suppress_linebreaks)
    {
      return $this->emphasize_off();
    }

    $this->stop = true;
    // avoid accidental run-on emphasis
    return $this->emphasize_off() . "<br /><br />";
  }

  protected function handle_list($matches, $close = false)
  {
    $listtypes = array(
      '*'=>'ul',
      '#'=>'ol',
    );

    $output = "";
    $closed_list = false;

    $newlevel = ($close) ? 0 : strlen($matches[1]);

    while ($this->list_level != $newlevel)
    {
      $listchar = substr($matches[1], -1);
      $listtype = $listtypes[$listchar];

      if ($this->list_level > $newlevel)
      {
        $listtype = '/'.array_pop($this->list_level_types);
        $this->list_level--;
      }
      else
      {
        $this->list_level++;
        array_push($this->list_level_types, $listtype);
      }
      if ($listtype[0]=='/')
      {
        $output .= "</li>\n<{$listtype}>\n";
        $closed_list = true;
      }
      else
      {
        $output .= "\n<{$listtype}>\n<li>";
      }
    }

    if ($close)
    {
      return $output;
    }

    if (empty($output) OR ($closed_list && $this->list_level > 0))
    {
      $output .= "</li>\n<li>";
    }
    $output .= $matches[2];

    return $output;
  }

  protected function handle_definitionlist($matches, $close=false)
  {
    if ($close)
    {
      $this->deflist = false;
      return "</dl>\n";
    }

    $output = "";
    if (!$this->deflist) $output .= "<dl>\n";
    $this->deflist = true;

    switch ($matches[1])
    {
      case ';':
        $term = $matches[2];
        $p = strpos($term,' :');
        if ($p!==false)
        {
          list($term,$definition) = explode(':',$term);
          $output .= "<dt>{$term}</dt><dd>{$definition}</dd>";
        }
        else
        {
          $output .= "<dt>{$term}</dt>";
        }
        break;
      case ':':
        $definition = $matches[2];
        $output .= "<dd>{$definition}</dd>\n";
        break;
    }

    return $output;
  }

  protected function handle_preformat($matches, $close = false)
  {
    if ($close)
    {
      $this->preformat = false;
      return "</pre>\n";
    }

    $this->stop_all = true;

    $output = "";
    if (!$this->preformat)
    {
      $output .= "<pre>";
    }
    $this->preformat = true;

    $output .= $matches[1];

    return $output."\n";
  }

  protected function handle_horizontalrule($matches)
  {
    return "<hr />";
  }

  protected function wiki_link($topic)
  {
    return ucfirst(str_replace(' ','_',$topic));
  }

  protected function handle_image($href, $title, $options)
  {
    if ($this->getOption('strip_images'))
    {
      return "";
    }

    if ($this->getOption('reduce_images_to_title'))
    {
      return $title;
    }

    $href = $this->getOption('image_prefix') . $href;

    $imagetag = sprintf(
      '<img src="%s" alt="%s" />',
      $href,
      $title
    );

    foreach ($options as $k=>$option)
    {
      switch($option)
      {
        case 'frame':
          $imagetag = sprintf(
            '<div style="float: right; background-color: #F5F5F5; border: 1px solid #D0D0D0; padding: 2px">'.
            '%s'.
            '<div>%s</div>'.
            '</div>',
            $imagetag,
            $title
          );
          break;
        case 'right':
          $imagetag = sprintf(
            '<div style="float: right">%s</div>',
            $imagetag
          );
          break;
      }
    }

    return $imagetag;
  }

  protected function handle_internallink($matches)
  {
    $href = $matches[4];
    $title = $matches[6] ? $matches[6] : $href.$matches[7];
    $namespace = $matches[3];

    if (in_array($namespace, array('Image', 'File')))
    {
      $options = explode('|',$title);
      $title = array_pop($options);

      if (null === $this->getOption('image_parser_callback'))
      {
        return $this->handle_image($href,$title,$options);
      }
      else
      {
        call_user_func($this->getOption('image_parser_callback'), $href, $title, $options);
      }
    }

    $title = preg_replace('/\(.*?\)/','',$title);
    $title = preg_replace('/^.*?\:/','',$title);

    if ($this->getOption('strip_internal_links'))
    {
      return $title;
    }

    $href = $this->getOption('internal_link_prefix').($namespace?$namespace.':':'').$this->wiki_link($href);

    return sprintf(
      '<a href="%s"%s>%s</a>',
      $href,
      ($newwindow?' target="_blank"':''),
      $title
    );
  }

  protected function handle_externallink($matches)
  {
    $href = $matches[2];
    $title = $matches[3];
    if (!$title)
    {
      $this->linknumber++;
      $title = "[{$this->linknumber}]";
    }
    $newwindow = true;

    return sprintf(
      '<a href="%s"%s>%s</a>',
      $href,
      ($newwindow?' target="_blank"':''),
      $title
    );
  }

  protected function emphasize($amount)
  {
    if ($this->getOption('use_semantic_emphasis'))
    {
      $amounts = array(
        2 => array('<em>','</em>'),
        3 => array('<strong>','</strong>'),
        4 => array('<strong>','</strong>'),
        5 => array('<em><strong>','</strong></em>'),
      );
    }
    else
    {
      $amounts = array(
        2 => array('<i>','</i>'),
        3 => array('<b>','</b>'),
        4 => array('<b>','</b>'),
        5 => array('<b><i>','</i></b>'),
      );
    }

    $output = "";

    // handle cases where emphasized phrases end in an apostrophe, eg: ''somethin'''
    // should read <em>somethin'</em> rather than <em>somethin<strong>
    if ( (!$this->emphasis[$amount]) && ($this->emphasis[$amount-1]) )
    {
      $amount--;
      $output = "'";
    }

    $output .= $amounts[$amount][(int) $this->emphasis[$amount]];

    $this->emphasis[$amount] = !$this->emphasis[$amount];

    return $output;
  }

  protected function handle_emphasize($matches)
  {
    $amount = strlen($matches[1]);
    return $this->emphasize($amount);
  }

  protected function emphasize_off()
  {
    $output = "";
    foreach ($this->emphasis as $amount=>$state)
    {
      if ($state)
      {
        $output .= $this->emphasize($amount);
      }
    }
    return $output;
  }

  protected function handle_eliminate($matches)
  {
    return "";
  }

  protected function handle_variable($matches)
  {
    switch($matches[2])
    {
      case 'CURRENTMONTH': return date('m');
      case 'CURRENTMONTHNAMEGEN':
      case 'CURRENTMONTHNAME': return date('F');
      case 'CURRENTDAY': return date('d');
      case 'CURRENTDAYNAME': return date('l');
      case 'CURRENTYEAR': return date('Y');
      case 'CURRENTTIME': return date('H:i');
      case 'NUMBEROFARTICLES': return 0;
      case 'PAGENAME': return $this->page_title;
      case 'NAMESPACE': return 'None';
      case 'SITENAME': return $_SERVER['HTTP_HOST'];
      default: return '';
    }
  }

  protected function parse_line($line)
  {
    $line_regexes = array(
      'preformat'=>'^\s(.*?)$',
      'definitionlist'=>'^([\;\:])\s*(.*?)$',
      'newline'=>'^$',
      'list'=>'^([\*\#]+)(.*?)$',
      'sections'=>'^(={1,6})(.*?)(={1,6})$',
      'horizontalrule'=>'^----$',
    );
    $char_regexes = array(
      'internallink'=>'('.
        '\[\['. // opening brackets
          '(([^\]]*?)\:)?'. // namespace (if any)
          '([^\]]*?)'. // target
          '(\|([^\]]*?))?'. // title (if any)
        '\]\]'. // closing brackets
        '([a-z]+)?'. // any suffixes
        ')',
      'externallink'=>'('.
        '\['.
          '([^\]]*?)'.
          '(\s+[^\]]*?)?'.
        '\]'.
        ')',
      'emphasize'=>'(\'{2,5})',
      'eliminate'=>'(__TOC__|__NOTOC__|__NOEDITSECTION__)',
      'variable'=>'('. '\{\{' . '([^\}]*?)' . '\}\}' . ')',
    );

    $this->stop = false;
    $this->stop_all = false;

    $called = array();

    $line = rtrim($line);

    foreach ($line_regexes as $func => $regex)
    {
      if (preg_match("/$regex/i", $line, $matches))
      {
        $called[$func] = true;
        $func = "handle_".$func;
        $line = $this->$func($matches);
        if ($this->stop || $this->stop_all)
        {
          break;
        }
      }
    }
    if (!$this->stop_all)
    {
      $this->stop = false;
      foreach ($char_regexes as $func=>$regex)
      {
        $line = preg_replace_callback("/$regex/i",array($this,"handle_".$func),$line);
        if ($this->stop)
        {
          break;
        }
      }
    }

    $isline = strlen(trim($line)) > 0;

    // if this wasn't a list item, and we are in a list, close the list tag(s)
    if (($this->list_level>0) && !$called['list'])
    {
      $line = $this->handle_list(false,true) . $line;
    }
    if ($this->deflist && !$called['definitionlist'])
    {
      $line = $this->handle_definitionlist(false,true) . $line;
    }
    if ($this->preformat && !$called['preformat'])
    {
      $line = $this->handle_preformat(false,true) . $line;
    }

    // suppress linebreaks for the next line if we just displayed one; otherwise re-enable them
    if ($isline)
    {
      $this->suppress_linebreaks = ($called['newline'] || $called['sections']);
    }

    return $line;
  }

  protected function handle_save_nowiki($matches)
  {
    array_push($this->nowikis,$matches[1]);
    return "<nowiki></nowiki>";
  }

  protected function handle_restore_nowiki($matches)
  {
    return array_pop($this->nowikis);
  }

  /**
   * Use DOMDocument to validate HTML.
   */
  protected function clean_html($html)
  {
    $doc = new DOMDocument();
    $doc->validateOnParse = true;
    @$doc->loadHTML($html);
    $newHtml = $doc->saveHTML();
    $newHtml = $this->get_cuted_text_part('<body>', '</body>', $newHtml);
    return $newHtml;
  }

  protected function get_cuted_text_part($from, $to, $text)
  {
    $startPos = strpos($text, $from, 0) + strlen($from);
    $endPos = strpos($text, $to, 0);
    return substr($text, $startPos, $endPos-$startPos);
  }
}
