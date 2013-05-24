<?php

/*
 * Restrictive-Deploy
 *
 * @author Damien Burns <mechjagger@gmail.com>
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 * @link http://github.com/mechjagger/Restrictive-Deploy
 * @version 0.8
 */

/*
 * If wanting to use the same script for multiple repositories
 * wrap the object in a logic block where the repository name is
 * searched for in the $_POST['payload'] variable
 */
$deploy = new Deploy();

/* Set the path to the repository we will be updating, unless we are already sitting in the root folder */
$deploy->set_path('../dev/');

/* Set the paths for log files this is based on the above set_path location */
$deploy->set_log('../deploy.log');
$deploy->set_ip_log('../deploy_ip.log');

/* Not required, just adds a ------ spacer to the log file when passed nothing */
$deploy->log('');

/* Limit connections to script from Bitbucket */
$deploy->allow_ip('63.246.22.222');

/* Limit connections to script from GitHub */
$deploy->allow_ip('207.97.227.253');
$deploy->allow_ip('50.57.128.197');
$deploy->allow_ip('108.171.174.178');

/* Allow any access to script to cause pull and update by disabling the payload requirement */
//$deploy->payload_not_required();

/* If utilizing POST service, limit updates to particular authors, branches or commit messages */
//$deploy->allow_by_author('JDoe');
//$deploy->allow_by_branch_commit('newfeature');
$deploy->allow_by_last_commit('deploy ready');
$deploy->allow_by_author('mechjagger');
$deploy->allow_by_author('dburns');

/* Deploy to a branch, this should be used in combination with allow_by_branch_commit */
//$deploy->deploy_to_branch('newfeature');

/* Deploy to a tag */
//$deploy->deploy_to_tag('stable');

/*
 * Daisy chain the POST payload to another url, the end url doesn't have to use the post body.
 * End url could be used for logging purposes, notification, etc
 * This occurs before the code update but after a successful pull
 */

//$deploy->post_before('url');
//$deploy->post_after('url');

/* Send emails when a successful update occurs */
//$deploy->email_result('johndoe_at_email.com');

/* Execute the deployment */
$deploy->execute();

/* Not required, use for debugging*/
echo $deploy->result();



class Deploy
{
    // log file name
    private $log_file = 'deploy.log';
    private $ip_log   = 'deploy_ip.log';
    private $debug;

    // default requirement is a posted payload from BB or GH
    private $payload_required = TRUE;

    // permissions for script/repo updating
    private $allowed_ips      = array();
    private $allowed_authors  = array();
    private $allowed_commits  = array();
    private $allowed_branches = array();

    // specific tags/branches to deploy
    private $deploy_tag;
    private $deploy_branch;

    // daisy chain hooks
    private $post_before     = array();
    private $post_after      = array();
    private $post_using_curl = FALSE;

    // email notification
    private $emails;

    // internally determined variables
    private $type;
    private $path;
    private $payload;
    private $last_result;

    // payload determined variables
    private $name;
    private $author;
    private $branch;
    private $message;
    private $timestamp;

    // Linux shell commands
    private $repo = array(
        'hg'  => array(
            'pre'           => '',
            'pull'          => 'hg pull 2>&1', // pulls all branches, output stderr for debugging setups
            'update'        => 'hg update -C ',
            'update_branch' => 'hg update -C ', // append string when used
            'update_tag'    => 'hg update -r ', // append string when used
            'post'          => '',
            'get_tag'       => 'hg id -t',  // not used
            'get_branch'    => 'hg id -b',  // not used
            'get_revision'  => 'hg id --debug -i',
            'get_author'    => 'hg log -r tip | grep "user: "', // need to have it so can look at more than tip
                // could replace tip with result from get_branch or get_tap
            'get_commit'    => 'hg log -r tip | grep "summary: "'
        ),
        'git' => array(
            'pre'           => 'git reset --hard HEAD',
            'pull'          => 'git pull 2>&1', // pulls only current branch
            'update'        => 'git update -C ',
            'update_branch' => 'git update ', // append string when used
            'update_tag'    => 'git update ', // append string when used
            'post'          => 'chmod -R og-rx .git',
            'get_tag'       => 'git rev-parse --abbrev-ref HEAD',  // not used
            'get_branch'    => 'git branch | grep "*" | cut -f2 -d" "',  // not used
            'get_revision'  => 'git rev-parse HEAD',
            'get_author'    => 'git log -1 | grep "Author: "',
            'get_commit'    => 'git log -1 ' // not limiting to 4th line
        )
    );

