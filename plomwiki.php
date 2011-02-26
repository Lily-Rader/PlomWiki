<!DOCTYPE html>
<meta charset="UTF-8">
<?php

##################
# Initialization #
##################

# Password hash salt. Change if you like, but update passwords.txt hashes, too.
$salt = '2RoNFIrcRa7WjWVUE8d2JYnTzK8lma4mFVpJPpWlGWdUnqavEKYVoHXfYmPWy1dT';

# Filesystem information.
$config_dir = 'config/';         $markup_list_path = $config_dir.'markups.txt';
$plugin_dir = 'plugins/';        $pw_path          = $config_dir.'password.txt';
$pages_dir  = 'pages/';          $plugin_list_path = $config_dir.'plugins.txt';
$diff_dir   = $pages_dir.'diffs/';     $work_dir      = 'work/';
$del_dir    = $pages_dir.'deleted/';   $work_temp_dir = $work_dir.'temp/';
$setup_file = 'setup.php';             $todo_urgent   = $work_dir.'todo_urgent';
$work_failed_logins_dir = $work_dir.'failed_logins/';

# Newline information. PlomWiki likes "\n", dislikes "\r".
$nl = "\n";                      $nl2 = $nl.$nl;                    $esc = "\r";

# Check for unfinished setup file, execute if found.
if (is_file($setup_file))
  require($setup_file);

# URL generation information.
$root_rel = 'plomwiki.php';      $title_root = $root_rel.'?title=';

# Get $max_exec_time and $now to know until when orderly stopping is possible.
$max_exec_time = ini_get('max_execution_time');                   $now = time();

# Default action bar links data, read by ActionBarLinks() for Output_HTML().
$actions_meta = array(array('Jump to Start page', '?title=Start'),
                      array('Set admin password', '?action=set_pw_admin'));
$actions_page = array(array('View',               '&amp;action=page_view'),
                      array('Edit',               '&amp;action=page_edit'),
                      array('History',            '&amp;action=page_history'));

# Get page title. Build dependent variables.
$legal_title = '[a-zA-Z0-9-]+';
$title       = GetPageTitle($legal_title);
$page_path   = $pages_dir .$title;
$diff_path   = $diff_dir  .$title;
$title_url   = $title_root.$title;

# Insert plugins' code.
foreach (ReadAndTrimLines($plugin_list_path) as $line)
  require($line);

# Before executing user's action, do urgent work if urgent todo file is found.
if (is_file($todo_urgent))
  WorkTodo($todo_urgent, TRUE);
$action = GetUserAction();
$action();

############################################
#                                          #
#   P A G E - S P E C I F IC   S T U F F   #
#                                          #
############################################

#######################
# Common page actions #
#######################

function Action_page_view()
# Formatted display of a page.
{ global $hook_Action_page_view, $page_path, $title, $title_url;
  
  # Get text from file. If none, show invitation to create one. Else, markup it.
  if (is_file($page_path)) 
  { $text = file_get_contents($page_path); 
    $text = EscapeHTML($text); 
    $text = Markup($text); }
  else
    $text = '<p>Page does not exist. <a href="'.$title_url.
                                       '&amp;action=page_edit">Create?</a></p>';

  # Before leaving, execute plugin hook.
  eval($hook_Action_page_view);
  Output_HTML($title, $text); }

function Action_page_edit()
# Edit form on a page source text. Send results to ?action=write.
{ global $hook_Action_page_edit, $markup_help, $nl, $nl2, $page_path, $title,
         $title_url;

  # If no page file is found, start with an empty $text.
  if (is_file($page_path)) 
  { $text = file_get_contents($page_path); 
    $text = EscapeHTML($text); }
  else $text = '';

  # Final HTML of edit form and JavaScript to localStorage-store password.
  $input = '<pre><textarea name="text" rows="20" style="width:100%">'.$nl.
           $text.'</textarea></pre>'.$nl.
           'Author: <input name="author" type="text" />'.$nl.
           'Summary: <input name="summary" type="text" />';
  $form = BuildPostForm($title_url.'&amp;action=write&amp;t=page', $input);
  eval($hook_Action_page_edit);
  $content = $form.$nl2.$markup_help;
  Output_HTML('Editing: '.$title, $content); }

