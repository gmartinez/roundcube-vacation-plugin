<?php
/*
 * CPANEL driver
 *
 * @package	plugins
 * @uses	rcube_plugin
 * @author	Gonzalo Martinez <gonzalo@inprove.uy>
 * @version	1.0
 * @license     GPL
 * @link	https://sourceforge.net/projects/rcubevacation/
 * @todo	See README.TXT
 */

class CPANEL extends VacationDriver {
	private $ftp = false;	

	public function init() {
		$username = rcube::Q($this->user->data['username']);
		$userpass = $this->rcmail->decrypt($_SESSION['password']);

		// 15 second time-out
		if (! $this->ftp = ftp_ssl_connect($this->cfg['server'],21,15)) {
			 rcube::raise_error(array('code' => 601, 'type' => 'php', 'file' => __FILE__,
                'message' => sprintf("Vacation plugin: Cannot connect to the FTP-server '%s'",$this->cfg['server'])
			),true, true);

		}

		// Supress error here
		if (! @ftp_login($this->ftp, $this->cfg['ftpuser'],$this->cfg['ftppass'])) {
			 rcube::raise_error(array(
                'code' => 601, 'type' => 'php','file' => __FILE__,
                'message' => sprintf("Vacation plugin: Cannot login to FTP-server '%s' with username: %s",$this->cfg['server'],$this->cfg['ftpuser'])
			),true, true);
		}

		// Once we have a succesfull login, discard user-sensitive data like password
		$username = $userpass = null;

		// Enable passive mode
		if (isset($this->cfg['passive']) && !ftp_pasv($this->ftp, TRUE)) {
			 rcube::raise_error(array(
                'code' => 601,'type' => 'php','file' => __FILE__,
                'message' => "Vacation plugin: Cannot enable PASV mode on {$this->cfg['server']}"
			),true, true);
		}
	}

	// Download response and interval files
	public function _get() {
		
		$username = rcube::Q($this->user->data['username']);
		$vacArr = array("interval"=>"","start"=>"","stop"=>"","html"=>0,"subject"=>"","aliases"=>"", "body"=>"","forward"=>"","keepcopy"=>true,"enabled"=>true);

		// Load current interval if it exists
		if ($json_interval = $this->downloadfile($username.'.json')) {
			$json_interval = json_decode($json_interval,true);
			$vacArr['interval'] = $json_interval['interval'] ? $json_interval['interval'] / 3600 : null;
			$reset = date_default_timezone_get();
			date_default_timezone_set($_SESSION['timezone']);		
			$vacArr['start'] = $json_interval['start'] ? date('Y-m-d H:i',$json_interval['start']) : null;
			$vacArr['stop'] = $json_interval['stop'] ? date('Y-m-d H:i',$json_interval['stop']) : null;
			date_default_timezone_set($reset);
		}

		// Load current subject and body if it exists
		if ($dot_vacation_msg = $this->downloadfile($username)) {
			$dot_vacation_msg = explode(PHP_EOL,$dot_vacation_msg);
			$vacArr['html'] = stripos($dot_vacation_msg[1],'text/html;')!==false ? 1 : 0;
			$vacArr['subject'] = str_replace('Subject: ','',$dot_vacation_msg[2]);
			unset($dot_vacation_msg[0]);
			unset($dot_vacation_msg[1]);
			unset($dot_vacation_msg[2]);
			unset($dot_vacation_msg[3]);
			//$breaks = array("<br />","<br>","<br/>");
			//$dot_vacation_msg=str_ireplace($breaks, "\r\n",$dot_vacation_msg);
			$vacArr['body'] = implode(PHP_EOL,$dot_vacation_msg);
		} 

        // Use dotforward if it exists
		if ($dotForwardFile = $this->downloadfile(".forward")) {
			$d = new DotForward();
                        $d->setOption("username",$this->user->data['username']);
			$vacArr = array_merge($vacArr,$d->parse($dotForwardFile));

		}
		// Load aliases using the available identities
		if (! $vacArr['enabled']) $vacArr['aliases'] = $this->vacation_aliases("method");
		
		return $vacArr;
	}

	protected function setVacation() {

		$username = rcube::Q($this->user->data['username']);
		
		// Remove existing vacation files
		//$this->disable();
		
		// Enable auto-reply?
		//if ($this->enable) {
			$message[] = 'From: "'.$username.'" <'.$username.'>';
			$message[] = 'Content-type: '.($_REQUEST['_vacation_html'] ? 'text/html' : 'text/plain').'; charset=utf-8';
			$message[] = 'Subject: '.$_REQUEST['_vacation_subject'];
			$message[] = '';
			$message[] = $_REQUEST['_vacation_body'];
			$this->uploadfile(implode(PHP_EOL,$message),$username);
			
		
			$reset = date_default_timezone_get();
			date_default_timezone_set($_SESSION['timezone']);
			$interval['interval'] = $_REQUEST['_vacation_interval'] * 3600;
			$interval['start'] = strtotime($_REQUEST['_vacation_start']);
			$interval['stop'] = strtotime($_REQUEST['_vacation_stop']);		
			$this->uploadfile(json_encode($interval),$username.'.json');
			date_default_timezone_set($reset);
			
		//}
		

		// Do we even need to upload a .forward file?
		if ($this->keepcopy || $this->enable || $this->forward != "")
		{
			if (! $this->enable) { $d->setOption("binary",""); } 
			$this->uploadfile($d->create(),".forward");
		}
		
		return true;

	}

	// Cleans up files
	private function disable() {
		$username = rcube::Q($this->user->data['username']);
		$deleteArr = array(".forward",$this->dotforward['message'],$this->dotforward['database'],$username,$username.'.json');
		if (isset($this->dotforward['always_keep_message']) && $this->dotforward['always_keep_message'])
		{
			unset($deleteArr[1]);
		}
		$this->deletefiles($deleteArr);
		return true;
	}

	// Delete files when disabling vacation
	private function deletefiles(array $remoteFiles) {
		foreach ($remoteFiles as $file)
		{
			 
			if (ftp_size($this->ftp, $file) > 0)
			{
				@ftp_delete($this->ftp, $file);
			}
		}

		return true;
	}

	// Upload a file. 
	private function uploadfile($data,$remoteFile) {
		$localFile = tempnam(sys_get_temp_dir(), 'Vac');
		file_put_contents($localFile,trim($data));
		$result = @ftp_put($this->ftp, $remoteFile, $localFile, FTP_ASCII);

		unlink($localFile);
		if (! $result)
		{
			 rcube::raise_error(array(
                'code' => 601,'type' => 'php', 'file' => __FILE__,
                'message' => "Vacation plugin: Cannot upload {$remoteFile}. Check permissions and/or server configuration"
			),true, true);

		}
		return $result;
	}

	// Download a file and return its content as a string or return false if the file cannot be found
	private function downloadfile($remoteFile) {
		$localFile = tempnam(sys_get_temp_dir(), 'Vac');
		if (ftp_size($this->ftp,$remoteFile) > 0 && ftp_get($this->ftp,$localFile,$remoteFile,FTP_ASCII)) {
			$content = trim(file_get_contents($localFile));
		} else {
			$content = false;
		}
		unlink($localFile);
		return $content;
	}



	public function __destruct() {
		if (is_resource($this->ftp)) {
			ftp_close($this->ftp);
		}
	}

}
?>
