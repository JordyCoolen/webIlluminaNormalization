<?php
/*
Author:					Job van Riet
Date of  creation:		24-2-14
Date of modification:	24-2-14
Version:				1.0
Modifications:			Original version
Known bugs:				None known
Function:				this page will allow the user to upload files.
*/
?>

<?php
// Get the idStudy from the session, if no session is made, let the user select a study.
session_start ();

if (isset ( $_SESSION ['idStudy'] )) {
	$idStudy = $_SESSION ['idStudy'];
} else {
	// Redirect to studyOverview of this study
	header('Location: chooseStudy');
}
?>

<?php
error_reporting ( E_ALL );
ini_set ( 'display_errors', '1' );
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />

<title>Uploading files for study: <?php echo $idStudy; ?></title>
<!--Load CSS for form layout -->
<link rel="stylesheet" type="text/css" href="../css/formLayout.css" media="all" />
<link href="../css/jQueryUI.css" rel="stylesheet" type="text/css" />

<!--Load main jQuery library-->
<script src="../js/jquery-1.11.0.js" type="text/javascript"></script>
<!--Load jQueryUI-->
<script src="../js/jquery-ui.js" type="text/javascript"></script>
<!-- Load Chosen module + CSS -->
<script src="../js/chosen.jquery.js" type="text/javascript"></script>
<script src="../js/chosen.order.js" type="text/javascript"></script>
<link rel="stylesheet" href="../css/chosen.css" />
</head>

<?php 
	//Get the fileType from the GET request if it was given
	if(isset($_GET['fileType'])){
		$fileType = $_GET['fileType'];
	}

	//Make a connection to the DIAMONDSDB (Defined in: functions_dataDB.php)
	require_once('../logic/functions_dataDB.php');
	$connection = makeConnectionToDIAMONDS();
?>

<!--Based on the selection from the type of file a user wants to upload, show the correct file upload options-->
<script type="text/javascript">
function showFileForm(){
		var selection = $('#fileType').val();
		switch(selection){ 
			case "sampleFile":
				$('#sampleFileForm').show();
				$('#sxsFile').hide();
				break;
			case "sxsFile":
				$('#sampleFileForm').hide();
				$('#sxsFile').show();
				break;
			default:
				$('#sampleFileForm').hide();
				$('#sxsFile').hide();
				break;
		}
	}; //End function showFileForm
</script>

<script type="text/javascript">
//Function to get the selected headers into a hidden field
function getMultipleHeaders(){
	 var selection = ChosenOrder.getSelectionOrder(document.getElementById('multiSelectHeaders'));
	$('#headersAll').val(selection);
}

//Functions to check if the supplied fields are all correctly filled in.
//Check if all the required dataTypes have been given.
function checkFields(){
	var headers = $('#headersAll').val();
	var splitHeaders = headers.split(",");
	var fileName = document.getElementById("sampleFile").value;	
    var fileExtension = fileName.split('.')[fileName.split('.').length - 1].toLowerCase();

	//Required fields correspond to idDataType in DB
	if(splitHeaders.indexOf("1") != -1 && splitHeaders.indexOf("1") != 2 && splitHeaders.indexOf("3") != -1 && splitHeaders.indexOf("4") != -1 && fileExtension == "txt"){
		document.getElementById("sampleFileForm").submit();
	}else{
		if(fileExtension != "txt"){
			alert("The sample file must be a tab-delimited file in .txt format.");
			return false;
		}
		else{
			alert("Not all required columns have been submitted! \n(compoundName/casNumber/sampleName/sampleType)");
			return false;
		}
	}
}
</script>

<div id="navBar">
	<?php require_once("menu.htm"); ?>
</div>