function Action_page_history()
# Show version history of page (based on its diff file), offer reverting.
{ global $diff_path, $nl, $nl2, $title, $title_url;
  $text = '<p>Page "'.$title.'" has no history.</p>';           # Fallback text.

  # Try to build $diff_list from $diff_path. If successful, format into HTML.
  if (is_file($diff_path)) 
    $diff_list = DiffList($diff_path);
  if ($diff_list)
  { 
    # Transform key into datetime string and revert link.
    $diffs = array();
    foreach ($diff_list as $id => $diff_data)
    { $time_string = date('Y-m-d H:i:s', (int) $diff_data['time']);
      $author      = EscapeHTML($diff_data['author']);
      $summary     = EscapeHTML($diff_data['summary']);
      $desc        = $time_string.': '.$summary.' ('.$author.')';
      $diffs[] =  '<p>'.$desc.' (<a href="'.$title_url.'&amp;action=page_revert'
                  .'&amp;id='.$id.'">revert</a>):</p>'.$nl.'<div class="diff">';

      # Preformat remaining lines. Translate arrows into less ambiguous +/-.
      foreach (explode($nl, $diff_data['text']) as $line_n => $line)
      { if     ($line[0] == '>') $line = '+ '.substr($line, 1);
        elseif ($line[0] == '<') $line = '- '.substr($line, 1);
        $diffs[] = '<pre>'.EscapeHTML($line).'</pre>'; } 
      $diffs[] = '</div>'.$nl; }
    $text = implode($nl, $diffs); }

  # Final HTML.
  $css = '<style type="text/css">'.$nl.
         'pre'.$nl.'{ white-space: pre-wrap;'.$nl.'  text-indent:-12pt;'.$nl.
         '  margin-top:0px;'.$nl.'  margin-bottom:0px; }'.$nl2.'.diff '.$nl.
         '{ margin-left:12pt; }'.$nl.'</style>';
  Output_HTML('Diff history of: '.$title, $text, $css); }

function Action_page_revert()
# Prepare version reversion and ask user for confirmation.
{ global $diff_path, $nl, $title, $title_url, $page_path;
  $id          = $_GET['id'];
  $diff_list   = DiffList($diff_path);
  $time_string = date('Y-m-d H:i:s', (int) $diff_list[$id]['time']);

  # Revert $text back through $diff_list until $time hits $id.
  $text = file_get_contents($page_path);
  foreach ($diff_list as $i => $diff_data)
  { if ($finished) break;
    $reversed_diff           = ReverseDiff($diff_data['text']); 
    $text                    = PlomPatch($text, $reversed_diff);
    if ($id == $i) $finished = TRUE; }
  $text = EscapeHTML($text);

  # Ask for revert affirmation and password. If reversion date is valid.
  if ($finished)
  { $input   = '<input type="hidden" name="text" value="'.$text.'">'.$nl.
               '<input type="hidden" name="summary" value="revert">';
    $form    = BuildPostForm($title_url.'&amp;action=write&amp;t=page', $input);
    $content = '<p>Revert page to before '.$time_string.'?</p>'.$nl.$form; }
  else 
    ErrorFail('No valid reversion date given.');

  # Final HTML.
  Output_HTML('Reverting: '.$title, $content); }

####################################
# Page text manipulation functions #
####################################

function Markup($text)
# Applying markup functions in the order described by markups.txt to $text.
{ global $markup_list_path; 
  $lines = ReadAndTrimLines($markup_list_path);
  foreach ($lines as $line)
    $text = $line($text);
  return $text; }

function Sanitize($text)
# Remove $esc from text and magical_quotes horrors.
{ global $esc;
  if (get_magic_quotes_gpc())
    $text = stripslashes($text);
  $text = str_replace($esc, '', $text);
  return $text; }

function EscapeHTML($text)
# Replace symbols that might be confused for HTML markup with HTML entities.
{ $text = str_replace('&',  '&amp;',  $text);
  $text = str_replace('<',  '&lt;',   $text); 
  $text = str_replace('>',  '&gt;',   $text);
  $text = str_replace('\'', '&apos;', $text); 
  return  str_replace('"',  '&quot;', $text); }

###########################
#                         #
#   D B   W R I T I N G   #
#                         #
###########################

#################################
# User-accessible writing to DB #
#################################

