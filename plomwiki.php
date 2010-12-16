<?php

##################
# Initialization #
##################

# Filesystem information.
$config_dir = 'config/';         $markup_list_path = $config_dir.'markups.txt';
$plugin_dir = 'plugins/';        $pw_path          = $config_dir.'password.txt';
$pages_dir  = 'pages/';          $plugin_list_path = $config_dir.'plugins.txt';
$diff_dir   = $pages_dir.'diffs/';     $work_dir      = 'work/';
$del_dir    = $pages_dir.'deleted/';   $work_temp_dir = $work_dir.'temp/';
$setup_file = 'setup.php';             $todo_urgent   = $work_dir.'todo_urgent';

# URL information.
$root_rel = 'plomwiki.php';      $title_root = $root_rel.'?title=';

# Newline information. PlomWiki likes "\n", dislikes "\r".
$nl = "\n";                      $nl2 = $nl.$nl;                    $esc = "\r";

# Check for unfinished setup file, execute if found.
if (is_file($setup_file)) require($setup_file);

# Default action bar links data.
$actions_meta = array(array('Jump to Start page', '?title=Start'),
                      array('Set admin password', '?action=set_pw_admin'));
$actions_page = array(array('View',               '&amp;action=view'),
                      array('Edit',               '&amp;action=edit'),
                      array('History',            '&amp;action=history'),
                      array('Set page password',  '&amp;action=set_pw_page'));

# Insert plugins' code.
$lines = ReadAndTrimLines($plugin_list_path); 
foreach ($lines as $line) require($line);

# Only allow alphanumeric titles. If title is needed, but empty, assume "Start".
$title       = $_GET['title']; 
$legal_title = '[a-zA-Z0-9]+';
if (!$title) 
  $title = 'Start';
if (!preg_match('/^'.$legal_title.'$/', $title)) 
{ $text = '<h1>Error</h1>'.$nl2.
          '<p><strong>Illegal page title.</strong><br />'.$nl.
          ' Only alphanumeric characters allowed</p>';
    Output_HTML('Error', $text);
  exit(); }
$page_path = $pages_dir .$title; 
$diff_path = $diff_dir  .$title;
$title_url = $title_root.$title;

# Build action / navigation bars.
$bar_start       = '<p>PlomWiki: ';
$wiki_view_start = BuildActionBar($actions_meta, $root_rel,   $bar_start);
$bar_start       = '<h1>'.$title.'</h1>'.$nl.'<p>';
$page_view_start = BuildActionBar($actions_page, $title_root, $bar_start);

# Find appropriate code for user's '?action='. Assume "view" if not found.
$fallback        = 'Action_view';
$action          = $_GET['action'];
$action_function = 'Action_'.$action;
if (!function_exists($action_function)) 
  $action_function = $fallback;

# Before executing user's action, do urgent work if urgent todo file is found.
WorkToDo($todo_urgent);
$action_function();

#######################
# Common page actions #
#######################

function Action_view()
# Formatted display of a page.
{ global $page_path, $title, $title_url;
  
  # Get text from file. If none, show invitation to create one. Else, markup it.
  if (is_file($page_path)) 
  { $text = file_get_contents($page_path); 
    $text = EscapeHTML($text); 
    $text = Markup($text); }
  else $text = 'Page does not exist. <a href="'.$title_url.'&amp;action=edit">'.
                                                                  'Create?</a>';

  # Final HTML.
  Output_HTML($title, $text, TRUE); }