<body onload="showFileForm()">
	<img id="top" src="../img/top.png" alt="" />
	<div id="form_container">
		<h1>These are the files that are listed for this study.</h1>
		<!--Form to add files/data to a study -->
		<form action="">
			<!--Add hidden value to keep track of which form this is-->
			<div class="form_description">
				<h2>Add Files to this study</h2>
				<p>This form can be used to add new files and data to the study via the buttons shown below:</p>
			</div>
			<ol>
				<li id="li_1"><label class="description" for="fileType">Choose your function: </label>
					<div>
						<select data-placeholder="Choose the correct files" id="fileType" name="fileType" class="chosen-select" style="width: 360px;" tabindex="2" onchange="showFileForm()">
							<option value="" selected></option>
							<option value="sampleFile" <?php if($fileType == "sampleFile"){echo "selected";} ?>>Upload (multiple) samples to this study.</option>
							<option value="sxsFile" <?php if($fileType == "sxsFile"){echo "selected";} ?>>Upload SXS expression data (/report/ folder)</option>
						</select>
					</div>
					<p class="guidelines" id="guide_1">
						<small>Select the type of file you want to upload. <br> <em>Data from the file will be added to this study if applicable.</em></small>
					</p></li>
			</ol>
		</form>
		<!--End form select a fileType form -->

		<!--Form for if a sampleFile should be uploaded (Hidden if not selected-->
		<form id="sampleFileForm" method="post" action="getForm.php" enctype="multipart/form-data">
			<input id="formType" name="formType" class="element text large" type="hidden" value="uploadSampleFile" />
			<ol>
				<li><label class="description" for="sampleFile">Add new samples by uploading a sample file</label>
					<div>
						<input id="sampleFile" name="sampleFile" class="element text large" type="file" required />
					</div>
					<p class="guidelines" id="guide_1">
						<small>Upload a file using the upload form. <br>The file should contain the <b>sampleName</b>, <b>sampleType(control/pos.control etc/generic)</b>, <b>name of the compound</b> that
							was used and the <b>CAS-number</b>. <br>A tab-delimited file can thus look like: "sampleName | sampleName | CASNumber |sampleType | Noel | LD50 | Loel | noAel | etc | etc<br> <br>
							<em>All other columns expect sampleName/sampleType/compoundName/CAS are treated as additional attributes to each sample. The columns do not specifically have to be in any given order as
								the columns will be user defined before uploading.</em></small>
					</p><input type="checkbox" name="headersInFile" value="1" checked>Does the file contain headers?<br></li>
				<li><label class="description" for="insertType">Add to or delete all the previous samples for this study?</label>
				<select style="width: 100%" class="chosen-select" name="insertType"	id="insertType">
						<option value="overwrite">Delete the previous samples of this study</option>
				</select></li>
				<li>
					<div>
						<label class="description" for="arrayPlatform">Choose the correct platform of the array:</label> 
						<select data-placeholder="What is the array?" style="width: 100%" class="chosen-select"	name="idArray" required>
							<option value="" selected="selected"></option>
							<?php
							if ($result =  mysqli_query($connection, "SELECT * FROM tArrayPlatform")) {
								while ($row = mysqli_fetch_assoc($result)) {
									echo "<option value=".$row['idArrayPlatform'].">".$row['name']."</option>";
								}
							}
						?>
						</select>
					</div>
					<p class="guidelines" id="guide_2">
						<small>Choose the correct array method used for the samples. E.g. Illumina BeadChip/Affymetrix etc. </small>
					</p>
				</li>

				<li>
					<div>
						<label class="description" for="multiSelectHeaders">Choose the correct datatypes for your columns. <br><em><font size="0.5">Must correspond to order of columns in file!</font></em></label>
						<select id="multiSelectHeaders" data-placeholder="What are your columns?" style="width: 100%" multiple class="chosen-select" onChange="getMultipleHeaders()" required>
							<option value="sxsName">sxsName</option>
							<?php
							if ($result =  mysqli_query($connection, "SELECT * FROM tDataType")) {
								while ($row = mysqli_fetch_assoc($result)) {
									echo "<option value=".$row['idDataType'].">".$row['name']."</option>";
								}
							}
						?>
						</select>
						<!--Hidden field to hold the multiple selected headers-->
						<input id="headersAll" name="headersAll" class="element text large" type="hidden" />
						<!--Button to create a new dataType-->
						<button type="button" onclick="window.open('dataOverview?crudType=dataType');">Add new dataType</button>
					</div>
					<p class="guidelines" id="guide_3">
						<small>Choose the dataTypes that correspond to your extra columns. E.g. the first column contains sampleNames, second column contains noAel data etc.</small>
					</p> 
					<!--Submit button to submit the sampleFile upload form, checks fields and using javascript.--> 
					<input type="button" value="Submit" onclick='checkFields()'/>
				</li>
			</ol>
		</form>
		<!--End form to submit a sample File-->

		<!--Form to add expressionData from SXS /report/ folder-->
		<form id="sxsFile" method="POST" action="getForm.php" enctype="multipart/form-data">
			<input id="formType" name="formType" class="element text large" type="hidden" value="expressionDataSXSForm" />
			<ol>
				<li id="expressionDataSXS"><label class="description" for="expressionData">Add beadChip Expression data from Service XS (Contents of the /report/ folder)</label>
					<div>
						<input id="expressionSXSData" multiple="" webkitdirectory="" name="expressionSXSData[]" class="element text large" type="file" required />
					</div>
					<p class="guidelines" id="guide_1">
						<small>Upload the contents of the /report/ folder gotten from serviceXS. (Can select multiple files)</small>
					</p>
				</li>
				
				<li><label class="description" for="sampleToSXSNumber">Add a tab-delimited file to add the SXS number to the samples (on sampleName).</label>
					<div>
						<input id="sampleToSXSNumber" name="sampleToSXSNumber" class="element text large" type="file" /> 
						<input type="checkbox" name="headersInFile" value="1" checked>Does the file contain	headers?<br>
						<?php 
						
						//Check if samples have SXS number attached
						if ($result =  mysqli_query($connection, "SELECT count(idStudy) as count FROM tSamples WHERE idStudy = $idStudy AND sxsName != 0 ;")) {
							while ($row = mysqli_fetch_assoc($result)) {
								if($row['count'] != 0){
									echo "<input type='checkbox' checked disabled /><font color='green'>Samples already have SXS number? (".$row['count']." samples) </font> <br>";
								}
								else{
									echo "<input type='checkbox' disabled /><font color='red'>Samples already have SXS number?</font> <br>";
								}
							}
						}
						?>
						<input type="submit" name="submit" value="Submit">
					</div>
					<p class="guidelines" id="guide_1">
						<small>Upload a tab-delimited file as such: sxsNumber | sampleName.</small>
					</p>
				</li>
			</ol>
		</form>
		<!--End of form for submitting serviceXS zip file.-->
	</div>
</body>

<!--Give chosen JQuery to selected elements-->
<script type="text/javascript">
	var config = {
	  '.chosen-select'           : {search_contains:true},
	  '.chosen-select-deselect'  : {allow_single_deselect:true},
	  '.chosen-select-no-single' : {disable_search_threshold:10},
	  '.chosen-select-no-results': {no_results_text:'Oops, nothing found!'},
	  '.chosen-select-width'     : {width:"95%"}
	  
	}
	for (var selector in config) {
	  $(selector).chosen(config[selector]);
	}
  </script>
</html>