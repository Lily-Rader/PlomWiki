<?php

$AutoLink_dir   = $plugin_dir.'AutoLink/';
$actions_meta[] = array('Build AutoLink DB', '?action=autolink_build_db');

##########
# Markup #
##########

function MarkupAutolink($text)
# Autolink $text according to its Autolink file.
{ global $AutoLink_dir, $root_rel, $title;
  
  # Don't do anything if there's no Autolink file for the page displayed
  $cur_page_file = $AutoLink_dir.$title;
  if (!is_file($cur_page_file))
    return $text; 
  
  # Get $links_out from $cur_page_file, turn into regex from their resp. files.
  $links_out = Autolink_GetFromFileLine($cur_page_file, 1, TRUE);
  foreach ($links_out as $pagename)
  { $linked_page_file = $AutoLink_dir.$pagename;
    $regex_pagename = Autolink_GetFromFileLine($linked_page_file, 0);
    
    # Build autolinks into $text where $avoid applies.
    $avoid = '(?=[^>]*($|<(?!\/(a|script))))';
    $match = '/('.$regex_pagename.')'.$avoid.'/iu';
    $repl  = '<a href="'.$root_rel.'?title='.$pagename.'">$1</a>';
    $text  = preg_replace($match, $repl, $text); }
  
  return $text; }

####################
# DB bootstrapping #
####################

function Action_autolink_build_db()
# Form asking for confirmation and password before triggering AutoLink DB build.
{ global $nl, $root_rel;

  # Final HTML.
  $title_h = 'Build AutoLink DB.';
  $form    = '<p>Build AutoLink DB?</p>'.$nl.
             '<form method="post" action="'.$root_rel.'?action=write&amp;t='.
                                                      'autolink_build_db">'.$nl.
             'Admin password: <input type="password" name="pw" />'.$nl.
             '<input type="submit" value="Build!" />'.$nl.'</form>';
  Output_HTML($title_h, $form); }

function PrepareWrite_autolink_build_db()
# Deliver to Action_write() all information needed for AutoLink DB building.
{ global $AutoLink_dir, $nl, $root_rel, $pages_dir, $todo_urgent;

  # Variables easily produced.
  $x['todo'] = $todo_urgent;
  $x['msg']  = '<p>Building AutoLink database.</p>';

  # Abort if $AutoLink_dir found, else prepare task to create it.
  if (is_dir($AutoLink_dir))
    ErrorFail('Not building AutoLink DB.', 
              'Directory already exists. <a href="'.$root_rel.
                                       '?action=autolink_purge_db">Purge?</a>');
  $x['tasks'][] = array('mkdir', $AutoLink_dir);

  # Build page file creation, linking tasks.
  $titles = GetAllPageTitles();
  foreach ($titles as $title)
    $x['tasks'][] = array('AutoLink_CreateFile', $title);
  foreach ($titles as $title)
    foreach ($titles as $linkable)
      if ($linkable != $title)
        $x['tasks'][] = array('AutoLink_TryLinking', $title.'_'.$linkable);

  return $x; }

function Action_autolink_purge_db()
# Form asking for confirmation and password before triggering AutoLink DB purge.
{ global $nl, $root_rel;

  # Final HTML.
  $title_h = 'Purge AutoLink DB.';
  $form    = '<p>Purge AutoLink DB?</p>'.$nl.
             '<form method="post" action="'.$root_rel.'?action=write&amp;t='.
                                                      'autolink_purge_db">'.$nl.
             'Admin password: <input type="password" name="pw" />'.$nl.
             '<input type="submit" value="Purge!" />'.$nl.'</form>';
  Output_HTML($title_h, $form); }

function PrepareWrite_autolink_purge_db()
# Deliver to Action_write() all information needed for AutoLink DB purging.
{ global $AutoLink_dir, $nl, $root_rel, $todo_urgent;

  # Variables easily produced.
  $x['todo'] = $todo_urgent;
  $x['msg']  = '<p>Purging AutoLink database.</p>';

  # Abort if $AutoLink_dir found, else prepare task to create it.
  if (!is_dir($AutoLink_dir))
    ErrorFail('Not purging AutoLink DB.', 'Directory does not exist.');

  # Add unlink(), rmdir() tasks for $AutoLink_dir and its contents.
  $p_dir = opendir($AutoLink_dir);
  while (FALSE !== ($fn = readdir($p_dir)))
    if (is_file($AutoLink_dir.$fn))
      $x['tasks'][] = array('unlink', $AutoLink_dir.$fn);
  closedir($p_dir); 
  $x['tasks'][] = array('rmdir', $AutoLink_dir);

  return $x; }

##########################################
# DB writing tasks to be called by todo. #
##########################################

function AutoLink_TryLinking($titles)
# $titles = $title_$linkable. Try auto-linking both pages, write to their files.
{ global $AutoLink_dir, $nl, $pages_dir;
  list($title, $linkable) = explode('_', $titles);
  $page_txt = file_get_contents($pages_dir.$title);

  $path_linkable = $AutoLink_dir.$linkable;
  $regex_linkable = Autolink_GetFromFileLine($path_linkable, 0);
  if (preg_match('/'.$regex_linkable.'/iu', $page_txt))
  { AutoLink_InsertInLine($title.'_1_'.$linkable);
    AutoLink_InsertInLine($linkable.'_2_'.$title); } }

function AutoLink_InsertInLine($string)
# Add in $path_file on $line_n $insert (last two found in $path_temp file).
{ global $AutoLink_dir, $nl;

  # Get $title, $line_n, $insert from $string.
  list($title, $line_n, $insert) = explode('_', $string);

  # Get $content from $title's AutoLink file, add $insert.whitespace on $line_n.
  $path_file = $AutoLink_dir.$title;
  $lines = explode($nl, file_get_contents($path_file));
  $lines[$line_n] = $lines[$line_n].$insert.' ';
  $content = implode($nl, $lines);
    
  # Put $content into temp file for SafeWrite() to harvest.
  $path_temp= NewTempFile($content);
  SafeWrite($path_file, $path_temp); }

function AutoLink_CreateFile($title)
# Start AutoLink file of page $title, empty but for title regex.
{ global $AutoLink_dir, $nl2;

  # $content (at start empty but for first, regex line) shall rest at $path.
  $path    = $AutoLink_dir.$title;
  $content = $title.$nl2;
  
  # Put $content into temp file for SafeWrite() to harvest.
  $path_temp = NewTempFile($content);
  SafeWrite($path, $path_temp); }

##########################
# Minor helper functions #
##########################

function Autolink_GetFromFileLine($path, $line, $return_as_array = FALSE)
# Return $line of file $path. $return_as_array string separated by ' ' if set.
{ global $nl;
  $x = explode($nl, file_get_contents($path));
  $x = $x[$line];
  if ($return_as_array)
  { $x = rtrim($x);
    $x = explode(' ', $x); }
  return $x; }