function Action_edit()
# Edit form on a page source text. Send results to ?action=write.
{ global $markup_help, $nl, $nl2, $page_path, $title, $title_url;

  # If no page file is found, start with an empty $text.
  if (is_file($page_path)) 
  { $text = file_get_contents($page_path); 
    $text = EscapeHTML($text); }
  else $text = '';

  # Final HTML of edit form and JavaScript to localStorage password.
  $title_h = 'Editing: '.$title;
  $form = '<h2>Edit page</h2>'.$nl2.
          '<form method="post" action="'.$title_url.
                                          '&amp;action=write&amp;t=page">'.$nl.
          '<pre><textarea name="text" rows="20" style="width:100%">'.$nl.
          $text.'</textarea></pre>'.$nl.
          'Password: <input id="password" type="password" name="pw" /> '.
                                  '<input type="submit" value="Update!" />'.$nl.
          '</form>'.$nl2.$markup_help.$nl2.'<script>'.$nl.
          'if (window.localStorage)'.$nl.
          '{ var pw_input = document.getElementById(\'password\');'.$nl2.
          '  if (localStorage.pw != null)'.$nl.
          '  { pw_input.value = localStorage.pw; }'.$nl2.
          '  pw_input.addEventListener('.$nl.'    \'keyup\', '.$nl.
          '    function() { localStorage.pw = pw_input.value; },'.$nl.
          '    false); }'.$nl.'</script>';
   Output_HTML($title_h, $form, TRUE); }

function Action_history()
# Show version history of page (based on its diff file), offer reverting.
{ global $diff_path, $nl, $nl2, $title, $title_url;

  # Check for non-empty diff file on page. Remove superfluous "%%" and $nl.
  $text = '<p>Page "'.$title.'" has no history.</p>';
  $diff_all = '';
  if (is_file($diff_path))
  { $diff_all = file_get_contents($diff_path);
    if (substr($diff_all,0,2) == '%%'    ) $diff_all = substr($diff_all,3);
    if (substr($diff_all, -3) == '%%'.$nl) $diff_all = substr($diff_all,0,-3);
    if (substr($diff_all, -2) == '%%'    ) $diff_all = substr($diff_all,0,-2);
    if (substr($diff_all, -1) == $nl     ) $diff_all = substr($diff_all,0,-1); }
  if ($diff_all != '')

  # Transform $diff_all into structured HTML output. Add revert-by-time hooks.
  { $diffs = explode('%%'.$nl, $diff_all);
    foreach ($diffs as $diff_n => $diff_str)
    { if (substr($diff_str, -1) == $nl)      # Last element's ending $nl isn't
        $diff_str = substr($diff_str, 0, -1); # needed, would trigger explode()
      $diff = explode($nl, $diff_str);       # to an empty final element.
      $time = '';
      foreach ($diff as $line_n => $line) 
      { if ($line_n == 0) 
        { $time = $line;
          $diff[$line_n] = '<p>'.date('Y-m-d H:i:s', (int) $time).' (<a href="'.
                           $title_url.'&amp;action=revert&amp;time='.$time.'">'.
                           'revert</a>):</p>'.$nl.'<div class="diff">'; }
        else
        { if     ($line[0] == '>') 
            $diff[$line_n] = '+ '.substr($diff[$line_n], 1);
          elseif ($line[0] == '<') 
            $diff[$line_n] = '- '.substr($diff[$line_n], 1);
          $diff[$line_n] = '<pre>'.EscapeHTML($diff[$line_n]).'</pre>'; } }
      $diff_output = implode($nl, $diff);
      $diffs[$diff_n] = $diff_output.$nl.'</div>'.$nl; }
    $text = '<h2>Diff history of page</h2>'.$nl2.implode($nl, $diffs); }

  # Final HTML.
  $title_h = 'Diff history of: '.$title;
  $css = '<style type="text/css">'.$nl.
         'pre'.$nl.'{ white-space: pre-wrap;'.$nl.'  text-indent:-12pt;'.$nl.
         '  margin-top:0px;'.$nl.'  margin-bottom:0px; }'.$nl2.'.diff '.$nl.
         '{ margin-left:12pt; }'.$nl.'</style>';
  Output_HTML($title_h, $text, TRUE, $css); }