function Action_write()
# User writing to DB. Expects password $_POST['pw'] and target type $_GET['t'],
# which determines the function shaping the details (like what to write where).
{ global $nl, $nl2, $root_rel, $todo_urgent; 
  $pw = $_POST['pw']; $auth = $_POST['auth']; $t = $_GET['t'];

  # Target type chooses writing preparation function, gets variables from it.
  $prep_func = 'PrepareWrite_'.$t;
  if (function_exists($prep_func))
    $x = $prep_func();
  else 
    ErrorFail('No known target type specified.');
  $redir = $x['redir']; $task_write_list = $x['tasks'];

  # Give a redir URL more harmless than a write action page if $redir is empty.
  if (empty($redir))
    $redir = $root_rel;

  # Password check.
  if (!CheckPW($auth, $pw, $t))
    ErrorFail('Authentication failure.');

  # From $task_write_list, add tasks to temp versions of todo lists.
  $temps = array();
  foreach ($task_write_list as $todo => $tasks)
  { $old_todo = '';
    if (is_file($todo))
      $old_todo = file_get_contents($todo);
    $temps[$todo] = NewTemp($old_todo);
    foreach ($tasks as $task)
      WriteTask($temps[$todo], $task[0], $task[1], $task[2]); }

  # Write from temp files to todo files. Make sure any $todo_urgent comes first.
  if ($temps[$todo_urgent])
    rename($temps[$todo_urgent], $todo_urgent);
  foreach ($temps as $todo => $content)
    if ($todo != $todo_urgent)
      rename($content, $todo);

  # Final HTML.
  WorkScreenReload($redir); }

function PrepareWrite_page()
# Deliver to Action_write() all information needed for page writing process.
{ global $diff_path, $esc, $hook_PrepareWrite_page, $nl, $page_path, $title,
         $title_url, $todo_urgent;
  $text = Sanitize($_POST['text']);

  # $old_text is for comparison and diff generation.
  $old_text = $esc;    # Code to PlomDiff() of $old_text having no lines at all.
  if (is_file($page_path))
    $old_text = file_get_contents($page_path);

  # Check for error conditions: $text empty or unchanged.
  if (!$text)         
    ErrorFail('Empty pages not allowed.', 
              'Replace text with "delete" if you want to delete the page.');
  elseif ($text == $old_text)            
    ErrorFail('You changed nothing!');

  $timestamp = time();

  # In case of page deletion question, add DeletePage() task to todo file.
  if ($text == 'delete')
  { if (is_file($page_path)) 
    $x['tasks'][$todo_urgent][] = array('DeletePage',array($title,$timestamp));}
  else
  { # Diff to previous version, add to diff file.
    $new_diff_id = 0;
    $author      = str_replace($nl, '', Sanitize($_POST['author'] ));
    $summary     = str_replace($nl, '', Sanitize($_POST['summary']));
    if (!$author)  $author  = 'Anonymous';
    if (!$summary) $summary = '?';
    $diff_add = PlomDiff($old_text, $text);
    if (is_file($diff_path))
    { $diff_old    = file_get_contents($diff_path);
      $diff_list   = DiffList($diff_path); 
      $old_diff_id = 0;
      foreach ($diff_list as $id => $diff_data)
        if ($id > $old_diff_id)
          $old_diff_id = $id;
      $new_diff_id = $old_diff_id + 1; }
    else
      $diff_old = '';
    $diff_new = $new_diff_id.$nl.$timestamp.$nl.$author.$nl.$summary.$nl.
                $diff_add.'%%'.$nl.$diff_old;
    
    # Actual writing tasks for Action_write().
    $x['tasks'][$todo_urgent][] = array('SafeWrite', 
                                         array($diff_path), array($diff_new));
    $x['tasks'][$todo_urgent][] = array('SafeWrite', 
                                         array($page_path), array($text)); }

  # Before leaving, execute plugin hook.
  $x['redir'] = $title_url;
  eval($hook_PrepareWrite_page);
  return $x; }

function PrepareWrite_admin_sets_pw()
# Deliver to Action_write() all information needed for pw writing process.
{ global $nl, $pw_path, $salt, $todo_urgent;

  # Check password key and new password for validity.
  $new_pw   = $_POST['new_pw'];
  $new_auth = $_POST['new_auth'];
  if (!$new_pw)
    ErrorFail('Empty password not allowed.');
  if (!$new_auth)
    ErrorFail('Not told what to set password for.');

  # Splice new password hash into text of password file at $pw_path.
  $passwords            = ReadPasswordList($pw_path);
  $passwords[$new_auth] = hash('sha512', $salt.$new_pw);
  $pw_file_text         = '';
  foreach ($passwords as $key => $pw)
    $pw_file_text .= $key.':'.$pw.$nl;

  # Actual writing tasks for Action_write().
  $x['tasks'][$todo_urgent][] = array('SafeWrite',
                                         array($pw_path), array($pw_file_text));
  return $x; }

#############
# Passwords #
#############

function Action_set_pw_admin()
# Display page for setting new admin password.
{ ChangePW_form('admin', '*', 'Old admin'); }

