<?php

namespace TheFox\Imap;

use Exception;
use RuntimeException;
use InvalidArgumentException;

use Zend\Mail\Storage\Writable\Maildir;
use Zend\Mail\Message;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Filesystem\Filesystem;

use TheFox\Imap\Exception\NotImplementedException;
use TheFox\Logger\Logger;
use TheFox\Logger\StreamHandler;
use TheFox\Network\Socket;

class Server extends Thread{
	
	const LOOP_USLEEP = 10000;
	
	private $log;
	private $isListening = false;
	private $ip;
	private $port;
	private $clientsId = 0;
	private $clients = array();
	private $storageMaildir;
	
	public function __construct($ip = '127.0.0.1', $port = 20143){
		#print __CLASS__.'->'.__FUNCTION__.''."\n";
		
		$this->setIp($ip);
		$this->setPort($port);
	}
	
	public function setLog($log){
		$this->log = $log;
	}
	
	public function getLog(){
		return $this->log;
	}
	
	public function setIp($ip){
		$this->ip = $ip;
	}
	
	public function setPort($port){
		$this->port = $port;
	}
	
	public function init(){
		if(!$this->log){
			$this->log = new Logger('server');
			$this->log->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));
			if(file_exists('log')){
				$this->log->pushHandler(new StreamHandler('log/server.log', Logger::DEBUG));
			}
		}
		$this->log->info('start');
		$this->log->info('ip = "'.$this->ip.'"');
		$this->log->info('port = "'.$this->port.'"');
	}
	
	public function listen(){
		if($this->ip && $this->port){
			#$this->log->notice('listen on '.$this->ip.':'.$this->port);
			
			$this->socket = new Socket();
			
			$bind = false;
			try{
				$bind = $this->socket->bind($this->ip, $this->port);
			}
			catch(Exception $e){
				$this->log->error($e->getMessage());
			}
			
			if($bind){
				try{
					if($this->socket->listen()){
						$this->log->notice('listen ok');
						$this->isListening = true;
						
						return true;
					}
				}
				catch(Exception $e){
					$this->log->error($e->getMessage());
				}
			}
			
		}
	}
	
	public function run(){
		#print __CLASS__.'->'.__FUNCTION__.''."\n";
		#print __CLASS__.'->'.__FUNCTION__.': client '.count($this->clients)."\n";
		
		$readHandles = array();
		$writeHandles = null;
		$exceptHandles = null;
		
		if($this->isListening){
			$readHandles[] = $this->socket->getHandle();
		}
		foreach($this->clients as $clientId => $client){
			// Collect client handles.
			$readHandles[] = $client->getSocket()->getHandle();
			
			// Run client.
			#print __CLASS__.'->'.__FUNCTION__.': client run'."\n";
			$client->run();
		}
		$readHandlesNum = count($readHandles);
		
		$handlesChanged = $this->socket->select($readHandles, $writeHandles, $exceptHandles);
		#$this->log->debug('collect readable sockets: '.(int)$handlesChanged.'/'.$readHandlesNum);
		
		if($handlesChanged){
			foreach($readHandles as $readableHandle){
				if($this->isListening && $readableHandle == $this->socket->getHandle()){
					// Server
					$socket = $this->socket->accept();
					if($socket){
						$client = $this->clientNew($socket);
						$client->sendHello();
						#$client->sendPreauth('IMAP4rev1 server logged in as thefox');
						#$client->sendPreauth('server logged in as thefox');
						
						#$this->log->debug('new client: '.$client->getId().', '.$client->getIpPort());
					}
				}
				else{
					// Client
					$client = $this->clientGetByHandle($readableHandle);
					if($client){
						if(feof($client->getSocket()->getHandle())){
							$this->clientRemove($client);
						}
						else{
							#$this->log->debug('old client: '.$client->getId().', '.$client->getIpPort());
							$client->dataRecv();
							
							if($client->getStatus('hasShutdown')){
								$this->clientRemove($client);
							}
						}
					}
					
					#$this->log->debug('old client: '.$client->getId().', '.$client->getIpPort());
					
				}
			}
		}
	}
	
	public function loop(){
		$s = time();
		$r1 = 0;
		$r2 = 0;
		
		while(!$this->getExit()){
			$this->run();
			
			if(time() - $s >= 0 && !$r1){
				$r1 = 1;
				
				try{
					#$this->storageMaildir['object']->createFolder('test2');
				}
				catch(Exception $e){}
				
				$message = new Message();
				$message->addFrom('thefox21at@gmail.com');
				$message->addTo('thefox@fox21.at');
				$message->setBody('body');
				
				$message->setSubject('t1 '.date('H:i:s'));
				#$this->mailAdd($message->toString());
				
				$message->setSubject('t2 '.date('H:i:s'));
				#$this->mailAdd($message->toString());
				
				$message->setSubject('t3 '.date('H:i:s'));
				#$this->mailAdd($message->toString());
				
				$message->setSubject('t4 '.date('H:i:s'));
				#$this->mailAdd($message->toString());
				
			}
			
			if(time() - $s >= 5 && !$r2){
				$r2 = 1;
				
				$message = new Message();
				$message->addFrom('thefox21at@gmail.com');
				$message->addTo('thefox@fox21.at');
				$message->setSubject('test '.date('H:i:s'));
				$message->setBody('body');
				
				#$this->mailAdd($message->toString(), null, null, true);
				#$this->mailAdd($message->toString(), null, null, true);
				
				#$this->clients[1]->dataSend('* 2 EXPUNGE');
			}
			
			usleep(static::LOOP_USLEEP);
		}
		
		$this->shutdown();
	}
	
	public function shutdown(){
		#$this->log->debug('shutdown');
		
		// Notify all clients.
		foreach($this->clients as $clientId => $client){
			$client->sendBye('Server shutdown');
			$this->clientRemove($client);
		}
		
		// Remove all temp files and save dbs.
		$this->storageRemoveTempAndSave();
		
		#$this->log->debug('shutdown done');
	}
	
	private function clientNew($socket){
		$this->clientsId++;
		print __CLASS__.'->'.__FUNCTION__.' ID: '.$this->clientsId."\n";
		
		$client = new Client();
		$client->setSocket($socket);
		$client->setId($this->clientsId);
		$client->setServer($this);
		
		$this->clients[$this->clientsId] = $client;
		#print __CLASS__.'->'.__FUNCTION__.' clients: '.count($this->clients)."\n";
		
		return $client;
	}
	
	private function clientGetByHandle($handle){
		foreach($this->clients as $clientId => $client){
			if($client->getSocket()->getHandle() == $handle){
				return $client;
			}
		}
		
		return null;
	}
	
	private function clientRemove(Client $client){
		$this->log->debug('client remove: '.$client->getId());
		
		$client->shutdown();
		
		$clientsId = $client->getId();
		unset($this->clients[$clientsId]);
	}
	
	public function getStorageMailbox(){
		$this->storageInit();
		return $this->storageMaildir;
	}
	
	public function storageInit(){
		#$this->log->debug(__CLASS__.'->'.__FUNCTION__.'');
		if(!$this->storageMaildir){
			#$this->log->debug(__CLASS__.'->'.__FUNCTION__.': no storage is set. create one...');
			
			$mailboxPath = './tmp_mailbox_'.mt_rand(1, 9999999);
			return $this->storageAddMaildir($mailboxPath, 'temp');
		}
		
		return null;
	}
	
	public function storageAddMaildir($path, $type = 'normal'){
		if(!file_exists($path)){
			try{
				Maildir::initMaildir($path);
			}
			catch(Exception $e){
				$this->log->error('initMaildir: '.$e->getMessage());
			}
		}
		
		try{
			$dbPath = $path;
			if(substr($dbPath, -1) == '/'){
				$dbPath = substr($dbPath, 0, -1);
			}
			$dbPath .= '.msgs.yml';
			#$this->log->debug('dbpath: '.$dbPath);
			$db = new MsgDb($dbPath);
			$db->load();
			
			$storage = new Maildir(array('dirname' => $path));
			
			$this->storageMaildir = array(
				'object' => $storage,
				'path' => $path,
				'type' => $type,
				'db' => $db,
			);
			
			return $this->storageMaildir;
		}
		catch(Exception $e){
			$this->log->error('storageAddMaildir: '.$e->getMessage());
		}
		
		return null;
	}
	
	public function storageFolderAdd($path){
		$this->storageInit();
		
		$this->storageMaildir['object']->createFolder($path);
	}
	
	public function storageRemoveTempAndSave(){
		if($this->storageMaildir['type'] == 'temp'){
			$filesystem = new Filesystem();
			$filesystem->remove($this->storageMaildir['path']);
			if($this->storageMaildir['db']){
				$filesystem->remove($this->storageMaildir['db']->getFilePath());
			}
		}
		else{
			if($this->storageMaildir['db']){
				#$this->log->debug('save db: '.$this->storageMaildir['db']->getFilePath());
				$this->storageMaildir['db']->save();
			}
		}
	}
	
	public function storageMailboxGetFolders($folder, $recursive = false, $level = 0){
		$this->log->debug(__CLASS__.'->'.__FUNCTION__.': "'.$folder.'" '.(int)$recursive.', '.$level);
		
		if($level >= 100){
			return array();
		}
		if($folder == '*' || strtolower($folder) == 'inbox'){
			$folder = null;
		}
		
		$storage = $this->getStorageMailbox();
		
		$rv = array();
		$folders = $storage['object']->getFolders($folder);
		foreach($folders as $folder){
			$name = $folder->getGlobalName();
			$rv[] = $folder;
			if($recursive && strtolower($name) != 'inbox'){
				$rv = array_merge($rv, $this->storageMailboxGetFolders($name, $recursive, $level + 1));
			}
		}
		return $rv;
	}
	
	public function storageMailboxGetDbNextId(){
		#$this->log->debug(__CLASS__.'->'.__FUNCTION__.'');
		
		$storage = $this->getStorageMailbox();
		if($storage['db']){
			#$this->log->debug(__CLASS__.'->'.__FUNCTION__.': db ok');
			return $storage['db']->getNextId();
		}
		
		#$this->log->debug(__CLASS__.'->'.__FUNCTION__.': db failed');
		return null;
	}
	
	public function storageMailboxGetDbSeqById($msgId){
		#$this->log->debug(__CLASS__.'->'.__FUNCTION__.'');
		
		$storage = $this->getStorageMailbox();
		if($storage['db']){
			#$this->log->debug(__CLASS__.'->'.__FUNCTION__.': db ok');
			return $storage['db']->getSeqById($msgId);
		}
		
		#$this->log->debug(__CLASS__.'->'.__FUNCTION__.': db failed');
		return null;
	}
	
	public function storageMaildirGetDbMsgIdBySeqNum($seqNum){
		#$this->log->debug(__CLASS__.'->'.__FUNCTION__.': '.$seqNum);
		
		if($this->storageMaildir['db']){
			#$this->log->debug(__CLASS__.'->'.__FUNCTION__.' db ok: '.$seqNum);
			
			try{
				$uid = $this->storageMaildir['object']->getUniqueId($seqNum);
				#$this->log->debug(__CLASS__.'->'.__FUNCTION__.' uid '.$uid);
				$msgId = $this->storageMaildir['db']->getMsgIdByUid($uid);
				#$this->log->debug(__CLASS__.'->'.__FUNCTION__.' msgid: '.$msgId);
				return $msgId;
			}
			catch(Exception $e){
				$this->log->error('storageMaildirGetDbMsgIdBySeqNum: '.$e->getMessage());
			}
			
			return null;
		}
		
		return null;
	}
	
	public function mailAdd($mail, $folder = null, $flags = array(), $recent = true){
		#fwrite(STDOUT, __CLASS__.'->'.__FUNCTION__.''."\n");
		$this->storageInit();
		
		$uid = null;
		$msgId = null;
		
		// Because of ISSUE 6317 (https://github.com/zendframework/zf2/issues/6317) in the Zendframework we must reselect the current folder.
		$oldFolder = $this->storageMaildir['object']->getCurrentFolder();
		if($folder){
			$this->storageMaildir['object']->selectFolder($folder);
		}
		$this->storageMaildir['object']->appendMessage($mail, null, $flags, $recent);
		$lastId = $this->storageMaildir['object']->countMessages();
		#$message = $this->storageMaildir['object']->getMessage($lastId);
		$uid = $this->storageMaildir['object']->getUniqueId($lastId);
		$this->storageMaildir['object']->selectFolder($oldFolder);
		
		if($this->storageMaildir['db']){
			try{
				#fwrite(STDOUT, "add msg: ".$uid."\n");
				$msgId = $this->storageMaildir['db']->msgAdd($uid, $lastId, $folder ? $folder : $oldFolder);
				#ve($storage['db']);
			}
			catch(Exception $e){
				$this->log->error('db: '.$e->getMessage());
			}
		}
		
		return $msgId;
	}
	
	public function mailRemove($msgId){
		#fwrite(STDOUT, __CLASS__.'->'.__FUNCTION__.''."\n");
		
		if(!$this->getStorageMailbox()){
			throw new RuntimeException('Root storage not initialized.', 1);
		}
		
		if($this->storageMaildir['db']){
			$seqNum = 0;
			
			#fwrite(STDOUT, 'remove msgId: '.$msgId."\n");
			
			$oldFolder = $this->storageMaildir['object']->getCurrentFolder();
			#fwrite(STDOUT, 'folder: '.$oldFolder."\n");
			
			$uid = $this->storageMaildir['db']->getMsgUidById($msgId);
			#fwrite(STDOUT, 'remove uid: '.$uid."\n");
			
			$seqNum = $this->storageMaildir['db']->getSeqById($msgId);
			#fwrite(STDOUT, 'remove seqNum: '.$seqNum."\n");
			
			try{
				$this->storageMaildir['db']->msgRemove($msgId);
			}
			catch(Exception $e){
				$this->log->error('db remove: '.$e->getMessage());
			}
			
			try{
				if($seqNum){
					$this->storageMaildir['object']->removeMessage($seqNum);
				}
			}
			catch(Exception $e){
				$this->log->error('root storage remove: '.$e->getMessage());
			}
		}
		
	}
	
	public function mailRemoveBySequenceNum($seqNum){
		#fwrite(STDOUT, __CLASS__.'->'.__FUNCTION__.': '.$seqNum."\n");
		
		if(!$this->getStorageMailbox()){
			throw new RuntimeException('Root storage not initialized.', 1);
		}
		
		try{
			$this->storageMaildir['object']->removeMessage($seqNum);
		}
		catch(Exception $e){
			$this->log->error('root storage remove: '.$e->getMessage());
		}
		
		if($this->storageMaildir['db']){
			try{
				$msgId = $this->storageMaildirGetDbMsgIdBySeqNum($seqNum);
				#fwrite(STDOUT, "remove: $msgId\n");
				$this->storageMaildir['db']->msgRemove($msgId);
			}
			catch(Exception $e){
				$this->log->error('db remove: '.$e->getMessage());
			}
		}
	}
	
	public function mailCopy($msgId, $folder){
		#fwrite(STDOUT, __CLASS__.'->'.__FUNCTION__.': '.$msgId.', '.$folder."\n");
		
		if(!$this->getStorageMailbox()){
			throw new RuntimeException('Root storage not initialized.', 1);
		}
		
		if($this->storageMaildir['db']){
			#fwrite(STDOUT, "copy: $msgId\n");
			
			$seqNum = $this->storageMaildir['db']->getSeqById($msgId);
			#fwrite(STDOUT, "seqNum: $seqNum\n");
			
			if($seqNum){
				$this->mailCopyBySequenceNum($seqNum, $folder);
			}
			
		}
	}
	
	public function mailCopyBySequenceNum($seqNum, $folder){
		#fwrite(STDOUT, __CLASS__.'->'.__FUNCTION__.': '.$seqNum.', '.$folder."\n");
		
		if(!$this->getStorageMailbox()){
			throw new RuntimeException('Root storage not initialized.', 1);
		}
		
		if($this->storageMaildir['db']){
			$this->storageMaildir['object']->copyMessage($seqNum, $folder);
			
			$oldFolder = $this->storageMaildir['object']->getCurrentFolder();
			#fwrite(STDOUT, "oldFolder: $oldFolder\n");
			
			$this->storageMaildir['object']->selectFolder($folder);
			#fwrite(STDOUT, "folder: $folder\n");
			
			$lastId = $this->storageMaildir['object']->countMessages();
			#fwrite(STDOUT, "lastId: $lastId\n");
			
			$uid = $this->storageMaildir['object']->getUniqueId($lastId);
			#fwrite(STDOUT, "uid: $uid\n");
			
			$this->storageMaildir['object']->selectFolder($oldFolder);
			
			$this->storageMaildir['db']->msgAdd($uid, $lastId, $folder);
		}
	}
	
}
