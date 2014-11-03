<?php
/**
 * Auto-conflict detector
 *
 * Runs as daemon that checks a queue directory for the
 * payload of GitHub push notifications, then detects 
 * conflicts with all other branches in repository caused
 * by said pushes. Finally, sends a message to HipChat
 * if conflicts exist.
 */

include 'src/Git.php';
include 'src/Hipchat.php';
// define global settings
$hipchat_room_id = '';
$hipchat_token = '';
$hipchat_name = '';
$ignore_branches = '';
$queue_directory = '.queue';
$cache_directory = '.cache';
$log_directory = '.logs';
$log_file=$log_directory.'/git.log';


function process_file($file_name)
{

	// parse JSON
	try {
		$payload = json_decode(file_get_contents($file_name));
	}
	catch (Exception $e)
	{
		// delete the file so we don't process it again
		unlink($file_name);
		die('Invalid request payload in file '.$file_name);
	}

	// INIT
	$repo_url = 'git@github.com:'.$payload->repository->owner->name.'/'.$payload->repository->name.'.git';
	$repo_dir = $GLOBALS['cache_directory'].'/'.$payload->repository->name;
	$git      = new Git($repo_dir);
	$chat     = new Hipchat($GLOBALS['hipchat_token']);

	// Make sure to chop down 'refs/heads/'
	$subject_branch = $payload->ref;
	$branch_parts = explode('/', $subject_branch);
	array_shift($branch_parts);
	array_shift($branch_parts);
	$subject_branch = implode('/', $branch_parts);

	// Do not process deletions
	if ($payload->deleted) die();

	// SETUP
	if ( ! is_dir($repo_dir))
	{
		file_put_contents($GLOBALS['log_file'], "Cmd: git clone $repo_url $repo_dir\n", FILE_APPEND);
		$git->execute("clone $repo_url ".escapeshellcmd($repo_dir));
	} else {
		file_put_contents($GLOBALS['log_file'], "Cmd: git fetch --prune\n", FILE_APPEND);
		$git->execute('fetch --prune');
	}

	// Detect remote branches
	$branches = $git->execute('for-each-ref refs/remotes/ --format=\'%(refname:short)\'');
	$branches = explode("\n", $branches);
	file_put_contents($GLOBALS['log_file'], "\nBranches:".implode(', ', $branches)."\n", FILE_APPEND);

	// Should we ignore certain branches?
	$ignore = array();
	if (array_key_exists('ignore_branches', $GLOBALS))
	{
		$ignore = explode(',', $GLOBALS['ignore_branches']);
	}

	$failures = [];
	foreach ($branches as $branch)
	{
		// Pull out remote name from branch ref
		$branch_parts = explode('/', $branch);
		$remote_name  = array_shift($branch_parts);
		$branch       = implode('/', $branch_parts);

		// Skip HEAD and empty strings
		if (empty($branch) || $branch === 'HEAD') continue;

		// Skip subject branch
		if ($branch == $subject_branch) continue;

		// Skip blacklisted branches
		if (in_array($branch, $ignore)) continue;
		
		file_put_contents($GLOBALS['log_file'], "\nBRANCH: $branch REMOTE: $remote_name/$branch\n", FILE_APPEND);

		try
		{
			// Clean previous local branch if exists
			file_put_contents($GLOBALS['log_file'], "Cmd: git branch -D $branch\n", FILE_APPEND);
			$git->execute("branch -D $branch");
		}
		catch (Exception $e)
		{
		}

		file_put_contents($GLOBALS['log_file'], "Cmd: git clean -f -d\n", FILE_APPEND);
		$git->execute("clean -f -d");

		file_put_contents($GLOBALS['log_file'], "Cmd: git checkout -b $branch $remote_name/$branch\n", FILE_APPEND);
		$git->execute("checkout -b $branch $remote_name/$branch");

		try
		{
			file_put_contents($GLOBALS['log_file'], 'Cmd: git pull origin '.escapeshellcmd($subject_branch)."\n", FILE_APPEND);
			$status = $git->execute('pull origin '.escapeshellcmd($subject_branch));
		}
		catch (Exception $e)
		{
			$failures[] = $branch;
		}

		file_put_contents($GLOBALS['log_file'], 'reset --hard origin/develop'."\n", FILE_APPEND);
		$git->execute('reset --hard origin/develop');
	}

	if ($failures)
	{
		// There could be multiple commits with multiple authors
		$ops = [];
		$commit_msgs = [];
		foreach ($payload->commits as $commit)
		{
			$ops[] = $commit->author->name;
			$commit_msgs[] = $commit->message;
		}
		$ops     = array_unique($ops);
		$pusher  = $payload->pusher->name;
		$commits = implode(', ', $commit_msgs);
		$commits = strlen($commits) > 30 ? substr($commits, 0, 29).'...' : $commits;

		$msg = '<strong>'.$pusher.'</strong> - Your latest pushes `<strong>'.$commits.'</strong>` is conflicting with the following branches: <strong>'.implode(', ', $failures).'</strong>';

		$chat->message_room($GLOBALS['hipchat_room_id'], $GLOBALS['hipchat_name'], $msg, TRUE, Hipchat::COLOR_RED);
	}
}

function load_and_validate_settings()
{
	$global_settings = parse_ini_file("settings.ini", TRUE);
	$hipchat_room_id = $global_settings['hipchat']['room_id'];
	$hipchat_token = $global_settings['hipchat']['token'];
	$hipchat_name = $global_settings['hipchat']['name'];
	if (array_key_exists('git', $global_settings))
	{
		$ignore_branches = $global_settings['git']['ignore_branches'];
	}
}

function main()
{
	load_and_validate_settings();

	$file_list = scandir($GLOBALS['queue_directory']);
	foreach ($file_list as $file) {
		$file_name = $GLOBALS['queue_directory']."/".$file;
		if (is_dir($file_name))
		{
			continue;
		} else {
			process_file($file_name);
		}

	}
}

main();
// The end.