function ChangePW_form($desc_new_pw, $new_auth, $desc_pw = 'Admin', 
                       $auth = '*', $t = 'admin_sets_pw')
# Output page for changing password keyed to $auth and described by $desc.
{ global $nl, $nl2, $title_url;
  $input = 'New password for '.$desc_new_pw.':<br />'.$nl.
           '<input type="hidden" name="new_auth" value="'.$new_auth.'">'.$nl
          .'<input type="password" name="new_pw" /><br />'.$nl.
           '<input type="hidden" name="auth" value="'.$auth.'">'.$nl.
           $desc_pw.' password :<br />'.$nl.
           '<input type="password" name="pw">';
  $form = BuildPostForm($title_url.'&amp;action=write&amp;t='.$t, $input, '');
  Output_HTML('Changing password for '.$desc_new_pw, $form); }

function CheckPW($key, $pw_posted, $target)
# Check if hash of $pw_posted fits $key password hash in internal password list.
{ global $permissions, $pw_path, $salt, $work_failed_logins_dir;
  $passwords   = ReadPasswordList($pw_path);
  $salted_hash = hash('sha512', $salt.$pw_posted);
  $return      = FALSE;
  $ip_file      = $work_failed_logins_dir.$_SERVER['REMOTE_ADDR'];
 
  # Let IPs that recently failed a login wait $delay seconds before next try.
  $delay = 10;
  if (is_file($ip_file))
  { $birth = file_get_contents($ip_file);
    while ($birth + $delay > time())
      sleep(1); }
  file_put_contents($ip_file, time());

  # Fail if empty $key provided.
  if (!$key)
    return $return;

  # Fail if $key is not authorized for target. Assume admin always authorized.
  if ($key != '*' and !in_array($key, $permissions[$target]))
      return $return;

  # Try positive authentication. If successful, delete IP form failed logins.
  if (isset($passwords[$key])
      and $salted_hash == $passwords[$key])
  { $return = TRUE;
    unlink($ip_file); }

  return $return; }

function ReadPasswordList($path)
# Read password list from $path into array.
{ global $legal_title, $nl;
  $content = substr(file_get_contents($path), 0, -1);

  # Trigger error if password file is not found / empty.
  if (!$content)
    ErrorFail('No valid password file found.');

  # Build $passwords list from file's $content.
  $passwords = array();
  $lines = explode($nl, $content);
  foreach ($lines as $line)
  { 
    # Allowed password keys: '*', pagenames and any "_"-preceded [a-z_] chars.
    preg_match('/^(\*|_[a-z_]+|'.$legal_title.'):(.+)$/', $line, $catch);
    $range = $catch[1];
    $pw    = $catch[2];
    $passwords[$range] = $pw; } 

  return $passwords; }

############################
# Internal DB manipulation #
############################

function WriteTask($todo, $func, $values_easy = array(), $values_hard = array())
# Write call to function $func into new $todo line, with $values_easy (use for
# short, sans-dangerous-chars strings) passed as parameters directly, strings of
# $values_hard only passed as paths to newly created temp files storing them. 
{ global $nl;
  $p_todo = fopen($todo, 'a+');

  # Write $values_hard into temp files, add their paths to $values_easy.
  if (!empty($values_hard))
    foreach ($values_hard as $value_hard)
      $values_easy[] = NewTemp($value_hard);

  # Write eval()-executable function call with $func and $values_easy.
  if (!empty($values_easy))
    $values_easy = '\''.implode('\', \'', $values_easy).'\'';
  $line = $func.'('.$values_easy.');'.$nl;
  fwrite($p_todo, $line);
  fclose($p_todo); }

function NewTemp($string)
# Put $string into new temp file in $work_temp_dir, return its path.
{ global $work_temp_dir;

  # Lock dir so its filename list won't change via something else during this.
  Lock($work_temp_dir);
  $p_dir = opendir($work_temp_dir);

  # Collect numerical filenames of temp files in $tempfiles.
  $tempfiles = array(0);
  while (FALSE !== ($fn = readdir($p_dir))) 
    if (preg_match('/^[0-9]*$/', $fn))
      $tempfiles[] = $fn;

  # Build new highest-number $temp_path, write $string into it.
  $new_max_int = max($tempfiles) + 1;
  $temp_path = $work_temp_dir.$new_max_int;
  file_put_contents($temp_path, $string);

  # As our change to $work_temp_dir's filename list is finished, unlock dir.
  closedir($p_dir);
  UnLock($work_temp_dir);
  return $temp_path; }

