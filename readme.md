# Restrictive Deploy

Deploying code automatically using BitBucket or GitHubs post service is a great value add. But there are times when you don't want those commits to trigger a repository update.  In my case I wanted a way to continue committing from multiple computers without dealing with branching or tagging, instead I wanted to control repo updates to those coming from me personally and in cases where I mention in the commit comment that things are "ready to go".  This could easily have been a one off script that pings a shell script but that wouldn't help anyone else or be flexible in the long run.  Calling this a **restrictive deploy** rather than secure one because deploying any website via a web based deploy script provides some inherent risk.  The restrictive policies that this script makes use of can be faked, but help overall to prevent -- not so much malicious intent but a 'crap I didn't want that live' event.  For a little more protection rename the script, hide it in a deep web accessible path and use https.

Other scripts I've seen either blindly allow any post to trigger a pull and update event, or at least look at some information in the payload JSON.  A few are creative in deploying without a repository by downloading files from a public repo, or upon URL hit; reach out and check the repository API's for changes.  A fine idea unless your repositories are private -- but I am considering some other creative ways to lock things down a bit more.  I've taken things a step further with this script, it checks payload information, pulls then checks the last commit to be darn sure it matches the deploy restrictions.

## Features
 * IP Permissions
 * Accepts payload post from GitHub and Bitbucket but not required
 * Works with GIT or HG
 * Restrict repo pull to payload author, branch or commit message text based on payload announcement
 * Also restricts repo update to payload author or commit message text from inside the repo tip
 * Restrict repo update to specific branch or tags
 * Daisy chain $_POST back out to multiple URL's before or after an acceptable pull request with changes

## Requirements
 * Linux - commands use chmod/grep/cut (Hg works in Windows)
 * Public key authentication for repo pull
 * Sudo access to set things up
 * Good knowledge of permissions

## Hg setup

Couple of special configurations are needed to let the web server update the code base.  The following are the errors you will encounter and the solutions.

 * remote: Host key verification failed

Solution - Add your private key and a known_hosts file to the web server home folder.  Depending on your setup the home folder might be in a different location, the setup might be like the following.
```
mkdir /var/www/.ssh
cp id_rsa /var/www/.ssh
cp known_hosts /var/www/.ssh
```

 * Not trusting file ..../.hg/hgrc from untrusted user johndoe, group staff

Solution - Create a .hgrc file for the web server user in it's home directory.  Replace my_user_... with the user and group of the web server.
```
vi /var/www/.hgrc
[trusted]
users = my_user_name
groups = my_user_group
```

 * abort: could not lock repository ...: Permission denied

Solution - repository files are not owned by the web server, chmown the directory.

## Script Examples

The most basic example, if the deploy script sits in same folder as repository, no security checks but a posted payload is required.
```
$deploy = new Deploy();
$deploy->execute();
```

Same but limit the post trigger to an ip address; code includes allow policies for bitbucket and git.
```
$deploy = new Deploy();
$deploy->allow_ip('ip');
$deploy->execute();
```

Limit pulls to commits by a particular author, call another URL if pull was successful.
```
$deploy = new Deploy();
$deploy->allow_ip('ip');
$deploy->allow_by_author('johndoe');
$deploy->deploy_post_after('localhost/rotate_scripts.php');
$deploy->execute();
```

Update only when a user commits with a message "ready to deploy" and email someone when it occurs
```
$deploy = new Deploy();
$deploy->allow_ip('ip');
$deploy->allow_by_author('johndoe');
$deploy->allow_by_last_commit('ready to deploy');
$deploy->email_result('johndoe@super.net');
$deploy->execute();
```

Allow code to pull when a commit is applied to a branch.  Only updates to that branch.
```
$deploy = new Deploy();
$deploy->allow_by_branch_commit('newfeature');
$deploy->deploy_to_branch('johndoe');
$deploy->execute();
```