function Action_revert()
# Prepare version reversion and ask user for confirmation.
{ global $diff_path, $nl, $nl2, $title, $title_url, $page_path;
  $time        = $_GET['time'];
  $time_string = date('Y-m-d H:i:s', (int) $time);

  # Build $diff_array from $diff_path to be cycled through, keyed by timestamps.
  $diff_array = array();
  $diffs_text = explode('%%'.$nl, file_get_contents($diff_path));
  foreach ($diffs_text as $diff_n => $diff_str)
  { $diff = explode($nl, $diff_str);
    $diff_text = '';
    $id = 0;
    foreach ($diff as $line_n => $line) 
    { if ($line_n == 0 and $line !== '') 
        $id = $line;
      else                               
        $diff_text .= $line.$nl; }
    if ($id > 0) 
      $diff_array[$id] = $diff_text; }

  # Revert $text back through $diff_array until $time hits $id.
  $text     = file_get_contents($page_path);
  $finished = FALSE;
  foreach ($diff_array as $id => $diff)
  { if ($finished) break;
    $reversed_diff = ReverseDiff($diff); 
    $text          = PlomPatch($text, $reversed_diff);
    if ($time == $id) 
      $finished = TRUE; }
  $text = EscapeHTML($text);

  # Ask for revert affirmation and password. If reversion date is valid.
  if ($finished)
  { $content = '<h2>Revert</h2>'.$nl2.
               'Revert page to before '.$time_string.'?</p>'.$nl.
               '<form method="post" action="'.$title_url.
                                           '&amp;action=write&amp;t=page">'.$nl.
               '<input type="hidden" name="text" value="'.$text.'">'.$nl.
               'Password: <input type="password" name="pw" />'.$nl.
               '<input type="submit" value="Revert!" />'.$nl.'</form>'; }
  else 
    $content = 'Error. No valid reversion date given.</p>';

  # Final HTML.
  $title_h = 'Reverting: '.$title; 
  Output_HTML($title_h, $content, TRUE); }

#################################
# User-accessible writing to DB #
#################################

function Action_write()
# Only way to write to DB. Expect password $_POST['pw'], target type $_GET['t'].
{ global $nl, $nl2, $todo_urgent; 
  $pw = $_POST['pw']; $t = $_GET['t'];

  # Get any writing-relevant variables from $x, built by function chosen by $t.
  if     ($t == 'page') $x = PreparePageWrite();
  elseif ($t == 'pw')   $x = PreparePasswordWrite();
  $fail=$x['fail']; $msg=$x['msg']; $hook=$x['hook']; $is_page=$x['is_page'];
  $tasks=$x['tasks']; $todo=$x['todo']; $temps=$x['temps']; $time=$x['time'];

  # Failure conditions: No target type $t. Positive $mistake. Wrong password.
  $title_h  = 'Error.';
  if (!$fail)
    if (!$t or ($t != 'page' and $t != 'pw'))
    { $fail = TRUE;
      $msg = '<h2>Error</h2>'.$nl2.
             '<p><strong>No known target type specified.</strong></p>'; }
    elseif (!CheckPW($pw, $t))
    { $fail = TRUE;
      $msg = '<h2>Error</h2>'.$nl2.
             '<p><strong>Wrong password.</strong></p>'; }

  # If writing can go through, start by opening hooks for redirect and plugins.
  if (!$fail)
  { $title_h = 'Writing';
    $p_todo = fopen($todo, 'a+');
    $redir = $x['redir'];
    eval($hook);

    # Write temp files, tasks into todo file. Expect well-formed $task content.
    if ($temps) foreach ($temps as $n => $temp)
        $temp_paths[$n] = NewTempFile($temp);
    foreach ($tasks as $n => $task_start)
    { $temp_path = '';
      if ($temp_paths[$n])
        $temp_path = $temp_paths[$n];
      fwrite($p_todo, $task_start.$temp_path.'");'.$nl); }
    fclose($p_todo);

    # If todo is urgent, why not start right away?
    if ($todo == $todo_urgent)
      WorkToDo($todo_urgent); }

  # Final HTML.
  Output_HTML($title_h, $msg, $is_page, $redir); }