    /*
     * If running on a windows box, commands above are replaced with these below
     *
     * This will work for HG out of the box but not for GIT
     * No easy way to test all the different ways GIT could be installed on windows or where things are
     * if going to use git update accordingly to your setup.
     */
    private $repo_windows = array(
        'hg'  => array(
            'pre'           => '',
            'pull'          => 'hg pull',
            'update'        => 'hg update -C ',
            'update_branch' => 'hg update -C ',
            'update_tag'    => 'hg update -r ',
            'post'          => '',
            'get_tag'       => 'hg id -t',
            'get_branch'    => 'hg id -b',
            'get_revision'  => 'hg id --debug -i',
            'get_author'    => 'hg log -r tip | FINDSTR user: ',
            'get_commit'    => 'hg log -r tip | FINDSTR "summary: "'
        ),
        'git' => array(
            'pre'           => 'git reset --hard HEAD',
            'pull'          => 'git pull',
            'update'        => 'git update -C ',
            'update_branch' => 'git update ',
            'update_tag'    => 'git update ',
            'post'          => 'chmod -R og-rx .git',
            'get_tag'       => 'git rev-parse --abbrev-ref HEAD',
            'get_branch'    => 'git branch | grep "*" | cut -f2 -d" "',
            'get_revision'  => 'git rev-parse HEAD',
            'get_author'    => 'git log -1 | grep "Author: "',
            'get_commit'    => 'git log -1 '
        )
    );

    public function __construct($path = FALSE)
    {
        // default path
        $this->path = realpath(dirname(__FILE__)).'/';

        // process payload post if available
        if (isset($_POST['payload']))
        {
            $this->payload = json_decode($_POST['payload']);

            if (is_object($this->payload))
            {
                $this->name      = (isset($this->payload->repository->name) ?
                    strtolower($this->payload->repository->name) : FALSE);
                $this->branch    = (isset($this->payload->commits[0]->branch) ?
                    strtolower($this->payload->commits[0]->branch) : FALSE);
                $this->timestamp = (isset($this->payload->commits[0]->timestamp) ?
                    $this->payload->commits[0]->timestamp : FALSE);
                $this->author    = (isset($this->payload->commits[0]->author) ?
                    strtolower($this->payload->commits[0]->author) : FALSE);
                $this->message    = (isset($this->payload->commits[0]->message) ?
                    strtolower($this->payload->commits[0]->message) : FALSE);
            }
        }

        // if running on windows replace the command set
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN')
        {
            $this->repo = $this->repo_windows;
        }
    }

    /**
     * Set the log path, probably a good idea to hide behind public directory, or prevent access
     *
     * @param $path
     */
    public function set_log($path)
    {
        $this->log_file = $path;
    }

    /**
     * Set the ip log path, probably a good idea to hide behind public directory, or prevent access
     *
     * @param $path
     */
    public function set_ip_log($path)
    {
        $this->ip_log = $path;
    }

    /**
     * Set the operating path
     * @param $path
     *
     * @return bool
     */
    public function set_path($path)
    {
        if (!file_exists($path))
        {
            $this->log("Error: path not found");
            return FALSE;
        }
        else
        {
            $this->path = $path;

            // move to correct folder
            chdir($this->path);

            return TRUE;
        }
    }

    /**
     * Determine the type of repository we are sitting in
     *
     * @return bool|string
     */
    public function determine_type()
    {
        if ($this->debug)
        {
            foreach (new DirectoryIterator($this->path) as $fn) {
                print $fn->getFilename()."\r\n";
            }
        }

        if (file_exists('.hg/')) {
            $this->type = 'hg';
        } elseif (file_exists('.git/')) {
            $this->type = 'git';
        } else {
            $this->type = FALSE;
        }

        return $this->type;
    }

    /**
     * Check if type is of git
     *
     * @return bool
     */
    public function is_git()
    {
        return ($this->type == 'git' ? TRUE : FALSE);
    }

    /**
     * Check if type is mercurial
     *
     * @return bool
     */
    public function is_hg()
    {
        return ($this->type == 'hg' ? TRUE : FALSE);
    }


    /************************************************************
     * Script access
     ***********************************************************/