function Lock($path)
# Check for and create lockfile for $path. Locks block $lock_dur seconds max.
{ global $max_exec_time;
  $lock_dur = 2 * $max_exec_time;
  $now      = time();
  $lock     = $path.'_lock';

  # Fail if $lock file exists already and is too young. Else, write new $lock.
  if (is_file($lock))
  { $time = file_get_contents($lock);
    if ($time + $lock_dur > $now)
      ErrorFail('Stuck by a lockfile of too recent a timestamp.',
                'Lock effective for '.$lock_dur.' seconds. Try a bit later.'); }
  file_put_contents($lock, $now); }

function UnLock($path)
# Unlock $path.
{ $lock = $path.'_lock';
  unlink($lock); }

function WorkTodo($todo, $do_reload = FALSE)
# Work through todo file. Comment out finished lines. Delete file when finished.
{ global $max_exec_time, $nl, $now;

  if (is_file($todo))
  { 
    # Lock todo file while working on it.
    Lock($todo);
    $p_todo = fopen($todo, 'r+');

    # Work through todo file until stopped by EOF or time limit.
    $limit_dur = $max_exec_time / 2;
    $limit_pos = $now + $limit_dur;
    $stop_by_time = FALSE;
    while (!feof($p_todo))
    { if (time() >= $limit_pos)
      { $stop_by_time = TRUE;
        break; }
    
      # Eval lines not commented out. Comment out lines worked through.
      $pos  = ftell($p_todo);
      $line = fgets($p_todo);
      if ($line[0] !== '#')
      { $call = substr($line, 0, -1);
        eval($call);
        fseek($p_todo, $pos);
        fwrite($p_todo, '#');
        fgets($p_todo); } }

    # Delete file if stopped by EOF. In any case, unlock it.
    fclose($p_todo);
    if (!$stop_by_time) 
      unlink($todo);
    UnLock($todo); }

  # Reload.
  if ($do_reload)
    WorkScreenReload(); }

function DeletePage($title, $timestamp)
# Rename, timestamp page $title and its diff. Move both files to $del_dir.
{ global $del_dir, $diff_dir, $pages_dir;
  $page_path     = $pages_dir.$title;
  $diff_path     = $diff_dir .$title;
  $path_diff_del = $del_dir.$title.',del-diff-'.$timestamp;
  $path_page_del = $del_dir.$title.',del-page-'.$timestamp;

  if (is_file($diff_path)) rename($diff_path, $path_diff_del);
  if (is_file($page_path)) rename($page_path, $path_page_del); }

function SafeWrite($path_original, $path_temp)
# Avoid data corruption: Exit if no temp file. Rename, don't overwrite directly.
{ if (!is_file($path_temp))    return;
  if (is_file($path_original)) unlink($path_original); 
  rename($path_temp, $path_original); }

###############
#             #
#   D I F F   #
#             #
###############

