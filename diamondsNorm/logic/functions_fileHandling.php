<?php
/*
Author:					Job van Riet
Date of  creation:		13-2-14
Date of modification:	13-2-14
Version:				1.0
Modifications:			Original version
Known bugs:				None known
Function:				This file contains the functions to handle file upload and data insertions from these files.
						This script could be included into a page which needs these functionality.
*/

error_reporting(E_ALL);
ini_set('display_errors', '1');

//Include the scripts containing the config variables
require_once('../logic/config.php');

//Include scripts with added functionality
require_once('../logic/functions_dataDB.php');


//Try to save a file to a temporary folder and return that path.
function uploadSampleFileToDB($FILES,$POST, $idStudy, $studyTitle){
	$connection = makeConnectionToDIAMONDS();

	//Check if the studyID id provided
	if(!isset($idStudy)){
		exit("The study ID is not given! Cannot save the sample to a study");
	}
	
	//Check if idArray was provided
	if(!isset($POST['idArray'])){
		exit("<p><font color=red>The array ID is not given! Cannot save the samples to a study without knowing what array technology was used to process these samples!</font></p>");
	}else{ $idArray = $POST['idArray']; }
	
	//Make a jobStatus in the DB
	$connection->query("INSERT INTO tJobStatus (`idStudy`, `name`, `description`, status) VALUES ($idStudy, 'Uploading samples', 'Uploading samples to studies using upload form', 0);");
	$idJob = mysqli_insert_id($connection);	
	
	//Define the number of required fields
	$requiredColumns=4;
	//Keeps track of which column in the file is which required field
	$sampleNameIndex;
	$compoundNameIndex;
	$compoundCASIndex;
	$sampleTypeIndex;
	$sxsNameIndex;
		
	//Check if the required column/headers are supplied
	//Split the headers on ,
	$headerArray = explode(",", $POST['headersAll']);
	$numberOfHeaders = count($headerArray);
	$i = 0;
	foreach ($headerArray as $dataType){
		//First translate the dataTypeID to its name
		$dataTypeInfo = getDataType($connection, $dataType);
		//Check if all the required headers are there (Must correspond to the name for each dataType in the DB)
		switch($dataTypeInfo['name']){
			case "sampleName":
				$sampleNameIndex = $i;
				$requiredColumns--;
				break;
			case "compoundName":
				$compoundNameIndex = $i;
				$requiredColumns--;
				break;
			case "compoundCAS":
				$compoundCASIndex = $i;
				$requiredColumns--;
				break;
			case "sampleType":
				$sampleTypeIndex = $i;
				$requiredColumns--;
				break;
			case "sxsName":
				$sxsNameIndex = $i;
				break;
		}
		$i++;
	}
	if($requiredColumns != 0){
		$connection->query("UPDATE tJobStatus SET status = 2, statusMessage = 'Failed: Not all the required columns are given!' WHERE idJob = '$idJob'");
		exit("<p><font color=red>Not all the required columns are given! (sampleName, compoundName, compoundCAS, sampleType)</font></p>");
		
	}
	//End of checking for required headers
	
	//Save the sampleAnnotation file on the server
	//Check if the folders already exists which are needed to store the sampleAnnotation file
	//Get the correct folder name
	$query = ("SELECT folderName FROM tDirectory WHERE idDirectory = 2");
	
	if ($result =  mysqli_query($connection, $query)) {
		while ($row = mysqli_fetch_assoc($result)) {
			$directoryName = $row['folderName'];
		}
	}
	if(!isset($directoryName)){
		//Set job to failed
		$connection->query("UPDATE tJobStatus SET status = 2, statusMessage = 'Failed: Could not retrieve the folder definition for raw expression data. Probably not filled in the DB.' WHERE idJob = '$idJob'");
		exit("<p><font color=red>Could not retrieve the folder definition for raw expression data. Probably not filled in the DB.</font></p>");
	}
	
	$sampleAnnotationFolder = checkFolderStructure($connection, $idStudy, $studyTitle, $directoryName, $idJob);
	
	//Save sampleFile into the folder
	//Should the old file be overwritten or not?
	$overwrite = false;
	if($POST['insertType'] == "overwrite"){
		$overwrite = true;
		echo "<p>Overwriting previous samples for idStudy: $idStudy </p>";
		//Delete old sampleAnnotation file
		if (file_exists($sampleAnnotationFolder.'sampleAnnotation.txt')){
			unlink($sampleAnnotationFolder.'sampleAnnotation.txt');
		}
		if(move_uploaded_file( $FILES['sampleFile']['tmp_name'], $sampleAnnotationFolder.'/sampleAnnotation.txt')){
			echo "<p><font color=green>Successfully saved file to server!</font>";
			
			//Delete previous samples from study.
			$connection->query("DELETE FROM tSamples WHERE idStudy = $idStudy");
			//Add the file to the study
			$connection->query("DELETE FROM tFiles WHERE idStudy = $idStudy AND idFileType = 1");
			$connection->query("INSERT INTO tFiles (`idStudy`, `idFileType`, `fileName`) VALUES ($idStudy, '1', 'sampleAnnotation.txt');");
		}
		else{
			$connection->query("UPDATE tJobStatus SET status = 2, statusMessage = 'Failed: Could not move file: ".$FILES['sampleFile']." to ".$sampleAnnotationFolder."!' WHERE idJob = '$idJob'");
			exit("<p><font color=red>Could not move file: ".$FILES['sampleFile']." to $sampleAnnotationFolder!.</font></p>");
		}
	}
	
	//Read sampleFile and insert samples into database using the headers to know what each field represented.
	$fileHandle = fopen($sampleAnnotationFolder.'/sampleAnnotation.txt', "r");
	
	//If file had headers, skip that file
	if($POST['headersInFile'] == 1){
		$line = fgets($fileHandle);
	}
	
	//Add the arrayPlatform to the study
	$connection->query("UPDATE tStudy SET idArrayPlatform = $idArray WHERE idStudy = $idStudy");
	
	//Read the file until FEOF
	try{
		while (!feof($fileHandle)) {
			$line = fgets($fileHandle);
			$line = rtrim($line);
			$lineSplit = explode("\t", $line);
			//Check if # of headers correspond with # of tab-delimited columns
			if(count($lineSplit) == $numberOfHeaders){
				$compoundName = $lineSplit[$compoundNameIndex];
				$compoundCas = $lineSplit[$compoundCASIndex];
				$sampleName = $lineSplit[$sampleNameIndex];
				$sampleTypeName = $lineSplit[$sampleTypeIndex];
				if(isset($sxsNameIndex)){
					$sxsName = $lineSplit[$sxsNameIndex];
				}
				
				//Translate the sampleType into its ID, create if it does not yet exist.
				$idSampleType = getCreateSampleTypeID(array("Connection" => $connection, "name" => $sampleTypeName));
				
				//Make a compound with the columns that contain compoundName and compoundCAS data.
				//If it already exists, it will return the id of the existing compound
				$idCompound = getCreateCompound(array('name'=>$compoundName, 'casNumber'=>$compoundCas));
				//Make a sample with the newly created/retrieved idCompound and the column containing the sampleName and sampleType
				$idSample = getCreateSample(array('idStudy'=>$idStudy, 'name'=>$sampleName, 'idCompound'=>$idCompound, 'idArrayPlatform'=>$idArray, 'idSampleType' => $idSampleType, 'overwrite'=>$overwrite));
				//If the SXSname was also supplied, update the record and add tis number
				if(isset($sxsNameIndex)){
					$sxsName = $lineSplit[$sxsNameIndex];
					$connection = $mysqli->prepare("UPDATE tSamples SET sxsName = ? WHERE idSample = ?");
					$connection->bind_param('si',
							$sxsName,
							$idSample);
					$connection->execute();
				}
	
				//Add all the extra provided attributes to the sample in tAttributes based on the provided dataType through the user specified headers
				$x = 0;
				foreach($lineSplit as $data){
					//If the column has not already been handled
					if($x != $compoundNameIndex && $x != $compoundCASIndex && $x != $sampleNameIndex && $x != $sampleTypeIndex){
						$dataType = $headerArray[$x];
						$value = $lineSplit[$x];
						insertSampleAttribute($connection, $idSample, $dataType, $value);
					}
				$x++;
				}
				
			}//Stop the upload if the column count does not match and rollback the server to prevent faulty data
			else{
				//Check if the entire line is not empty
				if(strlen($line) > 3){
					$connection->rollback();
					$connection->query("UPDATE tJobStatus SET status = 2, statusMessage = 'Failed: Line did not contain the same amount of columns as the amount of header' WHERE idJob = '$idJob'");
					exit("<p><font color=red>This line does not contain the same amount of columns as the amount of headers: $line </font></p>");
				}
			}
		}
	
		//If everything succeeded, close the file and commit the data and close the connection
		fclose($fileHandle);
		$connection->query("UPDATE tJobStatus SET status = 1, statusMessage = 'Succes!' WHERE idJob = '$idJob'");
		$connection->commit();
		$connection->close();
	}catch (Exception $e) {
    	echo 'Caught exception: ',  $e->getMessage(), "\n";
	}
}

