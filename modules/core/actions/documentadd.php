<?php

/*
 * LMS version 1.9-cvs
 *
 *  (C) Copyright 2001-2005 LMS Developers
 *
 *  Please, see the doc/AUTHORS for more information about authors!
 *
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License Version 2 as
 *  published by the Free Software Foundation.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program; if not, write to the Free Software
 *  Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA 02111-1307,
 *  USA.
 *
 *  $Id$
 */

$layout['pagetitle'] = trans('New Document');

if(isset($_POST['document']))
{
	$document = $_POST['document'];
	$document['customerid'] = $_GET['cid'];
	
	$oldfromdate = $document['fromdate'];
	$oldtodate = $document['todate'];

	if(!($document['title'] || $document['description'] || $document['type']))
	{
		$SESSION->redirect('?'.$SESSION->get('backto'));
	}
	
	if(!$document['type'])
		$error['type'] = trans('Document type is required!');

	if(!$document['title'])
		$error['title'] = trans('Document title is required!');

	if($document['number'] == '')
	{
		$tmp = $LMS->GetNewDocumentNumber($document['type'], $document['numberplanid']);
		$document['number'] = $tmp ? $tmp : 0;
	}
	elseif(!eregi('^[0-9]+$', $document['number']))
    		$error['number'] = trans('Document number must be an integer!');
	elseif($LMS->DocumentExists($document['number'], $document['type'], $document['numberplanid']))
		$error['number'] = trans('Document with specified number exists!');
	
	if($document['fromdate'])
	{
		$date = explode('/',$document['fromdate']);
		if(checkdate($date[1],$date[2],$date[0]))
			$document['fromdate'] = mktime(0,0,0,$date[1],$date[2],$date[0]);
		else
			$error['fromdate'] = trans('Incorrect date format! Enter date in YYYY/MM/DD format!');
	}
	else 
		$document['fromdate'] = 0;

	if($document['todate'])
	{
		$date = explode('/',$document['todate']);
		if(checkdate($date[1],$date[2],$date[0]))
			$document['todate'] = mktime(0,0,0,$date[1],$date[2],$date[0]);
		else
			$error['todate'] = trans('Incorrect date format! Enter date in YYYY/MM/DD format!');
	}
	else 
		$document['todate'] = 0;
	
	if($document['fromdate'] > $document['todate'] && $document['todate']!=0)
		$error['todate'] = trans('Start date can\'t be greater than end date!');

	if($filename = $_FILES['file']['name'])
	{
		if(is_uploaded_file($_FILES['file']['tmp_name']) && $_FILES['file']['size'])
		{
			$file = $_FILES['file']['tmp_name'];
			$document['md5sum'] = md5_file($file);
			$document['contenttype'] = $_FILES['file']['type'];
			$document['filename'] = $filename;
		}
		else // upload errors
			switch($_FILES['file']['error'])
			{
				case 1: 			
				case 2: $error['file'] = trans('File is too large.'); break;
				case 3: $error['file'] = trans('File upload has finished prematurely.'); break;
				case 4: $error['file'] = trans('Path to file was not specified.'); break;
				default: $error['file'] = trans('Problem during file upload.'); break;
			}
	}	
	elseif($document['template'])
	{
		include($_DOC_DIR.'/templates/'.$document['template'].'/info.php');
		if(file_exists($_DOC_DIR.'/templates/'.$engine['engine'].'/engine.php'))
			require_once($_DOC_DIR.'/templates/'.$engine['engine'].'/engine.php');
		else
			require_once($_DOC_DIR.'/templates/default/engine.php');

		if($output)
		{
			$file = $_DOC_DIR.'/tmp.file';
			$fh = fopen($file, 'w');
			fwrite($fh, $output);
			fclose($fh);
			
			$document['md5sum'] = md5_file($file);
			$document['contenttype'] = $engine['content_type'];
			$document['filename'] = $engine['output'];
		}
		else
			$error['template'] = trans('Problem during file generation!');
	}
	else
		$error['file'] = trans('You must to specify file for upload or select document template!');

	if(!$error)
	{
		$path = $_DOC_DIR.'/'.substr($document['md5sum'],0,2);
		@mkdir($path, 0700);
		$newfile = $path.'/'.$document['md5sum'];
		if(!file_exists($newfile))
		{
			if(!@rename($file, $newfile))
				$error['file'] = trans('Can\'t save file in "$0" directory!', $path);
		}
		else
			$error['file'] = trans('Specified file exists in database!');
	}
	
	if(!$error)
	{
		$customer = $LMS->GetCustomer($document['customerid']);
		$time = time();
		
		$DB->BeginTrans();
		
		$DB->Execute('INSERT INTO documents (type, number, numberplanid, cdate, customerid, userid, name, address, zip, city, ten, ssn)
				VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
				array(	$document['type'],
					$document['number'],
					$document['numberplanid'],
					$time,
					$document['customerid'],
					$AUTH->id,
					trim($customer['lastname'].' '.$customer['name']),
					$customer['address'] ? $customer['address'] : '',
					$customer['zip'] ? $customer['zip'] : '',
					$customer['city'] ? $customer['city'] : '',
					$customer['ten'] ? $customer['ten'] : '',
					$customer['ssn'] ? $customer['ssn'] : '',
					));
		
		$docid = $DB->GetOne('SELECT id FROM documents WHERE type = ? AND cdate = ? AND customerid = ?', 
				array($document['type'], $time, $document['customerid']));

		$DB->Execute('INSERT INTO documentcontents (docid, title, fromdate, todate, filename, contenttype, md5sum, description)
				VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
				array(	$docid,
					$document['title'],
					$document['fromdate'],
					$document['todate'],
					$document['filename'],
					$document['contenttype'],
					$document['md5sum'],
					$document['description']
					));
		
		$DB->CommitTrans();
		
		if(!isset($document['reuse']))
		{
			$SESSION->redirect('?m=customerinfo&id='.$document['customerid']);
		}
		
		unset($document['title']);
		unset($document['number']);
		unset($document['description']);
		unset($document['datefrom']);
		unset($document['dateto']);
	}
	else
	{
		$document['fromdate'] = $oldfromdate;
		$document['todate'] = $oldtodate;
	}
}

//$SESSION->save('backto', $_SERVER['QUERY_STRING']);

if(!$document['numberplanid'])
{
	$document['numberplanid'] = $DB->GetOne('SELECT id FROM numberplans WHERE doctype<0 AND isdefault=1 LIMIT 1');
}

$document['customerid'] = $_GET['cid'];

if($templist = $LMS->GetNumberPlans())
	foreach($templist as $item)
		if($item['doctype']<0)
			$numberplans[] = $item;

if($dirs = getdir($_DOC_DIR.'/templates', '^[a-z0-9]+$'))
	foreach($dirs as $dir)
	{
		$infofile = $_DOC_DIR.'/templates/'.$dir.'/info.php';
		if(file_exists($infofile))
		{
			unset($engine);
			include($infofile);
			$docengines[] = $engine;
		}
	}

$SMARTY->assign('error', $error);
$SMARTY->assign('customers', $LMS->GetCustomerNames());
$SMARTY->assign('numberplans', $numberplans);
$SMARTY->assign('docengines', $docengines);
$SMARTY->assign('document', $document);
$SMARTY->display('documentadd.html');

?>