function PlomDiff($text_A, $text_B)
# Output diff $text_A -> $text_B.
{ global $esc, $nl;

  # Transform $text_{A,B} into arrays of lines with empty line 0 appended.
  $lines_A = explode($nl, $text_A);     $lines_B = explode($nl, $text_B); 
  array_unshift($lines_A, $esc);        array_unshift($lines_B, $esc);
  $lines_A[] = $esc;                    $lines_B[] = $esc;

  # Fill $equals with all possible equalities between blocks of lines.
  $equals = array();
  foreach ($lines_A as $n_A => $line_A)
    foreach ($lines_B as $n_B => $line_B)
      if ($line_A === $line_B)
      { $ln = 1;
        for ($i = $n_A + 1; $i < count($lines_A); $i++)
          if ($lines_A[$n_A + $ln] === $lines_B[$n_B + $ln]) $ln++;
          else                                               break;
        $equals[] = array($n_A, $n_B, $ln); }

  # Fill $equal with largest-possible line-block equalities.
  $equal = array();
  while (!empty($equals))
  { $max_equal = PlomDiff_LargestThirdElement($equals);
    $max_n_A = $max_equal[0]; $max_n_B = $max_equal[1]; $max_ln = $max_equal[2];
    $equal[] = $max_equal;

    # Eliminate from / trunacate $equals blocks intersecting current $max_equal.
    foreach ($equals as $n_equal => $arr)
    { $n_A = $arr[0]; $n_B = $arr[1]; $ln = $arr[2]; 

      # Unset $equals block if it starts inside $max_equal block.
      if (   $max_n_A <= $n_A and $n_A < $max_n_A + $max_ln
          or $max_n_B <= $n_B and $n_B < $max_n_B + $max_ln)
        unset($equals[$n_equal]);

      # Else, truncate $equals block to end where $max_equal block starts.
      else
      { for ($i = $n_A + 1; $i < $n_A + $ln; $i++)
          if ($i == $max_n_A)
          { $equals[$n_equal][2] = $i - $n_A;
            break; }
        for ($i = $n_B + 1; $i < $n_B + $ln; $i++)
          if ($i == $max_n_B)
          { $equals[$n_equal][2] = $i - $n_B;
            break; } } } }

  # Rid $equal of out-of-order blocks. Prefer keeping large blocks. Sort $equal.
  $equal_unsorted = PlomDiff_PutIntoOrderByLargest($equal);
  $n_As = array();
  foreach ($equal_unsorted as $arr)
    $n_As[] = $arr[0];
  sort($n_As);
  foreach ($n_As as $n_A)
  { foreach ($equal_unsorted as $arr)
      if ($arr[0] == $n_A)
      { $equal_sorted[] = $arr; 
        break; } }

  # Build diff by inverting $equal.
  foreach ($equal_sorted as $n => $arr)
  { if ($n == count($equal_sorted) - 1)                # Last diff element would
      break;                                           # be garbage, ignore.
    $n_A = $arr[0]; $n_B = $arr[1]; $ln = $arr[2];
    $arr_next = $equal_sorted[$n + 1];
    $offset_A = $n_A + $ln;           $offset_B = $n_B + $ln;
    $n_A_next = $arr_next[0] - 1;     $n_B_next = $arr_next[1] - 1;
    $txt_A = $txt_B = '';
    if ($offset_A == $n_A_next + 1)
    { $char = 'a';
      $A = $offset_A - 1;
      list($B, $txt_A) = PlomDiff_RangeLines($lines_B,$offset_B,$n_B_next,'>');}
    elseif ($offset_B == $n_B_next + 1)
    { $char = 'd';
      $B = $offset_B - 1;
      list($A, $txt_B) = PlomDiff_RangeLines($lines_A,$offset_A,$n_A_next,'<');}
    else
    { $char = 'c'; 
      list($A, $txt_A) = PlomDiff_RangeLines($lines_A,$offset_A,$n_A_next,'<');
      list($B, $txt_B) = PlomDiff_RangeLines($lines_B,$offset_B,$n_B_next,'>');}
    $diffs .= $A.$char.$B.$txt_A.$txt_B.$nl; }

  return $diffs; }

function PlomDiff_RangeLines($lines, $offset, $n_next, $prefix)
# Output list of diff $range string and diff lines $txt.
{ global $nl;
  if ($offset == $n_next) $range = $offset;
  else                    $range = $offset.','.$n_next;
  foreach ($lines as $n => $line)
    if ($offset <= $n and $n <= $n_next)
      $txt .= $nl.$prefix.$line;
  return array($range, $txt); }

function PlomDiff_PutIntoOrderByLargest($arr, $return = FALSE)
# Reduce lines-blocks list $arr to one-way direction, try to keep large blocks.
{ $loc_max  = PlomDiff_LargestThirdElement($arr);
  $return[] = $loc_max;
  $before   = PlomDiff_ReturnOrderedBeforeOrAfter($arr, $loc_max, TRUE);
  $after    = PlomDiff_ReturnOrderedBeforeOrAfter($arr, $loc_max, FALSE); 
  if ($before) $return = array_merge($before, $return);
  if ($after ) $return = array_merge($after,  $return);
  return $return; }

function PlomDiff_ReturnOrderedBeforeOrAfter($arr_arr, $arr_max, $before = TRUE)
# Return $arr_arr arrays left/right of $arr_max, directed in one way only.
{ $return = FALSE;
  foreach ($arr_arr as $arr)
  { if (   ( $before and $arr[0] < $arr_max[0] and $arr[1] < $arr_max[1])
        or (!$before and $arr[0] > $arr_max[0] and $arr[1] > $arr_max[1]))  
      $legit[] = $arr; }
  if (!empty($legit))
    $return = PlomDiff_PutIntoOrderByLargest($legit, $return);
  return $return; }

function PlomDiff_LargestThirdElement($arr_arr)
# Return of array $arr_arr array with the largest value in its third field.
{ $max = array(NULL, NULL, 0);
  foreach ($arr_arr as $arr)
  { $a = $arr[0]; $b = $arr[1]; $c = $arr[2];
    if ($c > $max[2])
      $max = array($a, $b, $c); }
  return $max; }

