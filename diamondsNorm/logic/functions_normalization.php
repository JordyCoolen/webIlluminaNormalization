<?php
/*
 Author:				Job van Riet + ArrayAnalysis.org
Date of  creation:		25-2-14
Date of modification:	25-2-14
Version:				1.0
Modifications:			Original version
Known bugs:				None known
Function:				This file houses the functions to perform normalizations on samples uploaded to the DIAMONDS DB.
						The actual normalization is done by a slightly altered ArrayAnalysis.org R script on a CPU-limited thread (to prevent 100% CPU uptake). (Unknown author)
*/

//Include the scripts containing the config variables
require_once('../logic/config.php');

//Include script with added functionality (Connecting to DIAMONDS DB)
require_once('../logic/functions_dataDB.php');

//Include script with added functionality (Handling the files)
require_once('../logic/functions_fileHandling.php');

//Main function for the normalizationof data, this function will call other functions
function normalizeStudy($GET, $idStudy, $studyTitle){
	//Make a connection to the DIAMONDSDB (Defined in: functions_dataDB.php)
	$connection = makeConnectionToDIAMONDS();
	
	//Get the grouping options and put them in an array for easier handling
	$groupAttributes = explode(",",$GET['selectedAttributes']);
	
	//Convert the idDataTypes into their respective names for easier identification to the user
	//Except when compound and Sampletype are given as these are no idDataTypes
	$groupedOn = "";
	foreach($groupAttributes as $attr){
		if($attr != "compound" && $attr != "sampleType"){
			$query = ("SELECT name FROM tDataType WHERE idDataType = $attr");
			
			if ($result =  mysqli_query($connection, $query)) {
				while ($row = mysqli_fetch_assoc($result)) {
					$groupedOn.=$row['name'].'_';
				}
			}
		}
		else{
			$groupedOn.=$attr.'_';
		}
	}
	
	//Delete the last _ symbol
	if($groupedOn != ""){
		$groupedOn = substr($groupedOn, 0, -1);
	}else{
		$groupedOn = "None";
	}
		
	//Make a jobStatus in the DB
	$connection->query("INSERT INTO tJobStatus (`idStudy`, `name`, `description`, status, statusMessage) VALUES ($idStudy, 'Normalizing samples', 'Normalization of expression data.', 0, 'Running');");
	$idJob = mysqli_insert_id($connection);
	
	//Make a normAnalysis record in the DB
	$connection->query("INSERT INTO tNormAnalysis (`idStudy`, `description`, normType, bgCorrectionMethod, varStabMethod, normMethod, filterThreshold) 
			VALUES ($idStudy, 'Normalization is running, see idJob: $idJob', '".$GET['normType'] ."', '". (isset($GET['performBackgroundCorrection']) ? $GET['bgCorrect_m'] : 'None') ."', '".(isset($GET['performVarianceStabilization']) ? $GET['variance_Stab_m'] : 'None') ."', '".$GET['normalization_m'] ."', '".(isset($GET['filtering']) ? $GET['detectionTh'] : 'None') ."');");
	
	$idNorm = mysqli_insert_id($connection);
	
	//Get the correct folder in which the raw output has been storen output from the DB
	//Also get the Sample_Probe_Profile and Control_Probe_Profile locations and filenames
	$queryFiles = ("SELECT idFileType, folderName, fileName FROM vFilesWithInfo WHERE idStudy = $idStudy AND idFileType = 4 OR idFileType = 7;");
	$sampleProbeProfilePath;
	$controlProbeProfilePath;
	
	if ($resultFiles =  mysqli_query($connection, $queryFiles)) {
		while ($row = mysqli_fetch_assoc($resultFiles)) {
			$dataFolder = configMainfolder."/data/";
			$mainFolder = $dataFolder.$idStudy."_".$studyTitle;
			$inputFolder = $mainFolder."/".$row['folderName']."/";
			$fileLocation = $mainFolder."/".$row['folderName']."/".$row['fileName'];
			//Sample Probe Profile
			if($row['idFileType'] == 7){
				$sampleProbeProfilePath = $fileLocation;
				$sampleProbeProfileName = $row['fileName'];
			}
			//Control Probe Profile
			if($row['idFileType'] == 4){
				$controlProbeProfilePath = $fileLocation;
				$controlProbeProfileName = $row['fileName'];
			}
		}
	}
	
	//If file not in DB
	if(!isset($controlProbeProfilePath)){
		$connection->query("UPDATE tJobStatus SET status = 2, statusMessage = 'Failed: Could not find the Control Probe Profile in the DB! WHERE idJob = '$idJob'");
		exit("<p><font color=red>Could not find the Control Probe Profile in the DB!</font></p>");
	}
	//If file not on fileserver
	elseif(!file_exists($controlProbeProfilePath)){
		$connection->query("UPDATE tJobStatus SET status = 2, statusMessage = 'Failed: Could not find the Control Probe Profile on the fileserver on: $controlProbeProfilePath! WHERE idJob = '$idJob'");
		exit("<p><font color=red>Could not find the Control Probe Profile on the fileserver on: $controlProbeProfilePath!</font></p>");
	}else{
		echo "<p><font color=green>Control Probe Profile can be found in both the DB and fileserver!</font></p>";
	}
	//If file not in DB
	if(!isset($sampleProbeProfilePath)){
		$connection->query("UPDATE tJobStatus SET status = 2, statusMessage = 'Failed: Could not find the Sample Probe Profile on the fileserver on: $sampleProbeProfilePath! WHERE idJob = '$idJob'");
		exit("<p><font color=red>Could not find the Sample Probe Profile in the DB!</font></p>");
	}
	//If file not on fileserver
	elseif(!file_exists($sampleProbeProfilePath)){
		$connection->query("UPDATE tJobStatus SET status = 2, statusMessage = 'Failed: Could not find the Sample Probe Profile on the fileserver on: $sampleProbeProfilePath! WHERE idJob = '$idJob'");
		exit("<p><font color=red>Could not find the Sample Probe Profile on the fileserver on: $sampleProbeProfilePath!</font></p>");
	}else{
		echo "<p><font color=green>Sample Probe Profile can be found in both the DB and fileserver!</font></p>";
	}
	//Get the correct folder in which to store the normalized data output from the DB
	$query = ("SELECT folderName FROM tDirectory WHERE idDirectory = 4");
	
	if ($result =  mysqli_query($connection, $query)) {
		while ($row = mysqli_fetch_assoc($result)) {
			$directoryName = $row['folderName'];
		}
	}
	if(!isset($directoryName)){
		$connection->query("UPDATE tJobStatus SET status = 2, statusMessage = 'Failed: Could not retrieve the folder definition  the output of normalized expression data. Probably not filled in the DB' WHERE idJob = '$idJob'");
		exit("<p><font color=red>Could not retrieve the folder definition for the output of normalized expression data. Probably not filled in the DB.</font></p>");
	}
	
	#Add the idNorm to the output directory (expression/normed/idNorm/)
	$directoryName = $directoryName."/".$idNorm;
	
	//Make the folderName for the output of normalized data.
	//Create the folder if not created yet (Defined in: functions_filehandling.php)
	$normFolder = checkFolderStructure($connection, $idStudy, $studyTitle, $directoryName, $idJob);
	
	//Make a description file
	makeDescriptionFile($connection ,$normFolder, $groupAttributes, $idStudy, $idJob, $GET['skipNoSXS'], FALSE);
	
	//Get information about the study such as species, array used etc.
	$querySpecies = ("SELECT speciesName FROM vStudyWithTypeNames WHERE idStudy = $idStudy;");
	$species;
	if ($resultSpecies =  mysqli_query($connection, $querySpecies)) {
		while ($row = mysqli_fetch_assoc($resultSpecies)) {
			$species = $row['speciesName'];
		}
	}
	
	//Get the correct arrayType and annoType
	$queryArray = ("SELECT annoType, arrayType FROM vStudyWithTypeNames WHERE idStudy = $idStudy;");
	if ($resultArray =  mysqli_query($connection, $queryArray)) {
		while ($row = mysqli_fetch_assoc($resultArray)) {
			$annoType = $row['annoType'];
			$arrayType = $row['arrayType'];
		}
	}
		
	//Make a string of all the possible arguments a user can manipulate.
	$annoFolder = configMainfolder."/anno/";
	$scriptFolder = configMainfolder."/R/";
	
	//Check if some of the options should not be performed.
	//Should background correction be skipped?
	$bgSub = "TRUE";
	
	if(isset($GET['performBackgroundCorrection']) && $GET['performBackgroundCorrection'] == "on"){
		$bgSub = "FALSE";
	}
	
	$filtering = "TRUE";
	if(!$GET['filtering']){
		$filtering = "FALSE";
	}	
	
	//Should variance stabilization be skipped?
	$varStab = "FALSE";
	if(isset($GET['performVarianceStabilization']) && $GET['performVarianceStabilization'] == "on"){
		$varStab = "TRUE";
	}

	//Make a record in the tStatistics table if statistics should be run
	$performStat = "FALSE";

	//Should statistics be skipped?
	if(isset($GET['performStatistics']) && $GET['performStatistics'] == "on"){
		$connection->query("INSERT INTO tStatistics (`idNormAnalysis`, `groupedOn`, description) VALUES ($idNorm, '$groupedOn', '".$GET['descStat']."');");		
		$idStat = mysqli_insert_id($connection);
	}
	
	//Get the correct folder in which to store the statistics output from the DB
	$query = ("SELECT folderName FROM tDirectory WHERE idDirectory = 5");
	
	if ($result =  mysqli_query($connection, $query)) {
		while ($row = mysqli_fetch_assoc($result)) {
			$directoryName = $row['folderName'];
		}
	}
	
	#Make a new normQCResult/idStat folder
	$directoryName = $directoryName."/".$idStat."/";
	
	$statFolder = checkFolderStructure($connection, $idStudy, $studyTitle, $directoryName, $idJob);
	
	//Check if statistics should only be performed on a smaller subset of samples, if so, create a statFile.txt containing these sampleNames.
	$statSubset = "FALSE";
	//Should subsetting be skipped?	
	if(isset($GET['selectedSamples']) && $GET['selectedSamples'] != "0"){
		$statSubset = "TRUE";
		echo "<p><font color=orange>Creating statSubsetFile.txt.</font><p>";
		$sampleIDList = explode(",", $GET['selectedSamples']);
		
		//Open a file + fileHandler to make the statSubsetFile, save the file in the statistics folder and DB also.
		$statFile = "statSubsetFile.txt";
		$statFilePath = $statFolder."/".$statFile;
		$fileHandlerStat = fopen($statFolder."/statSubsetFile.txt", "w");
		if(!$fileHandlerStat){
			$connection->query("UPDATE tJobStatus SET status = 2, statusMessage = 'Failed: Could not make/write a statSubsetFile file in folder: ".$statFolder."' WHERE idJob = '$idJob'");
			exit("<p><font color=red>Could not make/write a description file in folder: $statFolder.</font></p>");
		}
		
		//Get the sampleNames
		foreach ($sampleIDList as &$idSample) {
			if ($result =  mysqli_query($connection, "SELECT name FROM tSamples WHERE idSample = $idSample")) {
				while ($row = mysqli_fetch_assoc($result)) {
					fwrite($fileHandlerStat, $row['name']."\n");
				}
			}
		}
		
		//Close the file
		fclose($fileHandlerStat);
		
		//Add the file to the DB
		$connection->query("INSERT INTO tFiles (`idStudy`, `idFileType`, `fileName`, idStatistics) VALUES ($idStudy, '31', 'statSubsetFile.txt', $idStat);");
		echo "<p><font color=green>Succesfully written a statSubsetFile file in folder: ".$statFolder."</font><p>";	
	}
	
	$reOrderGroup = "FALSE";
	//Should the plots be ordered based on the groups?
	if(isset($GET['reorderSamples']) && $GET['reorderSamples'] == "on"){
		$reOrderGroup = "TRUE";
	}
	
	$arguments = ("--inputDir $inputFolder
			--outputDir $normFolder
			--annoDir $annoFolder
			--scriptDir $scriptFolder
			--statisticsDir $statFolder
			--species $species
			--arrayType $arrayType
			--annoType $annoType
			--studyName $studyTitle
			--idStudy $idStudy
			--idJob $idJob
			--idNorm $idNorm
			--statSubset $statSubset
			--statFile ". (isset($statFile) ? $statFile : 'none')."
			--createLog FALSE
			-S ". (isset($idStat) ? $idStat : '0')."
			-s $sampleProbeProfileName
			-c $controlProbeProfileName
			-d descriptionFile.txt
			--bgSub $bgSub
			--detectionTh ".$GET['detectionTh']."
			--normType ".$GET['normType']."
			--bgcorrect.m ".$GET['bgCorrect_m']."
			--variance.stabilize $varStab
			--variance.m ".$GET['variance_Stab_m']."
			--normalization.m ".$GET['normalization_m']."
			--filtering $filtering
			--filter.Th ".$GET['filter_Th']."
			--filter.dp ".$GET['filter_dp']."
			--performStatistics $performStat
			--perGroup $reOrderGroup
			--raw.boxplot ".(isset($GET['raw_boxplot']) ? 'TRUE' : 'FALSE')."
			--raw.density ".(isset($GET['raw_density']) ? 'TRUE' : 'FALSE')."
			--raw.cv ".(isset($GET['raw_cv']) ? 'TRUE' : 'FALSE')."
			--raw.sampleRelation ".(isset($GET['raw_sampleRelation']) ? 'TRUE' : 'FALSE')."
			--raw.pca ".(isset($GET['raw_pca']) ? 'TRUE' : 'FALSE')."
			--raw.correl ".(isset($GET['raw_correl']) ? 'TRUE' : 'FALSE')."
			--norm.boxplot ".(isset($GET['norm_boxplot']) ? 'TRUE' : 'FALSE')."
			--norm.density ".(isset($GET['norm_density']) ? 'TRUE' : 'FALSE')."
			--norm.cv ".(isset($GET['norm_cv']) ? 'TRUE' : 'FALSE')."
			--norm.sampleRelation ".(isset($GET['norm_sampleRelation']) ? 'TRUE' : 'FALSE')."
			--norm.pca ".(isset($GET['norm_pca']) ? 'TRUE' : 'FALSE')."
			--norm.correl ".(isset($GET['norm_correl']) ? 'TRUE' : 'FALSE')."
			--clusterOption1 ".$GET['clustoption1']."
			--clusterOption2 ".$GET['clustoption2']."
			");
	
	//Perform the R script with a CPU limitation and in as a background deamon/thread.
	//The limit for the maximum amount of CPU time/power allowed for the normalization (Stops the server from freezing and focusing on the normalization)
	echo ("<p><font color=orange>Running normalization on samples using a background process and using a limited amount of CPU power.</font></p>");
		
	//Print output
	echo("nice -n 19 Rscript ".configMainfolder."/R/runIlluminaNormalization.R ".$arguments." > ".configMainfolder."/log &");
}

function doStatistics($GET, $idStudy, $studyTitle){
	//Make a connection to the DIAMONDSDB (Defined in: functions_dataDB.php)
	$connection = makeConnectionToDIAMONDS();
	
	//Get the grouping options and put them in an array for easier handling
	$groupAttributes = explode(",",$GET['selectedAttributes']);
	
	//Convert the idDataTypes into their respective names for easier identification to the user
	//Except when compound and Sampletype are given as these are no idDataTypes
	$groupedOn = "";
	foreach($groupAttributes as $attr){
		if($attr != "compound" && $attr != "sampleType"){
			$query = ("SELECT name FROM tDataType WHERE idDataType = $attr");
				
			if ($result =  mysqli_query($connection, $query)) {
				while ($row = mysqli_fetch_assoc($result)) {
					$groupedOn.=$row['name'].'_';
				}
			}
		}
		else{
			$groupedOn.=$attr.'_';
		}
	}
	
	//Delete the last _ symbol
	if($groupedOn != ""){
		$groupedOn = substr($groupedOn, 0, -1);
	}else{
		$groupedOn = "None";
	}
	
	//Make a jobStatus in the DB
	$connection->query("INSERT INTO tJobStatus (`idStudy`, `name`, `description`, status, statusMessage) VALUES ($idStudy, 'Performing statistics', 'Performing statistics on pre-existing normalized data.', 0, 'Running');");
	$idJob = mysqli_insert_id($connection);
	
	//Get the correct folder in which the rawdata R object has been stored
	$queryFiles = ("SELECT idFileType, folderName, fileName, idNorm FROM vFilesWithInfo WHERE idStudy = $idStudy AND idNorm = ".$GET['normSelect']." AND idFileType = 14;");
		
	$normObjectPath;
	
	if ($resultFiles =  mysqli_query($connection, $queryFiles)) {
		while ($row = mysqli_fetch_assoc($resultFiles)) {
			$dataFolder = configMainfolder."/data/";
			$mainFolder = $dataFolder.$idStudy."_".$studyTitle;
			$inputFolder = $mainFolder."/".$row['folderName']."/".$row['idNorm'];
			$fileLocation = $row['fileName'];
			//rawData R object
			if($row['idFileType'] == 14){
				$normObjectPath = $fileLocation;
			}
		}
	}
	
	//If file not in DB
	if(!isset($normObjectPath)){
		$connection->query("UPDATE tJobStatus SET status = 2, statusMessage = 'Failed: Could not find the normalized R object in the DB! WHERE idJob = '$idJob'");
		exit("<p><font color=red>Could not find the normalized R object in the DB!</font></p>");
	}	//If file not on fileserver
	elseif(!file_exists($normObjectPath)){
		$connection->query("UPDATE tJobStatus SET status = 2, statusMessage = 'Failed: Could not find the normalized R object on the fileserver on: $normObjectPath! WHERE idJob = '$idJob'");
		exit("<p><font color=red>Could not find the normalized R Object on the fileserver on: $normObjectPath!</font></p>");
	}else{
		echo "<p><font color=green>Normalized R Object can be found in both the DB and fileserver!</font></p>";
	}
	
	//Make a record in the tStatistics table
	$performStat = "TRUE";
	$connection->query("INSERT INTO tStatistics (`idNormAnalysis`, `groupedOn`, description) VALUES (".$GET['normSelect'].", '$groupedOn', '".$GET['descStat']."');");
	$idStat = mysqli_insert_id($connection);
	
	$reOrderGroup = "FALSE";
	//Should the plots be ordered based on the groups?
	if(isset($GET['reorderSamples']) && $GET['reorderSamples'] == "on"){
		$reOrderGroup = "TRUE";
	}
	
	//Get the correct folder in which to store the statistics output from the DB
	$query = ("SELECT folderName FROM tDirectory WHERE idDirectory = 5");
	
	if ($result =  mysqli_query($connection, $query)) {
		while ($row = mysqli_fetch_assoc($result)) {
			$directoryName = $row['folderName'];
		}
	}
	
	#Make a new normQCResult/idStat folder
	$directoryName = $directoryName."/".$idStat."/";
	$statFolder = checkFolderStructure($connection, $idStudy, $studyTitle, $directoryName, $idJob);
	
	//Check if statistics should only be performed on a smaller subset of samples, if so, create a statFile.txt containing these sampleNames.
	$statSubset = "FALSE";
	$statFile = "none";
	//Should statistics be skipped?
	if(isset($GET['selectedSamples']) && $GET['selectedSamples'] != "0"){
		$statSubset = "TRUE";
		echo "<p><font color=orange>Creating statSubsetFile.txt.</font><p>";
		$sampleIDList = explode(",", $GET['selectedSamples']);
	
		//Open a file + fileHandler to make the statSubsetFile, save the file in the statistics folder and DB also.
		$statFile = "statSubsetFile.txt";
		$statFilePath = $statFolder."/".$statFile;
		$fileHandlerStat = fopen($statFolder."/statSubsetFile.txt", "w");
		if(!$fileHandlerStat){
			$connection->query("UPDATE tJobStatus SET status = 2, statusMessage = 'Failed: Could not make/write a statSubsetFile file in folder: ".$statFolder."' WHERE idJob = '$idJob'");
			exit("<p><font color=red>Could not make/write a description file in folder: $statFolder.</font></p>");
		}
	
		//Get the sampleNames
		foreach ($sampleIDList as &$idSample) {
			if ($result =  mysqli_query($connection, "SELECT name FROM tSamples WHERE idSample = $idSample")) {
				while ($row = mysqli_fetch_assoc($result)) {
					fwrite($fileHandlerStat, $row['name']."\n");
				}
			}
		}
	
		//Close the file
		fclose($fileHandlerStat);
	
		//Add the file to the DB
		$connection->query("INSERT INTO tFiles (`idStudy`, `idFileType`, `fileName`, idStatistics) VALUES ($idStudy, '31', 'statSubsetFile.txt', $idStat);");
		echo "<p><font color=green>Succesfully written a statSubsetFile file in folder: ".$statFolder."</font><p>";
	}
	
	//Make a description file
	makeDescriptionFile($connection ,$statFolder, $groupAttributes, $idStudy, $idJob, "on", TRUE);
	
	//Make a string of all the possible arguments a user can manipulate.
	$annoFolder = configMainfolder."/anno/";
	$scriptFolder = configMainfolder."/R/";
	
	$arguments = ("--inputDir $inputFolder
			--outputDir $statFolder
			--annoDir $annoFolder
			--scriptDir $scriptFolder
			--statisticsDir $statFolder
			--studyName $studyTitle
			--idStudy $idStudy
			--idJob $idJob
			--idStatistics $idStat
			--statSubset $statSubset
			--statFile $statFile
			--createLog FALSE
			--normData $normObjectPath
			--loadOldNorm TRUE
			-d descriptionFile.txt
			--performStatistics $performStat
			--perGroup $reOrderGroup
			--rawDataQC FALSE
			--normDataQC TRUE
			--norm.boxplot ".(isset($GET['norm_boxplot']) ? 'TRUE' : 'FALSE')."
			--norm.density ".(isset($GET['norm_density']) ? 'TRUE' : 'FALSE')."
			--norm.cv ".(isset($GET['norm_cv']) ? 'TRUE' : 'FALSE')."
			--norm.sampleRelation ".(isset($GET['norm_sampleRelation']) ? 'TRUE' : 'FALSE')."
			--norm.pca ".(isset($GET['norm_pca']) ? 'TRUE' : 'FALSE')."
			--norm.correl ".(isset($GET['norm_correl']) ? 'TRUE' : 'FALSE')."
			--clusterOption1 ".$GET['clustoption1']."
			--clusterOption2 ".$GET['clustoption2']."
			--saveToDB FALSE
			--createAnno FALSE
			--filtering FALSE
			--normalize FALSE
			");
	
	//Perform the R script with a CPU limitation and in as a background deamon/thread.
	//The limit for the maximum amount of CPU time/power allowed for the normalization (Stops the server from freezing and focusing on the normalization)
	echo ("<p><font color=orange>Running normalization on samples using a background process and using a limited amount of CPU power.</font></p>");
	
	//Print output
	echo("nice -n 19 Rscript ".configMainfolder."/R/runIlluminaNormalization.R ".$arguments." > ".configMainfolder."/log &");	
}

//Make a tab-delimited description file (sxsName|sampleName|Group), the group is based on the user selected attributes.
//If $skipNoSXS == "on", it skips all the samples  from the study without a sxsNumber.
function makeDescriptionFile($connection, $normFolder, $groupAttributes, $idStudy, $idJob, $skipNoSXS, $oldNorm){
	echo "<p><font color=orange>Making description file.</font></p>";

	//Open a file + fileHandler to make the description file, save the file in the Normfolder also.
	$fileHandler = fopen($normFolder."/descriptionFile.txt", "w");
	if(!$fileHandler){
		$connection->query("UPDATE tJobStatus SET status = 2, statusMessage = 'Failed: Could not make/write a description file in folder: ".$normFolder."' WHERE idJob = '$idJob'");
		exit("<p><font color=red>Could not make/write a description file in folder: $normFolder.</font></p>");
	}
	
	//Write the headers
	fwrite($fileHandler, "ArrayDataFile\tSourceName\tFactorValue\n");
	
	//Loop over all the samples in a given study
	if($skipNoSXS != "on"){
		$query = ("SELECT idSample, sxsName, name FROM tSamples WHERE idStudy = 1 AND sxsName != NULL;");
	}
	else{
		$query = ("SELECT idSample, sxsName, name FROM tSamples WHERE idStudy = 1;");
	}
	if ($samples = $connection->query("SELECT * FROM tSamples WHERE idStudy = $idStudy")){
		while ($row = mysqli_fetch_assoc($samples)) {
			$idSample = rtrim($row['idSample']);
			$sxsName = rtrim($row['sxsName']);
			$sampleName = rtrim($row['name']);
			
			if($sxsName == "" && $skipNoSXS != "on"){
				$connection->query("UPDATE tJobStatus SET status = 2, statusMessage = 'Failed: Sample $sampleName (id: $idSample) has no sxsNumber and user selected no sampels should be skipped!' WHERE idJob = '$idJob'");
				fclose($fileHandler);
				exit("<p><font color=red>Failed: Sample $sampleName (id: $idSample) has no sxsNumber!</font></p>");
			}
			//Get all the attributes selected to cluster on of a given sample
			$groupOnLine = "";
			foreach($groupAttributes as $attr){
				if($attr == "compound"){
					$queryCompound = ("SELECT compoundName FROM vSamplesWithInfoNames WHERE idSample =$idSample");
					if ($result =  mysqli_query($connection, $queryCompound)) {
						while ($row = mysqli_fetch_assoc($result)) {
							$groupOnLine.=rtrim($row['compoundName']).'_';
						}
					}
				}
				else if($attr == "sampleType"){
					$querySampleType = ("SELECT typeName FROM vSamplesWithInfoNames WHERE idSample =$idSample");
					if ($result =  mysqli_query($connection, $querySampleType)) {
						while ($row = mysqli_fetch_assoc($result)) {
							$groupOnLine.=rtrim($row['typeName']).'_';
						}
					}
				}
				else if($attr != "compound" && $attr != "sampleType"){
					$queryAttributes = ("SELECT value FROM tAttributes WHERE idDataType = $attr AND idSample = $idSample");
					if ($dataTypeRes =  mysqli_query($connection, $queryAttributes)) {
						while ($row = mysqli_fetch_assoc($dataTypeRes)) {
							$groupOnLine.=rtrim($row['value']).'_';
						}
					}
				}//End local Else Loop
			}//End loop dataTypes
			//Cut of the last _ symbol
			$groupOnLine = substr($groupOnLine, 0, -1);
			if($groupOnLine=="") $groupOnLine = "noGroup";
			//Write the line (sxsName|sampleName|Group) to the descriptionFile.txt
			if($sxsName != ""){
				//If normalized data has already been provided, the unique names are the sampleNames and not the SXSNames
				if($oldNorm == TRUE){
					fwrite($fileHandler, $sampleName."\t".$sampleName."\t".$groupOnLine."\n");
				}else{
					fwrite($fileHandler, $sxsName."\t".$sampleName."\t".$groupOnLine."\n");
				}
			}
		}
	}//End loop samples
	//Close the fileHandler of descriptionFile.txt
	fclose($fileHandler);
	echo "<p><font color=green>$normFolder/descriptionFile.txt has succesfully been written!</font></p>";
}//End function makeDescriptionFile()
?>