//Function to make an array of the multiple uploaded files
function UpFilesTOObj($fileArr){
	
	foreach($fileArr['name'] as $keyee => $info)
	{
		$uploads[$keyee] = new StdClass;
		$uploads[$keyee]->name=$fileArr['name'][$keyee];
		$uploads[$keyee]->type=$fileArr['type'][$keyee];
		$uploads[$keyee]->tmp_name=$fileArr['tmp_name'][$keyee];
		$uploads[$keyee]->error=$fileArr['error'][$keyee];
	}
	return $uploads;
}

//Class to handle the iploads
class FileUploader{
	public function __construct($con, $idStudy, $studyTitle, $idJob, $uploads, $uploadDir, $fileTypeList){
		//Read through the multiple files
		foreach($uploads as $current)
		{
			//Get the filetype of the file and save the file to the Db records
			if($this->saveFileDB($con, $idStudy, $current, $fileTypeList)){
				$this->uploadFile=$uploadDir."/".$current->name;
			}
			//If fileType is not known, set uploadDor to /unknown/
			else{
				//Get the correct folder name of the unknown directory
				$query = ("SELECT folderName FROM tDirectory WHERE idDirectory = 6");
				
				if ($result =  mysqli_query($con, $query)) {
					while ($row = mysqli_fetch_assoc($result)) {
						$directoryName = $row['folderName'];
					}
				}
				
				//Create the unknown directory if not yet exist
				$unknownDir = checkFolderStructure($con, $idStudy, $studyTitle, $directoryName, $idJob);
				$this->uploadFile=$unknownDir."/".$current->name;
			}
			
			if($this->upload($current,$this->uploadFile)){
				echo "<p><font color=green>Successfully uploaded ".$current->name." to $uploadDir</font></p>";
			}
			else{
				echo "<p><font color=red>Could not upload ".$current->name." to $uploadDir</font></p>";
			}	
		}
	}