function PreparePageWrite()
# Deliver to Action_write() all information needed for page writing process.
{ global $diff_path, $esc, $hook_page_write, $nl, $page_path, $title,
         $title_url, $todo_urgent;
  $text = $_POST['text'];

  # All the variables easily filled.
  $x['redir'] = '<meta http-equiv="refresh" content="0; URL='.$title_url.'" />';
  $x['is_page'] = TRUE;
  $x['todo']    = $todo_urgent;
  $x['msg']     = '<p><strong>Page updated.</strong></p>';
  $x['hook']    = $hook_page_write;
  $x['time']    = $timestamp = time();

  # Repair problems in submitted text. Undo possible PHP magical_quotes horrors.
  if (get_magic_quotes_gpc()) $text = stripslashes($text); 
  $text = NormalizeNewlines($text);

  # $old_text is for comparison and diff generation.
  $old_text   = $esc;  # Code to PlomDiff() of $old_text having no lines at all.
  if (is_file($page_path))
    $old_text = file_get_contents($page_path);

  # Check for error conditions: $text empty or unchanged.
  $x['fail'] = TRUE;
  if (!$text)         
    $x['msg'] = '<p><strong>Empty pages not allowed.</strong> Replace page text'
               .' with "delete" if you want to eradicate the page.</p>';
  elseif ($text == $old_text)            
    $x['msg'] = '<p><strong>You changed nothing!</strong></p>';
  else
    $x['fail'] = FALSE;

  if (!$x['fail'])
    # In case of page deletion question, add DeletePage() task to todo file.
    if ($text == 'delete')
    { if (is_file($page_path)) 
      $x['tasks'][] = 'DeletePage("'.$page_path.'", "'.$title;
      $msg = '<p><strong>Page "'.$title.'" is deleted</strong> (if it ever '.
                                                              'existed).</p>'; }

    else
    { # Diff to previous version, add to diff file.
      $diff_add = PlomDiff($old_text, $text);
      if (is_file($diff_path)) $diff_old = file_get_contents($diff_path);
      else                     $diff_old = '';
      $diff_new = $timestamp.$nl.$diff_add.'%%'.$nl.$diff_old;

      # Actual writing tasks for Action_write(). Notice key number parallelisms.
      $x['temps'][] = $diff_new;
      $x['tasks'][] = 'SafeWrite("'.$diff_path.'", "'; 
      $x['temps'][] = $text;
      $x['tasks'][] = 'SafeWrite("'.$page_path.'", "'; }

  return $x; }

function PreparePasswordWrite()
# Deliver to Action_write() all information needed for pw writing process.
{ global $nl, $pw_path, $todo_urgent;
  
  # Check password key and new password for validity.
  $pw_key = $_POST['pw_key'];
  $new_pw = $_POST['new_pw'];
  if (!$new_pw)
  { $x['fail'] = TRUE;
    $x['msg']  = '<p><strong>Empty password not allowed.</strong></p>'; }
  elseif (!$pw_key)
  { $x['fail'] = TRUE;
    $x['msg']  = '<p><strong>Not told what to set password for.</strong></p>'; }

  if (!$x['fail'])
  { $x['msg']  = '<p><strong>Password updated.</strong></p>';
    $x['todo'] = $todo_urgent;

    # Splice new password into text of password file at $pw_path.
    $passwords = ReadPasswordList($pw_path);
    $passwords[$pw_key] = $new_pw;
    $pw_file_text = '';
    foreach ($passwords as $key => $pw)
      $pw_file_text .= $key.':'.$pw.$nl;

    # Actual writing tasks for Action_write().
    $x['temps'][] = $pw_file_text;
    $x['tasks'][] = 'SafeWrite("'.$pw_path.'", "'; }

  return $x; }

#############
# Passwords #
#############

function Action_set_pw_admin()
# Display page for setting new admin password.
{ BuildPageChangePW('admin', '*'); }

function Action_set_pw_page()
# Display page for setting new page password.
{ global $title;
  BuildPageChangePW('page', $title, TRUE); }