function PlomPatch($text_A, $diff)
# Patch $text_A to $text_B via $diff.
{ global $nl;
 
  # Explode $diff into $patch_tmp = array($action_tmp => array($line, ...), ...)
  $patch_lines = explode($nl, $diff);
  $patch_tmp = array(); $action_tmp = '';
  foreach ($patch_lines as $line)
  { if ($line[0] != '<' and $line[0] != '>') $action_tmp = $line;
    else                                     $patch_tmp[$action_tmp][] = $line;}

  # Collect add/delete lines info (split 'c' into both) from $patch_tmp into
  # $patch = array($start.'a' => array($line, ...), $start.'d' => $end, ...)
  $patch = array();
  foreach ($patch_tmp as $action_tmp => $lines)
  { if     (strpos($action_tmp, 'd'))
           { list($left, $ignore) = explode('d', $action_tmp);
             if (!strpos($left, ',')) 
               $left = $left.','.$left;
             list($start, $end) = explode(',', $left);
             $action = 'd'.$start; $patch[$action] = $end; }
    elseif (strpos($action_tmp, 'a'))
           { list($start, $ignore) = explode('a', $action_tmp);
             $action = 'a'.$start; $patch[$action] = $lines; }
    elseif (strpos($action_tmp, 'c'))
           { list($left, $right) = explode('c', $action_tmp);
             if (!strpos($left, ','))
               $left = $left.','.$left;
             list($start, $end) = explode(',', $left);
             $action         = 'd'.$start;
             $patch[$action] = $end;
             $action         = 'a'.$start; 
             foreach ($lines as $line) if ($line[0] == '>')
               $patch[$action][] = $line; } }

  # Create $lines_{A,B} arrays where key equals line number. Add temp 0-th line.
  $lines_A = explode($nl, $text_A); 
  foreach ($lines_A as $key => $line)
    $lines_A[$key + 1] = $line.$nl;
  $lines_A[0] = ''; $lines_B = $lines_A;

  foreach ($patch as $action => $value)
  { # Glue new lines to $lines_B[$apply_after_line] with $nl.
    if     ($action[0] == 'a')
           { $apply_after_line = substr($action, 1);
             foreach ($value as $line_diff)
               $lines_B[$apply_after_line] .= substr($line_diff, 1).$nl; }
    # Cut deleted lines' lengths from $lines_B[$apply_from_line:$until_line].
    elseif ($action[0] == 'd')
           { $apply_from_line = substr($action, 1);
             $until_line = $value;
             for ($i = $apply_from_line; $i <= $until_line; $i++) 
             { $end_of_original_line = strlen($lines_A[$i]);
               $lines_B[$i] = substr($lines_B[$i], $end_of_original_line); } } }

  # Before returning, remove potential superfluous $nl at $text_B end.
  $text_B = implode($lines_B);
  if (substr($text_B,-1) == $nl)
    $text_B = substr($text_B,0,-1);
  return $text_B; }

function ReverseDiff($old_diff)
# Reverse a diff.
{ global $nl;

  $old_diff = explode($nl, $old_diff);
  $new_diff = '';
  foreach ($old_diff as $line_n => $line)
  { if     ($line[0] == '<') $line[0] = '>'; 
    elseif ($line[0] == '>') $line[0] = '<';
    else 
    { foreach (array('c' => 'c', 'a' => 'd', 'd' => 'a') as $char => $reverse) 
      { if (strpos($line, $char))
        { list($left, $right) = explode($char, $line); 
          $line = $right.$reverse.$left; break; } } }
    $new_diff .= $line.$nl; }
  return $new_diff; }

function DiffList($diff_path)
# Build, return page-specific diff list from file text at $diff_path.
{ global $nl;
  $diff_list = array();

  # Remove superfluous "%%" and $nl from start and end of $file_txt.
  $file_txt = file_get_contents($diff_path);
  if (substr($file_txt,0,2) == '%%'    ) $file_txt = substr($file_txt,3);
  if (substr($file_txt, -3) == '%%'.$nl) $file_txt = substr($file_txt,0,-3);
  if (substr($file_txt, -2) == '%%'    ) $file_txt = substr($file_txt,0,-2);
  if (substr($file_txt, -1) == $nl     ) $file_txt = substr($file_txt,0,-1);

  if ($file_txt != '')
  # Break $file_txt into separate $diff_txt's. Remove superfluous trailing $nl.
  { $diffs = explode('%%'.$nl, $file_txt);
    foreach ($diffs as $diff_n => $diff_txt)
    { if (substr($diff_txt, -1) == $nl)
        $diff_txt = substr($diff_txt, 0, -1);

      # Harvest diff data / metadata from $diff_txt into $diff_list[$id].
      $diff_lines = explode($nl, $diff_txt);
      $diff_txt_new = array();
      foreach ($diff_lines as $line_n => $line) 
        if     ($line_n == 0) $id                        = $line;
        elseif ($line_n == 1) $diff_list[$id]['time']    = $line;
        elseif ($line_n == 2) $diff_list[$id]['author']  = $line;
        elseif ($line_n == 3) $diff_list[$id]['summary'] = $line;
        else                  $diff_txt_new[] = $line;
      $diff_list[$id]['text'] = implode($nl, $diff_txt_new); } }

  return $diff_list; }