    /**
     * Add allowed ip to script access
     *
     * @param $ip
     *
     * @return bool
     */
    public function allow_ip($ip)
    {
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            $this->allowed_ips[] = $ip;
        } else {
            $this->log("Bad IP address given");
        }

        return TRUE;
    }

    /**
     * Add allowed author for previous commit deployment
     *
     * @param $author
     *
     * @return bool
     */
    public function allow_by_author($author)
    {
        $this->allowed_authors[] = strtolower($author);

        return TRUE;
    }

    /**
     * Add allowed branch name for deployment,
     * prevent development branch/only allow stable, etc
     *
     * @param $branch
     *
     * @return bool
     */
    public function allow_by_branch_commit($branch)
    {
        $this->allowed_branches[] = strtolower($branch);

        return TRUE;
    }

    /**
     * Add allow by previous commit message contents,
     * something like "stable" or "deploy ready" are good deployment flags
     *
     * @param $commit
     *
     * @return bool
     */
    public function allow_by_last_commit($commit)
    {
        $this->allowed_commits[] = strtolower($commit);

        return TRUE;
    }

    /**
     * By default script requires a posted payload, a common function on the big repository sites
     * if you want the deployment to execute without a payload, call this method.
     *
     * @return bool
     */
    public function payload_not_required()
    {
        $this->payload_required = FALSE;

        return TRUE;
    }

    /**
     * Check incoming ip against the allow list
     *
     * @return bool
     */
    public function check_ip()
    {
        if (!isset($_SERVER['REMOTE_ADDR']))
        {
            $this->log('Notice: No REMOTE ADDR, probably running local ignoring.');
            return TRUE;
        }

        if (empty($this->allowed_ips)
            || isset($_SERVER['argv']) // okay if running from local cli
            || in_array($_SERVER['REMOTE_ADDR'], $this->allowed_ips))
        {
            return TRUE;
        }
        return FALSE;
    }

    /**
     * Verify the payload was passed and decoded
     *
     * @return bool
     */
    public function check_payload()
    {
        if (!$this->payload_required
            || is_object($this->payload))
        {
            return TRUE;
        }
        return FALSE;
    }

    /**
     * Check the last commit author in the payload before continuing
     *
     * @return bool
     */
    public function check_author()
    {
        if (!$this->payload_required
            || empty($this->allowed_authors)
            || in_array($this->author,$this->allowed_authors))
        {
            return TRUE;
        }

        return FALSE;
    }

    /**
     * Checked branch indicated in payload
     *
     * @return bool
     */
    public function check_branch()
    {
        if (!$this->payload_required
            || empty($this->allowed_branches)
            || in_array($this->branch,$this->allowed_branches))
        {
            return TRUE;
        }

        return FALSE;
    }

    /**
     * Check the commit message of the payload against the allowed commit message list
     *
     * @return bool
     */
    public function check_commit_message()
    {
        if (!$this->payload_required
            || empty($this->allowed_commits))
        {
            return TRUE;
        }
        elseif (is_array($this->allowed_commits))
        {
            foreach($this->allowed_commits as $commit_string)
            {
                if (stristr($this->message, $commit_string))
                {
                    return TRUE;
                }
            }
        }

        return FALSE;
    }

    /**
     * Check last commit for the author
     * @return bool
     */
    public function check_branch_author()
    {
        if (empty($this->allowed_authors))
        {
            return TRUE;
        }
        elseif (is_array($this->allowed_authors))
        {
            $last_author = $this->get_author();
            foreach($this->allowed_authors as $author)
            {
                if (stristr($last_author, $author))
                {
                   return TRUE;
                }
            }
            $this->log("Last commit by ".$last_author);
        }
        return FALSE;
    }

    /**
     * Check last commit for the comment/message
     * @return bool
     */
    public function check_branch_message()
    {
        if (empty($this->allowed_commits))
        {
            return TRUE;
        }
        elseif (is_array($this->allowed_commits))
        {
            $last_message = $this->get_commit();
            foreach($this->allowed_commits as $message)
            {
                if (stristr($last_message, $message))
                {
                    return TRUE;
                }
            }
        }
        return FALSE;
    }

    /**
     * Set the name of the tag to deploy too
     *
     * @param $tag
     */
    public function deploy_to_tag($tag)
    {
        $this->deploy_tag = $tag;
    }

    /**
     * Set the name of the branch to deploy
     *
     * @param $branch
     */
    public function deploy_to_branch($branch)
    {
        $this->deploy_branch = $branch;
    }


    /************************************************************
     *  Daisy chain post
     ***********************************************************/

    /**
     * Add url to a list of ones to pass payload too on execute_post_before() call
     *
     * @param $url
     *
     * @return bool
     */
    public function post_before($url)
    {
        $this->post_before[] = $url;
        return TRUE;
    }

    /**
     * Add url to a list of ones to pass payload too on execute_post_after() call
     *
     * @param $url
     *
     * @return bool
     */
    public function post_after($url)
    {
        $this->post_after[] = $url;
        return TRUE;
    }

    /**
     * Instead of using file stream to hit the URL's use CURL
     *
     * @param bool $bool
     *
     * @return bool
     */
    public function post_using_curl($bool = TRUE)
    {
        $this->post_using_curl = $bool;
        return TRUE;
    }

    /**
     * Trigger the BEFORE post
     * @return bool
     */
    public function execute_post_before()
    {
        $this->_execute_post($this->post_before);
        return TRUE;
    }

    /**
     * Trigger the AFTER post
     *
     * @return bool
     */
    public function execute_post_after()
    {
        $this->_execute_post($this->post_after);
        return TRUE;
    }

    /**
     * Run through list of URLs and post the payload to them.
     *
     * @param $post_urls
     *
     * @return bool
     */
    private function _execute_post($post_urls)
    {
        $_POST['daisy_time'] = date('U');

        if (!empty($post_urls))
        {
            if ($this->post_using_curl && !function_exists('curl_init'))
            {
                $this->log("Notice: curl not found, using fopen");
                $this->post_using_curl = FALSE;
            }

            foreach($post_urls as $url)
            {
                if (!empty($url))
                {
                    if ($this->post_using_curl)
                    {
                        $ch = curl_init();
                        curl_setopt($ch,CURLOPT_URL, $url);
                        curl_setopt($ch,CURLOPT_POST, count($_POST));
                        curl_setopt($ch,CURLOPT_POSTFIELDS, $_POST);
                        curl_exec($ch);
                        curl_close($ch);
                    }
                    else
                    {
                        $post = http_build_query($_POST);
                        $stream = stream_context_create(array('http'=>array('method'=>'POST','content'=>$post)));
                        $fp = @fopen($url, 'rb', FALSE, $stream);
                        if (is_resource($fp))
                        {
                            fclose($fp);
                        }
                    }
                }
            }
        }
        return TRUE;
    }

    /**
     * Add email to a list which gets emailed via email method
     *
     * @param $email
     *
     * @return bool
     */
    public function email_result($email)
    {
        if (empty($this->emails))
        {
            $this->emails = $email;
        }
        else
        {
            $this->emails .= ';' . $email;
        }
        return TRUE;
    }

    /**
     * Send email with message, just validates emails are provided
     * nothing fancy nancy
     *
     * @param $message
     *
     * @return bool
     */
    public function email($message)
    {
        if (!empty($this->emails))
        {
            mail($this->emails, 'Deploy Script', $message);
            return TRUE;
        }
        return FALSE;
    }


    /************************************************************
     *  Deployment core methods
     ***********************************************************/

    /**
     * Run through all the available test
     *
     * @return bool
     */
    public function execute()
    {
        // log ip address
        file_put_contents($this->ip_log, date('U').','.$_SERVER['REMOTE_ADDR']."\r\n",FILE_APPEND);

        // check permissions
        if (!$this->check_ip())
        {
            $this->log("Error: invalid remote IP: ".$_SERVER['REMOTE_ADDR']);
        }
        elseif (!$this->check_payload())
        {
            $this->log("Error: post payload is missing");
        }
        elseif (!$this->determine_type())
        {
            $this->log("Error: could not determine repo type, could be path problem");
        }
        elseif (!$this->check_author())
        {
            $this->log("Notice: ignoring commit by author ".$this->author);
        }
        elseif (!$this->check_branch())
        {
            $this->log("Notice: ignoring commit by branch ".$this->branch);
        }
        elseif (!$this->check_commit_message())
        {
            $this->log("Notice: ignoring commit by comment ".$this->message);
        }
        else
        {
            // update local repository copy
            exec($this->repo[$this->type]['pull'], $output);
            $this->log($output);
            $output = '';

            // check result for changes
            if (!$this->has_changes($output))
            {
                $this->log("Notice: no changes in repo");
            }
            else
            {
                // So far so good, now that we have updated the local repo
                // lets be damn sure that the update we pulled really does
                // match the author or message we want because the payload
                // could have been spoofed

                if (!$this->check_branch_author())
                {
                    $this->log("Error: last commit by different author -- could be alias?");
                }
                elseif (!$this->check_branch_message())
                {
                    $this->log("Error: last commit doesn't include message identifier");
                }
                else
                {
                    // daisy chain something BEFORE we update the code base
                    $this->execute_post_before();

                    // get current revision number
                    $previous_rev = $this->get_revision();

                    if (!empty($this->repo[$this->type]['pre']))
                    {
                        exec($this->repo[$this->type]['pre'], $output);
                    }

                    // update to a tag/branch
                    if (!empty($this->deploy_tag))
                    {
                        exec($this->repo[$this->type]['update_tag'] . $this->deploy_tag, $output);
                    }
                    elseif (!empty($this->deploy_branch))
                    {
                        exec($this->repo[$this->type]['update_branch'] . $this->deploy_branch, $output);
                    }
                    else
                    {
                        exec($this->repo[$this->type]['update'], $output);
                    }

                    if (!empty($this->repo[$this->type]['post']))
                    {
                        exec($this->repo[$this->type]['post'], $output);
                    }

                    // get new revision
                    $current_rev  = $this->get_revision();

                    $this->log($output);
                    if ($previous_rev != $current_rev)
                    {
                        $this->log("Repo revision went from '$previous_rev'");
                        $this->log("Repo revision went to   '$current_rev'");
                    }

                    // email result
                    $this->email($this->last_result);

                    // daisy chain something AFTER we update the code base
                    $this->execute_post_after();

                    return TRUE;
                }
            }
        }
        return FALSE;
    }

    /**
     * After pulling looks at the exec output result to see if any changes are found
     * could call this after everything and trigger another script/email/etc based on result.
     *
     * @param $output
     *
     * @return bool
     */
    public function has_changes($output)
    {
        $last_line = '';
        if (is_array($output))
        {
            $last_line = end($output);
        }

        if (!stristr($last_line, 'no changes found'))
        {
            return TRUE;
        }

        return FALSE;
    }

    /**
     * Determine the current tag of the repo
     *
     * @return mixed
     */
    private function get_tag()
    {
        exec($this->repo[$this->type]['get_tag'], $output);
        return $output[0];
    }

    /**
     * Determine the branch the repo is on
     *
     * @return mixed
     */
    private function get_branch()
    {
        exec($this->repo[$this->type]['get_branch'], $output);
        return $output[0];
    }

    /**
     * Get last revision
     *
     * @return mixed
     */
    private function get_revision()
    {
        exec($this->repo[$this->type]['get_revision'], $output);
        return $output[0];
    }

    /**
     * Return the author who committed last
     *
     * @return mixed
     */
    private function get_author()
    {
        exec($this->repo[$this->type]['get_author'], $output);
        return $output[0];
    }

    /**
     * Return the message of the last commit, could be multi line
     *
     * @return string
     */
    private function get_commit()
    {
        exec($this->repo[$this->type]['get_commit'], $output);
        return implode(' ', $output);
    }

    /**
     * Log something
     * if nothing is passed will pad log with a ------ line
     * if array is passed, each element is printed out
     *
     * @param mixed $msg
     *
     * @return string
     */
    public function log($msg = '')
    {
        if (empty($msg))
        {
            $result = "---------------------------\r\n";
        }
        elseif (is_array($msg))
        {
            $result = date('Ymd H:i:s') . "...\r\n";
            foreach($msg as $line)
            {
                $result .= "\t$line\r\n";
            }
        }
        else
        {
            $result = date('Ymd H:i:s') . " $msg\r\n";
        }

        if (!file_put_contents($this->log_file, $result, FILE_APPEND))
        {
            // if unable to write to file, going to try emailing if one was provided
            $result = "Error: unable to write to log";
            $this->email($result);
            die($result);
        }

        $this->last_result = $result;
        return $this->last_result;
    }

    /**
     * Return the last result, for debugging
     *
     * @return mixed
     */
    public function result()
    {
        return $this->last_result;
    }
}