function BuildPageChangePW($desc, $pw_key, $is_page = FALSE)
# Build HTML output for $desc password change form.
{ global $nl, $nl2, $title_url;
  $h = 1;
  if ($is_page) $h = 2;
  $title_h = 'Set '.$desc.' password';
  $form = '<h'.$h.'>'.$title_h.'</h'.$h.'>'.$nl2.
          '<form method="post" action="'.$title_url.'&amp;action=write&amp;t='.
                                                              'pw">'.$nl.
          '<input type="hidden" name="pw_key" value="'.$pw_key.'">'.$nl.
          'New '.$desc.' password:<br />'.$nl.
         ' <input type="password" name="new_pw" /><br />'.$nl.
          'Current admin password:<br />'.$nl.
          ' <input type="password" name="pw" />'.$nl.
          '<input type="submit" value="Update!" />'.$nl.
          '</form>';
  Output_HTML($title_h, $form, $is_page); }

function CheckPW($pw_posted, $t = '')
# Compare $pw_posted to admin password stored in $pw_path.
{ global $pw_path, $title;
  $passwords = ReadPasswordList($pw_path);

  # Return with success of checking $pw_posted against admin or $title password.
  if ($pw_posted === $passwords['*']
      or ($t == 'page' and $pw_posted === $passwords[$title]))
    return TRUE;
  return FALSE; }

function ReadPasswordList($path)
# Read password list from $path into array.
{ global $legal_title, $nl;
  $content = substr(file_get_contents($path), 0, -1);

  # Trigger error if password file is not found / empty.
  if (!$content)
  { $title_h = 'Error';
    $text = '<h1>Error.</h1>'.$nl.
            '<p><strong>No valid password file found.</strong></p>';
    Output_HTML($title_h, $text);
    exit(); }

  # Build $passwords list from file's $content.
  $passwords = array();
  $lines = explode($nl, $content);
  foreach ($lines as $line)
  { preg_match('/^(\*|'.$legal_title.'):(.+)$/', $line, $catch);
    $range = $catch[1];
    $pw    = $catch[2];
    $passwords[$range] = $pw; } 

  return $passwords; }

######################################
# Internal DB manipulation functions #
######################################

function WorkToDo($path_todo)
# Work through todo file. Comment-out finished lines. Delete file when finished.
{ global $work_dir; 

  if (file_exists($path_todo))
  { LockOn($work_dir); 
    $p_todo = fopen($path_todo, 'r+');
    while (!feof($p_todo))
    { $position = ftell($p_todo);             
      $line     = fgets($p_todo);
      if ($line[0] !== '#')
      { $call = substr($line, 0, -1);
        eval($call);
        fseek($p_todo, $position);
        fwrite($p_todo, '#');
        fgets($p_todo); } }
    fclose($p_todo);
    unlink($path_todo);
    LockOff($work_dir); } }

function NewTempFile($string)
# Put $string into new $work_temp_dir temp file.
{ global $work_temp_dir;

  LockOn($work_temp_dir);
  $p_dir = opendir($work_temp_dir);
  $temps = array(0);
  while (FALSE !== ($fn = readdir($p_dir))) 
    if (preg_match('/^[0-9]*$/', $fn)) $temps[] = $fn;
  $int = max($temps) + 1; 
  $temp_path = $work_temp_dir.$int;
  file_put_contents($temp_path, $string);
  closedir($p_dir);
  LockOff($work_temp_dir); 
  return $temp_path; }

function LockOn($dir)
# Check for and create lockfile for $dir. Lockfiling runs out after $max_time.
{ $lock_duration = 60;   # Lockfile duration. Should be > server execution time.
  $now = time();
  $lock = $dir.'lock';
  if (is_file($lock))
  { $time = file_get_contents($lock);
    if ($time + $lock_duration > $now)
    { echo 'Lockfile found, timestamp too recent. Try again later.'; 
      exit(); } }
  file_put_contents($lock, $now); }

function LockOff($dir)
# Unlock $dir.
{ unlink($dir.'lock'); }

