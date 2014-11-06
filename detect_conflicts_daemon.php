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
$log_file = $log_directory.'/git.log';
$maximum_branches_to_check = 1000;
$git_to_hipchat_name = array();

function log_message($message)
{
	print($message);
	file_put_contents($GLOBALS['log_file'], $message, FILE_APPEND);
}

function call_git($git, $command)
{
	log_message("Cmd: git ".$command."\n");
	return $git->execute($command);
}

function process_file($file_name)
{

	// parse JSON
	$payload = json_decode(file_get_contents($file_name));

	// Do not process deletions
	if ($payload->deleted)
	{
		log_message("Ignoring deletion commit\n");
		return;
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

	// SETUP
	if ( ! is_dir($repo_dir))
	{
		call_git($git, "clone $repo_url ".escapeshellcmd($repo_dir));
	} else {

		// cleanup any changes that might have been left over if we crashed while running
		call_git($git, 'reset --hard origin');
		call_git($git, "clean -f -d");

		// move to the master branch
		call_git($git, "checkout master");

		// remove all local branches
		call_git($git, 'branch -D `git branch | grep -v \* | xargs`');

		// remove branches that no longer exist on origin and update all branches that do
		call_git($git, "fetch --prune --all");
	}

	// Detect remote branches
	$branches = call_git($git, 'for-each-ref refs/remotes/ --format=\'%(refname:short)\'');
	$branches = explode("\n", $branches);
	log_message("\nBranches:".implode(', ', $branches)."\n");

	// Should we ignore certain branches?
	$ignore = array();
	if (array_key_exists('ignore_branches', $GLOBALS))
	{
		$ignore = explode(',', $GLOBALS['ignore_branches']);
	}

	$failures = [];
	$branches_checked=0;
	foreach ($branches as $branch)
	{
		if ($branches_checked > $GLOBALS['maximum_branches_to_check'])
		{
			break;
		}
		$branches_checked = $branches_checked + 1;

		// Pull out remote name from branch ref
		$branch_parts = explode('/', $branch);
		$remote_name  = array_shift($branch_parts);
		$branch       = implode('/', $branch_parts);

		// Skip HEAD and empty strings
		if (empty($branch) || $branch === 'HEAD') continue;

		// Skip subject branch
		if ($branch == $subject_branch) continue;

		// Skip blacklisted branches
		if (in_array($branch, $ignore))
		{
			log_message("\nSkipping $branch it is in ignore list\n");
			continue;
		}
		
		log_message("\nBRANCH: $branch REMOTE: $remote_name/$branch\n");

		call_git($git, "checkout $remote_name/$branch");

		try
		{
			$status = call_git($git, 'pull origin '.escapeshellcmd($subject_branch));
		}
		catch (Exception $e)
		{
			log_message("BRANCH: $branch conflicts with $subject_branch\n");
			$failures[] = $branch;
		}

		call_git($git, 'reset --hard origin/'.escapeshellcmd($subject_branch));
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

		$hipchat_mention = '@all';
		if (array_key_exists('git_to_hipchat_name',$GLOBALS))
		{
			if (array_key_exists($pusher,$GLOBALS['git_to_hipchat_name']))
			{
				$hipchat_mention = "@".$GLOBALS['git_to_hipchat_name'][$pusher];
			}
		}

		$msg = $hipchat_mention.' Branch "'.$subject_branch.'" is conflicting with the following branches: "'.implode(', ', $failures).'"';
		log_message($msg."\n");
		$chat->message_room($GLOBALS['hipchat_room_id'], $GLOBALS['hipchat_name'], $msg, TRUE, Hipchat::COLOR_RED, Hipchat::FORMAT_TEXT);
	}
}

function load_and_validate_settings()
{
	$settings = parse_ini_file("settings.ini", TRUE);
	$GLOBALS['hipchat_room_id'] = $settings['hipchat']['room_id'];
	$GLOBALS['hipchat_token'] = $settings['hipchat']['token'];
	$GLOBALS['hipchat_name'] = $settings['hipchat']['name'];
	if (array_key_exists('git', $settings))
	{
		$GLOBALS['ignore_branches'] = $settings['git']['ignore_branches'];
		$GLOBALS['maximum_branches_to_check'] = $settings['git']['maximum_branches_to_check'];
	}
	if (array_key_exists('git_to_hipchat_name', $settings))
	{
		$GLOBALS['git_to_hipchat_name'] = $settings['git_to_hipchat_name'];
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
			try {
				process_file($file_name);
			}
			catch (Exception $e)
			{
				log_message('Failed to process '.$file_name.', error: '.$e->getMessage());
			}
			// delete the file so we don't process it again
			//unlink($file_name);
		}
		break;
	}
}

main();
// The end.