	public function upload($current,$uploadFile){
		if(move_uploaded_file($current->tmp_name,$uploadFile)){
			return true;
		}
	}
	
	//Save the file into the db, if fileType is found, save to normal directory, else to <dataDir>/unknown/
	public function saveFileDB($connection, $idStudy, $file, $fileTypeList){
		foreach ($fileTypeList as $fileType){
			$foundFiletype = 0;
			
			if (strpos($file->name,$fileType['searchOn']) !== false) {
				$foundFiletype = 1;
				//Delete old file in DB
				$connection->query("DELETE FROM tFiles WHERE idStudy = $idStudy AND idFileType = ".$fileType['id']);
				//Add new file
				$connection->query("INSERT INTO tFiles (`idStudy`, `idFileType`, `fileName`) VALUES ($idStudy, ".$fileType['id'].", '$file->name');");
				return true;
			}
		}
			
		if($foundFiletype == 0){		
			echo "<p><font color=red>Could not determine filetype for file: $file->name, user needs to set this filetype manually!</font></p>";
			$connection->query("INSERT INTO tFiles (`idStudy`, `idFileType`, `fileName`) VALUES ($idStudy, 2, '$file->name');");
			return false;
		}
	}
}

//Try to open and save the different files submitted by the user.
function uploadExpressionDataSXSToDB($FILES,$POST, $idStudy, $studyTitle){
	
	//Check if the studyID id provided
	if(!isset($idStudy)){
		exit("<p><font color=red>The study ID is not given! Cannot save the expression data to a study</font></p>");
	}

	//Make a connection to the DB
	$connection = makeConnectionToDIAMONDS();

	//Make a jobStatus in the DB
	$connection->query("INSERT INTO tJobStatus (`idStudy`, `name`, `description`, status) VALUES ($idStudy, 'Uploading /report/ folder', 'Uploading folder with the expression data from SXS', 0);");
	$idJob = mysqli_insert_id($connection);

	//Get the correct folder name
	$query = ("SELECT folderName FROM tDirectory WHERE idDirectory = 3");

	if ($result =  mysqli_query($connection, $query)) {
		while ($row = mysqli_fetch_assoc($result)) {
			$directoryName = $row['folderName'];
		}
	}
	if(!isset($directoryName)){
		$connection->query("UPDATE tJobStatus SET status = 2, statusMessage = 'Failed: Could not retrieve the folder definition for raw expression data. Probably not filled in the DB' WHERE idJob = '$idJob'");
		exit("<p><font color=red>Could not retrieve the folder definition for raw expression data. Probably not filled in the DB.</font></p>");
	}
	
	//Make the expressionfolder
	$expressionFolder = checkFolderStructure($connection, $idStudy, $studyTitle, $directoryName, $idJob);
	
	//Get an array of all the different fileTypes and their searchOn value
	//Get the correct folder name
	$query = ("SELECT idFileType, searchOn FROM tFileType WHERE idDirectory = 3;");
	$fileTypes = array();
	if ($result =  mysqli_query($connection, $query)) {
		while ($row = mysqli_fetch_assoc($result)) {
			array_push($fileTypes, array('id'=>$row['idFileType'], 'searchOn'=>$row['searchOn']));
		}
	}
	
	//Try to read all the supplied files
	try{
		$uploads = UpFilesTOObj($FILES['expressionSXSData']);
		if (!isset($fileUploader))$fileUploader= new FileUploader($connection, $idStudy, $studyTitle, $idJob, $uploads, $expressionFolder, $fileTypes);
	}
	catch (Expection $e) {
		$postMax = ini_get('post_max_size');
		$connection->query("UPDATE tJobStatus SET status = 2, statusMessage = 'Failed: Could not read all the given files. ($e)' WHERE idJob = '$idJob'");
		exit('<p><font color=red>Failed to open the .zip containing the SXS files. It possibly exceeds maximum filesize. ('.$postMax.')</font></p>');
	}

	//Check if SXS#->sampleName file is given
	if($FILES['sampleToSXSNumber']['name'] != null){

		//Add the SXS numbers to the samples
		//Read file to set SXSnumber to the correct sample (using its name)		
		try{
			if($fileHandle = fopen($FILES['sampleToSXSNumber']['tmp_name'], "r")){
				//If file had headers, skip that file
				if($POST['headersInFile'] == 1){
					$line = fgets($fileHandle);
				}
				
				//Read the file until FEOF
				while (!feof($fileHandle)) {
					$line = fgets($fileHandle);
					$lineSplit = explode("\t", $line);
					//Check if # of headers correspond with # of tab-delimited columns
					if(count($lineSplit) == 2){
						$sampleName = rtrim($lineSplit[0]);
						$sxsNumber = rtrim($lineSplit[1]);
	
						//Update the sample with the SXS number
						$idSample = getIDSampleByName($connection, $idStudy, $sampleName);
						if($idSample != ''){
							$connection->query("UPDATE tSamples SET sxsName = '$sxsNumber' WHERE idSample = '$idSample'");
						}else{
							$connection->query("UPDATE tJobStatus SET status = 2, statusMessage = 'Failed: Could not find a sample for this study with the name of ".$sampleName."' WHERE idJob = '$idJob'");
							exit("<p><font color=red>Could not find a sample for this study with the name of ".$sampleName."</font></p>");
						}
					}//Stop the upload if the column count does not match and rollback the server to prevent faulty data
					else{
						//Check if line is not just empty
						if(strlen($line) > 3){
							$connection->query("UPDATE tJobStatus SET status = 2, statusMessage = 'Failed: A line did not contain the same amount of columns as the amount of headers' WHERE idJob = '$idJob'");
							exit("<p><font color=red>This line does not contain the same amount of columns as the amount of headers:". $line."</font></p>");
						}
					}
				}
				fclose($fileHandle);
			}
		}catch (Exception $e) {
			echo 'Caught exception while reading file '.$FILES['sampleToSXSNumber']['tmp_name'].'. Exception: ',  $e->getMessage(), "\n";
		}
		//If everything succeeded, close the file and commit the data and close the connection
		
	}//End adding SXS -> sampleName
	$connection->query("UPDATE tJobStatus SET status = 1, statusMessage = 'Succes!' WHERE idJob = '$idJob'");

	//Commit data to DB
	$connection->commit();
	$connection->close();
}


