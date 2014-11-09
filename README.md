# Auto-conflict detector
----
> (c) 2014 cielo24 Inc., cielo24.com
> github.com/cielo24/git-conflict-detector

Runs as a webpage that listens to GitHub hook requests and alerts an HipChat room if a conflict has detected with other branches in the repository.

## Setup

  - Setup a PHP webserver vhost
  - Clone files into vhost root dir
  - Generate an SSH key pair in the webserver's user dir, eg. /var/www/.ssh (See section below)
  - Add the public key you generated into the repository's GitHub "SSH Keys"
  - Configure GitHub web hook:
    - http://www.yourwebserver/detect_conflicts.php
    - Choose:
      - application/x-www-form-encoded
      - Just push the event
  - Create directories and grant write permissions (chmod 750)
    - `/var/www/.logs`
    - `/var/www/.cache`
    - `/var/www/.queue`
  - Copy ./etc/init.d/conflict-detector to /etc/init.d and make is executable
  - Modify paths in /etc/init.d/conflict-detector as necessary
  - Create `settings.ini` to meet your needs

## Settings.ini

  - [hipchat]
    - room_id: The HipChat API id of the room you want to post into
    - token: The HipChat API token
    - name: The name of the "user" posting in the room

  - [git]
    - ignore_branches: Comma separated list of branches that should not be checked for conflicts
    - maximum_branches_to_check: The maximum number of branches to check, 0 for unlimited

  - [git_to_hipchat_name]
    - Map of GitHub user names and HipChat user names. Will @mention the HipChat user that pushed the commit. If no match is found in this map, then will mention @all.
    - GitHubUserName: HipChatUserName

## GitHub authentication

  - For GitHub authentication through `git` command-line this script requires the webserver's user having an SSH key created in `.ssh`
  - An easy way to test:
    - Switch users to webserver's user
    - Generate key pair: `ssh-keygen -t rsa`
    - `ssh git@github.com`
    - You should see: "Hi foo! You've successfully authenticated"

## License

Modifications copyright (c) 2014 cielo24 Inc.
Original code copyright (c) 2010-2013 Sortex Systems Development Ltd.

This software is provided 'as-is', without any express or implied
warranty. In no event will the authors be held liable for any damages
arising from the use of this software.

Permission is granted to anyone to use this software for any purpose,
including commercial applications, and to alter it and redistribute it
freely, subject to the following restrictions:

   1. The origin of this software must not be misrepresented; you must not
   claim that you wrote the original software. If you use this software
   in a product, an acknowledgment in the product documentation would be
   appreciated but is not required.

   2. Altered source versions must be plainly marked as such, and must not be
   misrepresented as being the original software.

   3. This notice may not be removed or altered from any source
   distribution.