function DeletePage($page_path, $title) 
# Deletion renames and timestamps a page and its diff and moves it to $del_dir.
{ global $pages_dir, $diff_dir, $del_dir;

  $timestamp = time();
  $deleted_page_path = $del_dir.$title.',del-page-'.$timestamp;
  $diff_path = $diff_dir.$title;
  $deleted_diff_path = $del_dir.$title.',del-diff-'.$timestamp;
  if (is_file($diff_path)) rename($diff_path, $deleted_diff_path);
  if (is_file($page_path)) rename($page_path, $deleted_page_path); }

function SafeWrite($path_original, $path_temp)
# Avoid data corruption: Exit if no temp file. Rename, don't overwrite directly.
{ if (!is_file($path_temp)) 
    return;
  if (is_file($path_original)) 
    unlink($path_original); 
  rename($path_temp, $path_original); }

########
# Diff #
########

function PlomDiff($text_A, $text_B)
# Output diff $text_A -> $text_B.
{ global $esc, $nl;

  # Pad $lines_A and $lines_B to same length, add one empty line at end. Start
  # line counting in $lines_A and $lines_B at 1, not 0 -- just like diff does.
  $lines_A_tmp   = explode($nl, $text_A);  
  $lines_B_tmp   = explode($nl, $text_B);
  $original_ln_A = count($lines_A_tmp);     # Will be needed further below, too.
  if ($text_A == $esc)        # $text = $esc is our code for $text containing no
    $original_ln_A = 0;       # lines at all (instead of one single empty line).
  $new_ln        = max($original_ln_A, count($lines_B_tmp)) + 1;
  $lines_A_tmp   = array_pad($lines_A_tmp, $new_ln, $esc);
  $lines_B_tmp   = array_pad($lines_B_tmp, $new_ln, $esc);
  foreach ($lines_A_tmp as $k => $line) $lines_A[$k + 1] = $line;
  foreach ($lines_B_tmp as $k => $line) $lines_B[$k + 1] = $line;

  # Collect adds / dels from line mismatches between $lines_{A,B} into $diff.
  # add pattern: $diff[$before_in]['a'] = array($in_first, $in_last)
  # del pattern: $diff[$out_first]['d'] = array($before_out, $out_last)
  $diff = array(); 
  $offset = 0;
  foreach ($lines_A as $key_A => $line_A)
  { 
    # $offset in $lines_B grows/shrinks for each line added/deleted.
    $key_B = $key_A + $offset; $line_B = $lines_B[$key_B];
   
    if ($line_A !== $line_B)
    { # Find matching line in later lines of $lines_B. If successful, mark lines
      # range in-between as lines added and add its length $range to $offset.
      $lines_B_later = array_slice($lines_B, $key_B, NULL, TRUE);
      $range = 0; 
      foreach ($lines_B_later as $key_B_sub => $line_B_sub)
      { $range++;
        if ($line_A == $line_B_sub)
        { $diff[$key_A - 1]['a'] = array($key_B, $key_B + $range - 1);
          $offset += $range;
          break; } }
      
      # If mismatch is unredeemed by matching later lines, mark line as deleted
      # -- except for (temporarily added $esc) lines beyond $original_ln_A.
      if (!$diff[$key_A - 1]['a'] and $key_A <= $original_ln_A)
      { $diff[$key_A]['d'] = array($key_B - 1, $key_A);
        $offset--; } } }
  
  # Combine subsequent single line dels to line del blocks by, for each del,
  # checking if $old_del's $out_last is just one line before new $out_first.
  $old_del = array(NULL, NULL, -1);  # = array($out_first,$before_out,$out_last)
  foreach ($diff as $line_n => $info)
  { foreach ($info as $char => $limits) if ($char == 'd')
    { $new_out_last = $limits [1]; 
      $old_out_last = $old_del[2];
      if ($line_n - 1 == $old_out_last)
      { $old_out_first  = $old_del[0]; 
        $old_before_out = $old_del[1];
        $diff[$old_out_first]['d'] = array($old_before_out, $new_out_last);
        unset($diff[$line_n]['d']);
        $old_del = array($old_out_first, $old_before_out, $new_out_last); }
      else 
      { $new_start_in_B = $limits[0]; 
        $old_del = array($line_n, $new_start_in_B, $new_out_last); } } }
  
  # Combine 'a' and 'd' to 'c' in cases where they meet.
  # 'c' pattern: $diff[$out_first] = array($out_last, $in_first, $in_last);
  foreach ($diff as $line_n => $info)
  { if ($diff[$line_n]['d'])
    { $out_last = $diff[$line_n]['d'][1];
      if ($diff[$out_last]['a'])
      { $in_first = $diff[$out_last]['a'][0]; 
        $in_last  = $diff[$out_last]['a'][1];
        $diff[$line_n]['c'] = array($out_last, $in_first, $in_last);
        unset($diff[$line_n]['d']); unset($diff[$out_last]['a']); } } }

  # Output diff into $string and return.
  $string = '';
  foreach ($diff as $line_n => $info)
  { foreach ($info as $char => $limits)
    { if ($char == 'a') 
      { if ($limits[0] == $limits[1]) $string .= $line_n.$char.$limits[0].$nl;
        else                          $string .= $line_n.$char.$limits[0].','.
                                                                $limits[1].$nl;
        for ($i = $limits[0]; $i <= $limits[1]; $i++)
          $string .= '>'.$lines_B[$i].$nl; }
      elseif ($char == 'd')
      { if ($line_n    == $limits[1]) $string .= $line_n.$char.$limits[0].$nl;
        else                          $string .= $line_n.','.$limits[1].$char.
                                                                $limits[0].$nl;
        for ($i = $line_n; $i <= $limits[1]; $i++)
          $string .= '<'.$lines_A[$i].$nl; }
      elseif ($char == 'c')
      { if ($line_n    == $limits[0]) $string .= $line_n.$char;
        else                          $string .= $line_n.','.$limits[0].$char;
        if ($limits[1] == $limits[2]) $string .= $limits[1].$nl;
        else                          $string .= $limits[1].','.$limits[2].$nl;
        for ($i = $line_n; $i <= $limits[0]; $i++)
          $string .= '<'.$lines_A[$i].$nl;
        for ($i = $limits[1]; $i <= $limits[2]; $i++)
          $string .= '>'.$lines_B[$i].$nl; } } }
  return $string; }

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