//Checks and creates folders for a given study
function checkFolderStructure($connection, $idStudy, $studyTitle, $folderName, $idJob){
	$dataFolder = configMainfolder."/data/";
	//Unique title of the main folder
	$mainFolder = $dataFolder.$idStudy."_".$studyTitle;
	//Concatenate the folder names, indicating the new directory.
	$newDirectory = $mainFolder."/".$folderName;
	//Try to create the folder if it does not yet exist.
	createFolder($connection, $mainFolder, $idJob);
	createFolder($connection, $newDirectory, $idJob);
	//Return the created folder
	return $newDirectory;
}

//Creates a folder if it does not yet exist.
function createFolder($connection, $folderName, $idJob){
	$baseName = basename($folderName);
	if (!is_dir($folderName)) {
		if(!mkdir($folderName, 0775,true)){
			$connection->query("UPDATE tJobStatus SET status = 2, statusMessage = 'Failed: Could not create folder: $baseName</font>.' WHERE idJob = '$idJob'");
			exit("<p><font color=red>Could not create folder: $baseName</font></p>");
		}
		else{
			echo "<p><font color=green>The folder $baseName was successfully created.</font></p>";
		}
	}
	else{
		echo "<p><font color=orange>$folderName already exists. Skipping mkdir of this folder.</font></p>";
	}
}
?>