###################################################
#                                                 #
#   M I N O R   H E L P E R   F U N C T I O N S   #
#                                                 #
###################################################

#########
# Input #
#########

function ReadAndTrimLines($path)
# Read file $path into a list of all lines sans comments and ending whitespaces.
{ global $nl;

  $lines = explode($nl, file_get_contents($path));
  $list = array(); 
  foreach ($lines as $line)
  { $hash_pos = strpos($line, '#');
    if ($hash_pos !== FALSE)
      $line = substr($line, 0, $hash_pos);
    $line = rtrim($line);
    if ($line)
      $list[] = $line; } 
  return $list; }

function GetPageTitle($legal_title, $fallback = 'Start')
# Only allow alphanumeric titles plus -. If title is empty, assume $fallback.
{ $title = $_GET['title']; 
  if (!$title) $title = $fallback;
  if (!preg_match('/^'.$legal_title.'$/', $title)) 
    ErrorFail('Illegal page title.', 
              'Only alphanumeric characters and "-" allowed'); 
 return $title; }

function GetUserAction($fallback = 'Action_page_view')
# Find appropriate code for user's '?action='. Assume $fallback if not found.
{ $action          = $_GET['action'];
  $action_function = 'Action_'.$action;
  if (!function_exists($action_function)) 
    $action_function = $fallback;
  return $action_function; }

##########
# Output #
##########

function ErrorFail($msg, $help = '')
# Fail and output error $msg. $help may provide additional helpful advice.
{ global $nl;
  $text = '<p><strong>'.$msg.'</strong></p>'.$nl.'<p>'.$help.'</p>';
  Output_HTML('Error', $text); 
  exit(); }

function Output_HTML($title_h, $content, $head = '')
# Generate final HTML output from given parameters and global variables.
{ global $action, $actions_meta, $actions_page, $nl, $nl2, $root_rel, $title, 
                                                                     $title_url;

  # If we have more $head lines, append a newline for better code readability.
  if ($head) $head .= $nl;

  # Generate header / action bars.
  $header_wiki = '<p>'.$nl.'PlomWiki: '.$nl.
                 ActionBarLinks($actions_meta, $root_rel).'</p>'.$nl2;
  if (substr($action, 7, 5) == 'page_')
    $header_page = $nl.'<p>'.$nl.ActionBarLinks($actions_page, $title_url).
                                                                     '</p>'.$nl;
  # Final HTML.
  echo '<title>'.$title_h.'</title>'.$nl.$head.$nl.
       $header_wiki.'<h1>'.$title_h.'</h1>'.$nl.$header_page.$nl.$content; }

function ActionBarLinks($array_actions, $root)
# Build a HTML line of action links from $array_actions over $root.
{ global $nl;
  foreach ($array_actions as $action)
    $links .= '<a href="'.$root.$action[1].'">'.$action[0].'</a> '.$nl;
  return $links; }

function WorkScreenReload($redir = '')
# Just output the HTML of a work message and instantly redirect to $redirect.
{ global $nl;
  if (!empty($redir))
    $redir = '; URL='.$redir;
  echo '<title>Working</title>'.$nl.
       '<meta http-equiv="refresh" content="0'.$redir.'" />'.$nl.
       '<p>Working.</p>'; 
  exit(); }

function BuildPostForm($URL, $input, $ask_pw = NULL)
# HTML form. $URL = action, $input = code between, $ask_pw = PW input element.
{ global $nl;
  if ($ask_pw === NULL)
    $ask_pw = 'Admin password: <input name="pw" type="password" />'.
                              '<input name="auth" type="hidden" value="*" />';
  return '<form method="post" action="'.$URL.'">'.$nl.$input.$nl.$ask_pw.$nl.
         '<input type="submit" value="OK" />'.$nl.'</form>'; }