function NormalizeNewlines($text)
# Allow $nl newline only. $esc stripped from user input is free for other uses.
{ global $esc;
  return str_replace($esc, '', $text); }

function EscapeHTML($text)
# Replace symbols that might be confused for HTML markup with HTML entities.
{ $text = str_replace('&',  '&amp;',  $text);
  $text = str_replace('<',  '&lt;',   $text); 
  $text = str_replace('>',  '&gt;',   $text);
  $text = str_replace('\'', '&apos;', $text); 
  return  str_replace('"',  '&quot;', $text); }

##########################
# Minor helper functions #
##########################

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

function Output_HTML($title, $content, $page_view = FALSE, $head = '')
# Combine all provided HTML snippets to the final HTML output.
{ global $nl, $wiki_view_start, $page_view_start;

  if (!$page_view) $page_view_start = '';
  if ($head)       $head .= $nl;

  echo '<!DOCTYPE html>'.$nl.'<meta charset="UTF-8">'.$nl.
       '<title>'.$title.'</title>'.$nl.
       $head.$nl.$wiki_view_start.$page_view_start.$content; }

function BuildActionBar($array_actions, $root, $bar_start)
# Concatenate $bar_start, $array_actions and $root into HTML of an action bar.
{ global $nl, $nl2;

  foreach ($array_actions as $action)
    $action_bar .= '<a href="'.$root.$action[1].'">'.$action[0].'</a> '.$nl;

  return $bar_start.$nl.$action_bar.'</p>'.$nl2; }
