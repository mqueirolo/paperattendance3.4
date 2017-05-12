<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <http://www.gnu.org/licenses/>.
/**
 * @package    local
 * @subpackage paperattendance
 * @copyright  2016 Hans Jeria (hansjeria@gmail.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Define whether the pdf has been processed or not 
define('PAPERATTENDANCE_STATUS_UNREAD', 0); 	//not processed
define('PAPERATTENDANCE_STATUS_PROCESSED', 1); 	//already processed
define('PAPERATTENDANCE_STATUS_SYNC', 2); 		//already synced with omega

/**
* Creates a QR image based on a string
*
* @param unknown $qrstring
* @return multitype:string
*/
function paperattendance_create_qr_image($qrstring , $path){
		global $CFG;
		require_once ($CFG->dirroot . '/local/paperattendance/phpqrcode/phpqrcode.php');

		if (!file_exists($path)) {
			mkdir($path, 0777, true);
		}
		
		$filename = "qr".substr( md5(rand()), 0, 4).".png";
		$img = $path . "/". $filename;
		QRcode::png($qrstring, $img);
		
		return $filename;
}

/**
 * Get all students from a course, for list.
 *
 * @param unknown_type $courseid
 */
function paperattendance_get_students_for_printing($course) {
	global $DB;
	
	$query = 'SELECT u.id, 
			u.idnumber, 
			u.firstname, 
			u.lastname, 
			u.email,
			GROUP_CONCAT(e.enrol) AS enrol
			FROM {user_enrolments} ue
			INNER JOIN {enrol} e ON (e.id = ue.enrolid AND e.courseid = ?)
			INNER JOIN {context} c ON (c.contextlevel = 50 AND c.instanceid = e.courseid)
			INNER JOIN {role_assignments} ra ON (ra.contextid = c.id AND ra.roleid = 5 AND ra.userid = ue.userid)
			INNER JOIN {user} u ON (ue.userid = u.id)
			GROUP BY u.id
			ORDER BY lastname ASC';
	$params = array($course->id);
	$rs = $DB->get_recordset_sql($query, $params);
	
	return $rs;
}

/**
 * Get the student list
 * 
 * @param int $course
 *            Id course
 */
function paperattendance_students_list($contextid, $course){
	global $CFG;
	//TODO: Add enrolments for omega, Remember change "manual".
	$enrolincludes = explode("," ,$CFG->paperattendance_enrolmethod);
//	$filedir = $CFG->dataroot . "/temp/emarking/$contextid";
//	$userimgdir = $filedir . "/u";
	$students = paperattendance_get_students_for_printing($course);
	
	$studentinfo = array();
	// Fill studentnames with student info (name, idnumber, id and picture).
	foreach($students as $student) {
		$studentenrolments = explode(",", $student->enrol);
		// Verifies that the student is enrolled through a valid enrolment and that we haven't added her yet.
		if (count(array_intersect($studentenrolments, $enrolincludes)) == 0 || isset($studentinfo[$student->id])) {
			continue;
		}
		// We create a student info object.
		$studentobj = new stdClass();
		$studentobj->name = substr("$student->lastname, $student->firstname", 0, 65);
		$studentobj->idnumber = $student->idnumber;
		$studentobj->id = $student->id;
		//$studentobj->picture = emarking_get_student_picture($student, $userimgdir);
		// Store student info in hash so every student is stored once.
		$studentinfo[$student->id] = $studentobj;
	}
	$students->close();
	return $studentinfo;
}


/**
 * Draws a table with a list of students in the $pdf document
 *
 * @param unknown $pdf
 *            PDF document to print the list in
 * @param unknown $logofilepath
 *            the logo
 * @param unknown $downloadexam
 *            the exam
 * @param unknown $course
 *            the course
 * @param unknown $studentinfo
 *            the student info including name and idnumber
 */
function paperattendance_draw_student_list($pdf, $logofilepath, $course, $studentinfo, $requestorinfo, $modules, $qrpath, $qrstring, $webcursospath, $sessiondate,$description) {
	global $CFG;
	$modulecount = 1;
	// Pages should be added automatically while the list grows.
	$pdf->SetAutoPageBreak(false);
	$pdf->AddPage();
	$pdf->SetFont('Helvetica', '', 8);
	// Top QR
	$qrfilename = paperattendance_create_qr_image($qrstring.$modulecount."*".$description, $qrpath);
	$goodcirlepath = $CFG->dirroot . '/local/paperattendance/img/goodcircle.png';
	$pdf->Image($qrpath."/".$qrfilename, 153, 5, 35);
	// Botton QR, messege to fill the circle and Webcursos Logo
	$pdf->Image($webcursospath, 18, 265, 35);

	$pdf->SetXY(70,264);
	$pdf->Write(1, "Recuerde NO utilizar Lápiz mina ni destacador,");
	$pdf->SetXY(70,268);
	$pdf->Write(1, "de lo contrario la  asistencia no quedará valida.");
	$pdf->SetXY(70,272);
	$pdf->Write(1, "Se recomienda rellenar así");
	//$pdf->Image($goodcirlepath, 107, 272, 5);
	$pdf->Image($goodcirlepath, 107, 272, 5, 5, "PNG", 0);
	
	$pdf->Image($qrpath."/".$qrfilename, 153, 256, 35);
	unlink($qrpath."/".$qrfilename);
	
	// If we have a logo we draw it.
	$left = 20;
	if ($logofilepath) {
		$pdf->Image($logofilepath, $left, 15, 50);
		$left += 55;
	}
	
	// Write the attendance description
	$pdf->SetFont('Helvetica', '', 12);
	$pdf->SetXY(20, 31);
	$descriptionstr = trim_text(paperattendance_returnattendancedescription(false, $description),20);
	$pdf->Write(1, core_text::strtoupper($descriptionstr));
	
	// We position to the right of the logo.
	$top = 7;
	$pdf->SetFont('Helvetica', 'B', 12);
	$pdf->SetXY($left, $top);

	// Write course name.
	$coursetrimmedtext = trim_text($course->shortname,30);
	$top += 6;
	$pdf->SetFont('Helvetica', '', 8);
	$pdf->SetXY($left, $top);
	$pdf->Write(1, core_text::strtoupper(get_string('course') . ': ' . $coursetrimmedtext));
	
	$teachers = get_enrolled_users(context_course::instance($course->id), 'mod/emarking:supervisegrading');
	$teachersnames = array();
	foreach($teachers as $teacher) {
		$teachersnames[] = $teacher->firstname . ' ' . $teacher->lastname;
	}
	$teacherstring = implode(',', $teachersnames);
	$schedule = explode("*", $modules);
	$stringmodules = $schedule[1]." - ".$schedule[2];
	// Write teacher name.
	$teachertrimmedtext = trim_text($teacherstring,30);
	$top += 4;
	$pdf->SetXY($left, $top);
	$pdf->Write(1, core_text::strtoupper(get_string('teacher', 'mod_emarking') . ': ' . $teachertrimmedtext));
	// Write requestor.
	$requestortrimmedtext = trim_text($requestorinfo->firstname." ".$requestorinfo->lastname,30);
	$top += 4;
	$pdf->SetXY($left, $top);
	$pdf->Write(1, core_text::strtoupper(get_string("requestor", 'local_paperattendance') . ': ' . $requestortrimmedtext));
	// Write date.
	$top += 4;
	$pdf->SetXY($left, $top);
	$pdf->Write(1, core_text::strtoupper(get_string("date") . ': ' . date("d-m-Y", $sessiondate)));
	// Write modules.
	$modulestrimmedtext = trim_text($stringmodules,30);
	$top += 4;
	$pdf->SetXY($left, $top);
	$pdf->Write(1, core_text::strtoupper(get_string("modulescheckbox", 'local_paperattendance') . ': ' . $modulestrimmedtext));
	// Write number of students.
	$top += 4;
	$pdf->SetXY($left, $top);
	$pdf->Write(1, core_text::strtoupper(get_string('students') . ': ' . count($studentinfo)));
	// Write the table header.
	$left = 20;
	$top += 8;
	$pdf->SetXY($left, $top);
	$pdf->Cell(8, 8, "N°", 0, 0, 'C');
	$pdf->Cell(25, 8, core_text::strtoupper(get_string('idnumber')), 0, 0, 'L');
	$pdf->Cell(20, 8, core_text::strtoupper(""), 0, 0, 'L');
	$pdf->Cell(90, 8, core_text::strtoupper(get_string('name')), 0, 0, 'L');
	$pdf->Cell(20, 8, core_text::strtoupper(get_string('pdfattendance','local_paperattendance')), 0, 0, 'L');
	$pdf->Ln();
	$top += 8;
	
	$circlepath = $CFG->dirroot . '/local/paperattendance/img/circle.png';
	paperattendance_drawcircles($pdf);
	
	// Write each student.
	$current = 1;
	$pdf->SetFillColor(228, 228, 228);
	foreach($studentinfo as $stlist) {

		$pdf->SetXY($left, $top);
		// Cell color
		if($current%2 == 0){
			$fill = 1;
		}else{
			$fill = 0;
		}
		// Number
		$pdf->Cell(8, 8, $current, 0, 0, 'L', $fill);
		// ID student
		$pdf->Cell(25, 8, $stlist->idnumber, 0, 0, 'L', $fill);
		// Profile image
		$x = $pdf->GetX();
		$y = $pdf->GetY();
		$pdf->Cell(20, 8, "", 0, 0, 'L', $fill);
//		$pdf->Image($stlist->picture, $x + 5, $y, 8, 8, "PNG", $fill);
		// Student name
		$pdf->Cell(90, 8, core_text::strtoupper($stlist->name), 0, 0, 'L', $fill);
		// Attendance
		$x = $pdf->GetX();
		$y = $pdf->GetY();
		$pdf->Cell(20, 8, "", 0, 0, 'C', 0);
		$pdf->Image($circlepath, $x + 5, $y+1, 6, 6, "PNG", 0);
		
		$pdf->line(20, $top, (20+8+25+20+90+20), $top);
		$pdf->Ln();
		
		if($current%26 == 0 && $current != 0 && count($studentinfo) > $current){
			$pdf->AddPage();
			paperattendance_drawcircles($pdf);
			
			$top = 35;
			$modulecount++;
			
			// Write the attendance description
			$pdf->SetFont('Helvetica', '', 12);
			$pdf->SetXY(20, 31);
			$pdf->Write(1, core_text::strtoupper($descriptionstr));
				
			
			// Logo UAI and Top QR
			$pdf->Image($logofilepath, 20, 15, 50);
			// Top QR
			$qrfilename = paperattendance_create_qr_image($qrstring.$modulecount, $qrpath);
			//echo $qrfilename."  ".$qrpath."<br>";
			$pdf->Image($qrpath."/".$qrfilename, 153, 5, 35);
			
			// Attendance info
			// Write teacher name.
			$leftprovisional = 75;
			$topprovisional = 7;
			$pdf->SetFont('Helvetica', 'B', 12);
			$pdf->SetXY($leftprovisional, $topprovisional);
			// Write course name.
			$topprovisional += 6;
			$pdf->SetFont('Helvetica', '', 8);
			$pdf->SetXY($leftprovisional, $topprovisional);
			$pdf->Write(1, core_text::strtoupper(get_string('course') . ': ' . $coursetrimmedtext));
			$topprovisional += 4;
			$pdf->SetXY($leftprovisional, $topprovisional);
			$pdf->Write(1, core_text::strtoupper(get_string('teacher', 'mod_emarking') . ': ' . $teachertrimmedtext));
			// Write requestor.
			$topprovisional += 4;
			$pdf->SetXY($leftprovisional, $topprovisional);
			$pdf->Write(1, core_text::strtoupper("Solicitante" . ': ' . $requestortrimmedtext));
			// Write date.
			$topprovisional += 4;
			$pdf->SetXY($leftprovisional, $topprovisional);
			$pdf->Write(1, core_text::strtoupper(get_string("date") . ': ' . date("d-m-Y", $sessiondate)));
			// Write modules.
			$topprovisional += 4;
			$pdf->SetXY($leftprovisional, $topprovisional);
			$pdf->Write(1, core_text::strtoupper("Modulos" . ': ' . $modulestrimmedtext));
			// Write number of students.
			$topprovisional += 4;
			$pdf->SetXY($leftprovisional, $topprovisional);
			$pdf->Write(1, core_text::strtoupper(get_string('students') . ': ' . count($studentinfo)));
			
			// Botton QR, messege to fill the circle and Logo Webcursos
			$pdf->Image($webcursospath, 18, 265, 35);
			
			$pdf->SetXY(70,264);
			$pdf->Write(1, "Recuerde NO utilizar Lápiz mina ni destacador,");
			$pdf->SetXY(70,268);
			$pdf->Write(1, "de lo contrario la  asistencia no quedará valida.");
			$pdf->SetXY(70,272);
			$pdf->Write(1, "Se recomienda rellenar así");
			$pdf->Image($goodcirlepath, 107, 272, 5, 5, "PNG", 0);
			
			$pdf->Image($qrpath."/".$qrfilename, 153, 256, 35);
			unlink($qrpath."/".$qrfilename);
		}
		
		$top += 8;
		$current++;
	}
	$pdf->line(20, $top, (20+8+25+20+90+20), $top);
}

function paperattendance_drawcircles($pdf){
	
	$w = $pdf -> GetPageWidth();
	$h = $pdf -> GetPageHeight();
	
	$top = 10;
	$left = 10;
	$width = $w - 20;
	$height = $h - 20;
	
	$style = array(
			'width' => 0.25,
			'cap' => 'butt',
			'join' => 'miter',
			'dash' => 0,
			'color' => array(
					0,
					0,
					0
			)
	);
	
	$pdf->Circle($left, $top, 9, 0, 360, 'F', $style, array(
			0,
			0,
			0
	));
	$pdf->Circle($left, $top, 4, 0, 360, 'F', $style, array(
			255,
			255,
			255
	));
	
	$pdf->Circle($left + $width, $top, 9, 0, 360, 'F', $style, array(
			0,
			0,
			0
	));
	$pdf->Circle($left + $width, $top, 4, 0, 360, 'F', $style, array(
			255,
			255,
			255
	));
	
	$pdf->Circle($left, $top + $height, 9, 0, 360, 'F', $style, array(
			0,
			0,
			0
	));
	$pdf->Circle($left, $top + $height, 4, 0, 360, 'F', $style, array(
			255,
			255,
			255
	));
	
	$pdf->Circle($left + $width, $top + $height, 9, 0, 360, 'F', $style, array(
			0,
			0,
			0
	));
	$pdf->Circle($left + $width, $top + $height, 4, 0, 360, 'F', $style, array(
			255,
			255,
			255
	));
}

function paperattendance_readpdf($path, $filename, $course){
	global $DB, $CFG;
	
	$return = array();
	$return["result"] = "false";
	$return["synced"] = "false";

	$context = context_course::instance($course);
	$objcourse = new stdClass();
	$objcourse -> id = $course;
	
	$studentlist = paperattendance_students_list($context ->id, $objcourse);	
	$sessid = paperattendance_get_sessionid($filename);
	
	// pre process pdf
	$pdf = new Imagick($path."/".$filename);
	$pdftotalpages = (int)$pdf->getNumberImages();	
	$pdfpages = array();
	
	//$debugpath = $CFG -> dirroot. "/local/paperattendance/test/";
	for($numpage = 0; $numpage < $pdftotalpages; $numpage++){
		$page = new Imagick();
		$page->setResolution( 300, 300);
		$page->readImage($path."/".$filename."[$numpage]");
		if(PHP_MAJOR_VERSION < 7){
			$page = $page->flattenImages(); 
		}else{
			$page->setImageBackgroundColor('white');
			$page->setImageAlphaChannel(11);
			$page->mergeImageLayers(imagick::LAYERMETHOD_FLATTEN);
		}
		$page->setImageType( imagick::IMGTYPE_GRAYSCALE );
		$page->setImageFormat('png');
		//$page->writeImage($debugpath."pdf_$numpage.pdf");
		$pdfpages[] = $page;
	}
	
	$countstudent = 1;
	$numberpage = 0;
	$factor = 0;
	$arrayalumnos = array();
	
	foreach ($studentlist as $student){
		$return["result"] = "true";
		
		// Page size
		$height = $pdfpages[$numberpage]->getImageHeight();
		$width = $pdfpages[$numberpage]->getImageWidth();
		
		if($numberpage == 0){
			$attendancecircle = $pdfpages[$numberpage]->getImageRegion(
					$width * 0.028,
					$height * 0.019,
					$width * 0.773,
					$height * (0.182 + 0.02640 * $factor)
			);
			//$attendancecircle->writeImage($debugpath.'student_'.$countstudent.' * '.$student->name.'.png');
			//echo "<br> Pagina 1: $numberpage estudiante $countstudent ".$student->name;
	
		}else{
			$attendancecircle = $pdfpages[$numberpage]->getImageRegion(
					$width * 0.028,
					$height * 0.0195,
					$width * 0.771,
					$height * (0.160 + 0.02640 * $factor)
			);
			//$attendancecircle->writeImage($debugpath.'student_'.$countstudent.' * '.$student->name.'.png');	
			//echo "<br> Pagina 2: $numberpage estudiante $countstudent ".$student->name;
		}
		
		$line = array();
		$line['emailAlumno'] = paperattendance_getusername($student->id);
		$line['resultado'] = "true";
		
		$graychannel = $attendancecircle->getImageChannelMean(Imagick::CHANNEL_GRAY);
		if($graychannel["mean"] < $CFG->paperattendance_grayscale){
			paperattendance_save_student_presence($sessid, $student->id, '1', $graychannel["mean"]);
			$line['asistencia'] = "true";
		}
		else{
			paperattendance_save_student_presence($sessid, $student->id, '0', $graychannel["mean"]);
			$line['asistencia'] = "false";
		}
		
		$arrayalumnos[] = $line;
		
		// 26 student per each page
		$numberpage = floor($countstudent/26);
		$attendancecircle->destroy();
		
		if($countstudent%26 == 0 && $countstudent != 1){
			$factor = $factor - 26;
		}
		
		$countstudent++;
		$factor++;
	}
	
	if(paperattendance_checktoken($CFG->paperattendance_omegatoken)){
		if(paperattendance_omegacreateattendance($course, $arrayalumnos, $sessid)){
			$return["synced"] = "true";
		}
	}
	
	return $return;
}

function paperattendance_get_sessionid($pdffile){
	global $DB;
	
	$query = "SELECT sess.id AS id
			FROM {paperattendance_session} AS sess
			WHERE pdf = ? ";
	$resultado = $DB->get_record_sql($query, array($pdffile));
	
	return $resultado -> id;
}

function paperattendance_save_student_presence($sessid, $studentid, $status, $grayscale = NULL){
	global $DB;
	
	$sessioninsert = new stdClass();
	$sessioninsert->sessionid = $sessid;
	$sessioninsert->userid = $studentid;
	$sessioninsert->status = $status;
	$sessioninsert->lastmodified = time();
	$sessioninsert->grayscale = $grayscale;
	$lastinsertid = $DB->insert_record('paperattendance_presence', $sessioninsert, false);
}


// //returns orientation {straight, rotated, error}
// //pdf = pdfname + extension (.pdf)
function paperattendance_get_orientation($path, $pdf, $page){
	//TODO: la pagina donde se utiliza la funcion debe incluir el require_once
	global $CFG;
	require_once ($CFG->dirroot . '/local/paperattendance/phpdecoder/QrReader.php');

	$pdfexplode = explode(".",$pdf);
	$pdfname = $pdfexplode[0];
	$qrpath = $pdfname.'qr.png';

	//save the pdf page as a png
	$myurl = $pdf.'['.$page.']';
	$image = new Imagick($path.$myurl);
	$image->setResolution(100,100);
	$image->setImageFormat( 'png' );
	$image->writeImage( $path.$pdfname.'.png' );
	$image->clear();

	//check if there's a qr on the top right corner
	$imagick = new Imagick();
	$imagick->setResolution(100,100);
	$imagick->readImage( $path.$pdfname.'.png' );
	$imagick->setImageType( imagick::IMGTYPE_GRAYSCALE );

	$height = $imagick->getImageHeight();
	$width = $imagick->getImageWidth();

	$qrtop = $imagick->getImageRegion($width*0.25, $height*0.14, $width*0.652, $height*0.014);
	$qrtop->writeImage($path."topright".$qrpath);
	
	unlink($path.$pdfname.'.png');
	// QR
	$qrcodetop = new QrReader($path."topright".$qrpath);
	$texttop = $qrcodetop->text(); //return decoded text from QR Code

	if($texttop == "" || $texttop == " " || empty($texttop)){

		//check if there's a qr on the bottom right corner
		$qrbottom = $imagick->getImageRegion($width*0.25, $height*0.14, $width*0.652, $height*0.846);
		$qrbottom->writeImage($path."bottomright".$qrpath);

		// QR
		$qrcodebottom = new QrReader($path."bottomright".$qrpath);
		$textbottom = $qrcodebottom->text(); //return decoded text from QR Code

		if($textbottom == "" || $textbottom == " " || empty($textbottom)){

			//check if there's a qr on the top left corner
			$qrtopleft = $imagick->getImageRegion($width*0.25, $height*0.14, $width*0.1225, $height*0.014);
			$qrtopleft->writeImage($path."topleft".$qrpath);

			// QR
			$qrcodetopleft = new QrReader($path."topleft".$qrpath);
			$texttopleft = $qrcodetopleft->text(); //return decoded text from QR Code

			if($texttopleft == "" || $texttopleft == " " || empty($texttopleft)){
					
				//check if there's a qr on the top left corner
				$qrbottomleft = $imagick->getImageRegion($width*0.25, $height*0.14, $width*0.1255, $height*0.846);
				$qrbottomleft->writeImage($path."bottomleft".$qrpath);
					
				// QR
				$qrcodebottomleft = new QrReader($path."bottomleft".$qrpath);
				$textbottomleft = $qrcodebottomleft->text(); //return decoded text from QR Code
					
				if($textbottomleft == "" || $textbottomleft == " " || empty($textbottomleft)){
					return "error";
				}
				else{
					return "rotated";
				}
			}
			else{
				return "rotated";
			}
		}
		else{
			return "straight";
		}
	}
	else{
		return "straight";
	}
	$imagick->clear();
}

function paperattendance_get_qr_text($path, $pdf){
	global $CFG, $DB;

	$pdfexplode = explode(".",$pdf);
	$pdfname = $pdfexplode[0];
	$qrpath = $pdfname.'qr.png';

	//Cleans up the pdf
	$myurl = $pdf.'[0]';
	$imagick = new Imagick();
	$imagick->setResolution(100,100);
	$imagick->readImage($path.$myurl);
	// hay que probar si es mas util hacerle el flatten aqui arriba o abajo de reduceNoiseImage()
	/*if(PHP_MAJOR_VERSION < 7){
		$imagick->flattenImages();
	}else{
		$imagick->setImageBackgroundColor('white');
		$imagick->setImageAlphaChannel(11);
		$imagick->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
	}*/
//	$imagick->despeckleImage();
	//$imagick->deskewImage(0.5);
//	$imagick->trimImage(2);
//	$imagick->enhanceImage();
	$imagick->setImageFormat( 'png' );
	$imagick->setImageType( Imagick::IMGTYPE_GRAYSCALE );
//	$imagick->normalizeImage($channel  = Imagick::CHANNEL_ALL );
//	$imagick->sharpenimage(0, 1, $channel);

	$height = $imagick->getImageHeight();
	$width = $imagick->getImageWidth();
	
	//$recortey = ($height - 2112)/2;
	//$recortex = ($width- 1272)/2;
	//$hashtime = time();
	//$imagick->writeImage( $path.'originalmihail'.$hashtime.'.png' );
	//$crop = $imagick->getImageRegion(1272, 2112, $recortex, $recortey);
	
	//$crop->writeImage( $path.'cropmihail'.$hashtime.'.png' );
	//$crop->trimImage(2);
	//esta es solamente para debuggiar, despues hay que borrarla por que no sirve
	//$crop->writeImage( $path.'trimmihail'.$hashtime.'.png' );
	//return "error";
	$qrtop = $imagick->getImageRegion(300, 300, $width*0.6, 0);
	//$qrtop->trimImage(2);
	$qrtop->writeImage($path."topright".$qrpath);
	
	// QR
	$qrcodetop = new QrReader($qrtop, QrReader::SOURCE_TYPE_RESOURCE);
	$texttop = $qrcodetop->text(); //return decoded text from QR Code
//	unlink($CFG -> dataroot. "/temp/local/paperattendance/unread/topright".$qrpath);
	
	if($texttop == "" || $texttop == " " || empty($texttop)){

		//check if there's a qr on the bottom right corner
		$qrbottom = $imagick->getImageRegion($width*0.14, $height*0.098, $width*0.710, $height*0.866);
		$qrbottom->trimImage(2);
//		$qrbottom->writeImage($path."bottomright".$qrpath);
		
		// QR
		$qrcodebottom = new QrReader($qrbottom, QrReader::SOURCE_TYPE_RESOURCE);
		$textbottom = $qrcodebottom->text(); //return decoded text from QR Code
		$imagick->clear();
//		unlink($CFG -> dataroot. "/temp/local/paperattendance/unread/bottomright".$qrpath);
		if($textbottom == "" || $textbottom == " " || empty($textbottom)){
			return "error";
		}
		else {
			return $textbottom;
		}
	}
	else {
		$imagick->clear();
		return $texttop;
	}
}


function paperattendance_insert_session($courseid, $requestorid, $userid, $pdffile, $description){
	global $DB;

	$sessioninsert = new stdClass();
	$sessioninsert->id = "NULL";
	$sessioninsert->courseid = $courseid;
	$sessioninsert->teacherid = $requestorid;
	$sessioninsert->uploaderid = $userid;
	$sessioninsert->pdf = $pdffile;
	$sessioninsert->status = 0;
	$sessioninsert->lastmodified = time();
	$sessioninsert->description = $description;
	$sessionid = $DB->insert_record('paperattendance_session', $sessioninsert);
	
	return $sessionid;
}


function paperattendance_insert_session_module($moduleid, $sessionid, $time){
	global $DB;

	$sessionmoduleinsert = new stdClass();
	$sessionmoduleinsert->id = "NULL";
	$sessionmoduleinsert->moduleid = $moduleid;
	$sessionmoduleinsert->sessionid = $sessionid;
	$sessionmoduleinsert->date = $time;
	
	if($DB->insert_record('paperattendance_sessmodule', $sessionmoduleinsert)){
		return true;
	}
	else{
		return false;
	}
}

//returns {perfect, repited}
function paperattendance_check_session_modules($arraymodules, $courseid, $time){
	global $DB;

	$verification = 0;
	$modulesexplode = explode(":",$arraymodules);
	list ( $sqlin, $parametros1 ) = $DB->get_in_or_equal ( $modulesexplode );
	
	$parametros2 = array($courseid, $time);
	$parametros = array_merge($parametros1,$parametros2);
	
	$sessionquery = "SELECT sess.id,
			sessmodule.id
			FROM {paperattendance_session} AS sess
			INNER JOIN {paperattendance_sessmodule} AS sessmodule ON (sessmodule.sessionid = sess.id)
			WHERE sessmodule.moduleid $sqlin AND sess.courseid = ?  AND sessmodule.date = ? ";
	
	$resultado = $DB->get_records_sql ($sessionquery, $parametros );
	if(count($resultado) == 0){
		return "perfect";
	}
	else{
		return "repited";
	}
}

function paperattendance_read_pdf_save_session($path, $pdffile, $qrtext){
	
	//path must end with "/"
	global $USER;

	if($qrtext != "error"){
		//if there's a readable qr

		$qrtextexplode = explode("*",$qrtext);
		$courseid = $qrtextexplode[0];
		$requestorid = $qrtextexplode[1];
		$arraymodules = $qrtextexplode[2];
		$time = $qrtextexplode[3];
		$page = $qrtextexplode[4];
		$description = $qrtextexplode[5];

		$verification = paperattendance_check_session_modules($arraymodules, $courseid, $time);
		if($verification == "perfect"){
			$pos = substr_count($arraymodules, ':');
			if ($pos == 0) {
				$module = $arraymodules;
				$sessionid = paperattendance_insert_session($courseid, $requestorid, $USER-> id, $pdffile, $description);
				$verification = paperattendance_insert_session_module($module, $sessionid, $time);
				if($verification == true){
					return "Perfect";
				}
				else{
					return "Error";
				}
			}
			else {
				$modulesexplode = explode(":",$arraymodules);

				for ($i = 0; $i <= $pos; $i++) {
						
					//for each module inside $arraymodules, save records.
					$module = $modulesexplode[$i];

					$sessionid = paperattendance_insert_session($courseid, $requestorid, $USER-> id, $pdffile, $description);
					$verification = paperattendance_insert_session_module($module, $sessionid, $time);
					if($verification == true){
						return "Perfect";
					}
					else{
						return "Error";
					}
				}
			}
		}
		else{
			//couldnt save session
			$return = get_string("couldntsavesession", "local_paperattendance");
			return $return;
		}
	}
	else{
			//couldnt read qr
			$return = get_string("couldntreadqrcode", "local_paperattendance");
			return $return;
	}
}

// //pdf = pdfname + extension (.pdf)
function paperattendance_rotate($path, $pdfname){
	
	//read pdf and rewrite it 
	$pdf = new FPDI();
	// get the page count
	$pageCount = $pdf->setSourceFile($path.$pdfname);
	// iterate through all pages
	for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
		//get page orientation
		$orientation = paperattendance_get_orientation($path, $pdfname,$pageNo-1);
	    // import a page
	    $templateId = $pdf->importPage($pageNo);
	    // get the size of the imported page
	    $size = $pdf->getTemplateSize($templateId);
	
	    // create a page (landscape or portrait depending on the imported page size)
	    if($orientation == "rotated"){
		    if ($size['w'] > $size['h']) {
		        $pdf->AddPage('L', array($size['w'], $size['h']),180);
		    } else {
		        $pdf->AddPage('P', array($size['w'], $size['h']),180);
		    }
	    }
	    else{
	    	if ($size['w'] > $size['h']) {
	    		$pdf->AddPage('L', array($size['w'], $size['h']));
	    	} else {
	    		$pdf->AddPage('P', array($size['w'], $size['h']));
	    	}
	    }
	    // use the imported page
	    $pdf->useTemplate($templateId);
	}
	
	if($pdf->Output($path.$pdfname, "F")){
		return true;
	}else{
		return false;
	}
}

function trim_text($input, $length, $ellipses = true, $strip_html = true) {
	//strip tags, if desired
	if ($strip_html) {
		$input = strip_tags($input);
	}

	//no need to trim, already shorter than trim length
	if (strlen($input) <= $length) {
		return $input;
	}

	//find last space within length
	$last_space = strrpos(substr($input, 0, $length), ' ');
	$trimmed_text = substr($input, 0, $last_space);

	//add ellipses (...)
	if ($ellipses) {
		$trimmed_text .= '...';
	}

	return $trimmed_text;
}

//function for deleting files from moodle data print folder
function paperattendance_recursiveremovedirectory($directory)
{
	foreach(glob("{$directory}/*") as $file)
	{
		if(is_dir($file)) {
			paperattendance_recursiveremovedirectory($file);
		} else {
			unlink($file);
		}
	}
	
	//this comand delete the folder of the path, in this case we only want to delete the files inside the folder
	//rmdir($directory);
}

//function for deleting pngs from moodle data unread folder
function paperattendance_recursiveremovepng($directory)
{
	foreach(glob("{$directory}/*.png") as $file)
	{
		if(is_dir($file)) {
			paperattendance_recursiveremovepng($file);
		} else {
			unlink($file);
		}
	}
	//this comand delete the folder of the path, in this case we only want to delete the files inside the folder
	//rmdir($directory);
}
function paperattendance_convertdate($i){
	//arrays of days and months
	$days = array(get_string('sunday', 'local_paperattendance'),get_string('monday', 'local_paperattendance'), get_string('tuesday', 'local_paperattendance'), get_string('wednesday', 'local_paperattendance'), get_string('thursday', 'local_paperattendance'), get_string('friday', 'local_paperattendance'), get_string('saturday', 'local_paperattendance'));
	$months = array("",get_string('january', 'local_paperattendance'), get_string('february', 'local_paperattendance'), get_string('march', 'local_paperattendance'), get_string('april', 'local_paperattendance'), get_string('may', 'local_paperattendance'), get_string('june', 'local_paperattendance'), get_string('july', 'local_paperattendance'), get_string('august', 'local_paperattendance'), get_string('september', 'local_paperattendance'), get_string('october', 'local_paperattendance'), get_string('november', 'local_paperattendance'), get_string('december', 'local_paperattendance'));
	
	$dateconverted = $days[date('w',$i)].", ".date('d',$i).get_string('of', 'local_paperattendance').$months[date('n',$i)].get_string('from', 'local_paperattendance').date('Y',$i);
	return $dateconverted;
}

function paperattendance_getteacherfromcourse($courseid, $userid){
	global $DB;
	$sqlteacher = "SELECT u.id
			FROM {user} AS u
			INNER JOIN {role_assignments} ra ON (ra.userid = u.id)
			INNER JOIN {context} ct ON (ct.id = ra.contextid)
			INNER JOIN {course} c ON (c.id = ct.instanceid AND c.id = ?)
			INNER JOIN {role} r ON (r.id = ra.roleid AND r.shortname IN ( ?, ?))
			WHERE u.id = ?";

	$teacher = $DB->get_record_sql($sqlteacher, array($courseid, 'teacher', 'editingteacher', $userid));

	if(!isset($teacher->id)){
		$teacher = $DB->get_record_sql($sqlteacher, array($courseid, 'profesoreditor', 'ayudante', $userid));
	}

	return $teacher;
}

function paperattendance_getstudentfromcourse($courseid, $userid){
	global $DB;
	$sqlstudent = "SELECT u.id
			FROM {user} AS u
			INNER JOIN {role_assignments} ra ON (ra.userid = u.id)
			INNER JOIN {context} ct ON (ct.id = ra.contextid)
			INNER JOIN {course} c ON (c.id = ct.instanceid AND c.id = ?)
			INNER JOIN {role} r ON (r.id = ra.roleid AND r.shortname = 'student')
			WHERE u.id = ?";

	$student = $DB->get_record_sql($sqlstudent, array($courseid,$userid));

	return $student;
}


function paperattendance_omegacreateattendance($courseid, $arrayalumnos, $sessid){
	global $DB,$CFG;
	
	if(paperattendance_checktoken($CFG->paperattendance_omegatoken)){
		//GET OMEGA COURSE ID FROM WEBCURSOS COURSE ID
		$omegaid = $DB->get_record("course", array("id" => $courseid));
		$omegaid = $omegaid -> idnumber;
		
		//GET FECHA & MODULE FROM SESS ID $fecha, $modulo,
		$sqldatemodule = "SELECT sessmodule.id, FROM_UNIXTIME(sessmodule.date,'%Y-%m-%d') AS sessdate, module.initialtime AS sesstime
						FROM {paperattendance_sessmodule} AS sessmodule
						INNER JOIN {paperattendance_module} AS module ON (sessmodule.moduleid = module.id AND sessmodule.sessionid = ?)";
		$datemodule = $DB->get_record_sql($sqldatemodule, array($sessid));
		
		$fecha = $datemodule -> sessdate;
		$modulo = $datemodule -> sesstime;
	
		//CURL CREATE ATTENDANCE OMEGA
		$curl = curl_init();

		$url =  $CFG->paperattendance_omegacreateattendanceurl;
		$token =  $CFG->paperattendance_omegatoken;
		mtrace("SESSIONID: " .$datemodule->id. "## Formato de fecha: " . $fecha . " Modulo " . $modulo);
		$fields = array (
				"token" => $token,
				"seccionId" => $omegaid,
				"diaSemana" => $fecha,
				"modulos" => array( array("hora" => $modulo) ),
				"alumnos" => $arrayalumnos
		);

		curl_setopt($curl, CURLOPT_URL, $url);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($curl, CURLOPT_POST, TRUE);
		curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($fields));
		curl_setopt($curl, CURLOPT_HTTPHEADER, array("Content-Type: application/json"));
		$result = curl_exec ($curl);
		curl_close ($curl);

		$alumnos = new stdClass();
		$alumnos = json_decode($result)->alumnos;
		
		$return = false;
		// FOR EACH STUDENT ON THE RESULT, SAVE HIS SYNC WITH OMEGA (true or false)
		for ($i = 0 ; $i < count($alumnos); $i++){
			if($alumnos[$i]->resultado == true){
				$return = true;
				// el estado es 0 por default, asi que solo update en caso de ser verdadero el resultado
					
				// get student id from its username
				$username = $alumnos[$i]->emailAlumno;
				$studentid = $DB->get_record("user", array("username" => $username));
				$studentid = $studentid -> id;
					
				$omegasessionid = $alumnos[$i]->asistenciaId;
				//save student sync
				$sqlsyncstate = "UPDATE {paperattendance_presence} SET omegasync = ?, omegaid = ? WHERE sessionid  = ? AND userid = ?";
				$studentid = $DB->execute($sqlsyncstate, array('1', $omegasessionid, $sessid, $studentid));
			}
		}
	}
	return $return;
}

function paperattendance_getusername($userid){
	global $DB;
	$username = $DB->get_record("user", array("id" => $userid));
	$username = $username -> username;
	return $username;
}

function paperattendance_omegaupdateattendance($update, $omegaid){
	global $CFG, $DB;
	
	if (paperattendance_checktoken($CFG->paperattendance_omegatoken)){
		//CURL UPDATE ATTENDANCE OMEGA
	
		$url =  $CFG->paperattendance_omegaupdateattendanceurl;
		$token =  $CFG->paperattendance_omegatoken;
	
		if($update == 1){
			$update = "true";
		}
		else{
			$update = "false";
		}
	
		$fields = array (
				"token" => $token,
				"asistenciaId" => $omegaid,
				"asistencia" => $update
		);
	
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_URL, $url);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($curl, CURLOPT_POST, TRUE);
		curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($fields));
		curl_setopt($curl, CURLOPT_HTTPHEADER, array("Content-Type: application/json"));
		$result = curl_exec ($curl);
		curl_close ($curl);
	}
}

function paperattendance_checktoken($token){
	if (!isset($token) || empty($token) || $token == "" || $token == null) {
		return false;
	}
	else{
		return true;
	}
}

function paperattendance_getcountstudentssynchronizedbysession($sessionid){
	//Query for the total count of synchronized students
	global $DB;
	$query = 'SELECT
				count(*)
				FROM {paperattendance_session} AS s
				INNER JOIN {paperattendance_presence} AS p ON (s.id = p.sessionid AND p.omegasync = ?)
				WHERE p.sessionid = ?';
	
	$attendancescount = $DB->count_records_sql($query, array(1, $sessionid));
	return $attendancescount;
	
}

function paperattendance_getcountstudentsbysession($sessionid){
	//Query for the total count of students in a session
	global $DB;
	$query = 'SELECT
				count(*)
				FROM {paperattendance_session} AS s
				INNER JOIN {paperattendance_presence} AS p ON (s.id = p.sessionid)
				WHERE p.sessionid = ?';

	$attendancescount = $DB->count_records_sql($query, array($sessionid));
	return $attendancescount;

}

function paperattendance_sendMail($attendanceid, $courseid, $teacherid, $uploaderid, $date, $course, $case) {
	GLOBAL $CFG, $USER, $DB;
	
	$teacher = $DB->get_record("user", array("id"=> $teacherid));
	$userfrom = core_user::get_noreply_user();
	$userfrom->maildisplay = true;
	$eventdata = new stdClass();
	switch($case){
		case "processpdf":
			//subject
			$eventdata->subject = get_string("processconfirmationbodysubject", "local_paperattendance");
			//process pdf message
			$messagehtml = "<html>";
			$messagehtml .= "<p>".get_string("dear", "local_paperattendance") ." ". $teacher->firstname . " " . $teacher->lastname . ",</p>";	
			$messagehtml .= "<p>".get_string("processconfirmationbody", "local_paperattendance") . "</p>";
			$messagehtml .= "<p>".get_string("datebody", "local_paperattendance") ." ". $date . "</p>";
			$messagehtml .= "<p>".get_string("coursebody", "local_paperattendance") ." ". $course . "</p>";
			$messagehtml .= "<p>".get_string("checkyourattendance", "local_paperattendance")." <a href='" . $CFG->wwwroot . "/local/paperattendance/history.php?action=studentsattendance&attendanceid=". $attendanceid ."&courseid=". $courseid ."'>" . get_string('historytitle', 'local_paperattendance') . "</a></p>";
			$messagehtml .= "</html>";
			
			$messagetext = get_string("dear", "local_paperattendance") ." ". $teacher->firstname . " " . $teacher->lastname . ",\n";
			$messagetext .= get_string("processconfirmationbody", "local_paperattendance") . "\n";
			$messagetext .= get_string("datebody", "local_paperattendance") ." ". $date . "\n";
			$messagetext .= get_string("coursebody", "local_paperattendance") ." ". $course . "\n";
			break;
		case "newdiscussionteacher":
			//subject
			$eventdata->subject = get_string("newdiscussionsubject", "local_paperattendance");
			//new discussion message
			$messagehtml = "<html>";
			$messagehtml .= "<p>".get_string("dear", "local_paperattendance") ." ". $teacher->firstname . " " . $teacher->lastname . ",</p>";
			$messagehtml .= "<p>".get_string("newdiscussion", "local_paperattendance") . "</p>";
			$messagehtml .= "<p>".get_string("sessiondate", "local_paperattendance") ." ". $date . "</p>";
			$messagehtml .= "<p>".get_string("coursebody", "local_paperattendance") ." ". $course . "</p>";
			$messagehtml .= "<p>".get_string("checkyourattendance", "local_paperattendance")." <a href='" . $CFG->wwwroot . "/local/paperattendance/discussion.php?action=view&courseid=". $courseid ."'>" . get_string('discussiontitle', 'local_paperattendance') . "</a></p>";
			$messagehtml .= "</html>";
				
			$messagetext = get_string("dear", "local_paperattendance") ." ". $teacher->firstname . " " . $teacher->lastname . ",\n";
			$messagetext .= get_string("newdiscussion", "local_paperattendance") . "\n";
			$messagetext .= get_string("sessiondate", "local_paperattendance") ." ". $date . "\n";
			$messagetext .= get_string("coursebody", "local_paperattendance") ." ". $course . "\n";
			break;
		case "newdiscussionstudent":
			//subject
			$eventdata->subject = get_string("newdiscussionsubject", "local_paperattendance");
			//new discussion message
			$messagehtml = "<html>";
			$messagehtml .= "<p>".get_string("dearstudent", "local_paperattendance") ." ". $teacher->firstname . " " . $teacher->lastname . ",</p>";
			$messagehtml .= "<p>".get_string("newdiscussionstudent", "local_paperattendance") . "</p>";
			$messagehtml .= "<p>".get_string("sessiondate", "local_paperattendance") ." ". $date . "</p>";
			$messagehtml .= "<p>".get_string("coursebody", "local_paperattendance") ." ". $course . "</p>";
			$messagehtml .= "<p>".get_string("checkyourattendance", "local_paperattendance")." <a href='" . $CFG->wwwroot . "/local/paperattendance/discussion.php?action=view&courseid=". $courseid ."'>" . get_string('discussiontitle', 'local_paperattendance') . "</a></p>";
			$messagehtml .= "</html>";
		
			$messagetext = get_string("dear", "local_paperattendance") ." ". $teacher->firstname . " " . $teacher->lastname . ",\n";
			$messagetext .= get_string("newdiscussionstudent", "local_paperattendance") . "\n";
			$messagetext .= get_string("sessiondate", "local_paperattendance") ." ". $date . "\n";
			$messagetext .= get_string("coursebody", "local_paperattendance") ." ". $course . "\n";
			break;
		case "newresponsestudent":
			//subject
			$eventdata->subject = get_string("newresponsesubject", "local_paperattendance");
			//new discussion message
			$messagehtml = "<html>";
			$messagehtml .= "<p>".get_string("dearstudent", "local_paperattendance") ." ". $teacher->firstname . " " . $teacher->lastname . ",</p>";
			$messagehtml .= "<p>".get_string("newresponsestudent", "local_paperattendance") . "</p>";
			$messagehtml .= "<p>".get_string("sessiondate", "local_paperattendance") ." ". $date . "</p>";
			$messagehtml .= "<p>".get_string("coursebody", "local_paperattendance") ." ". $course . "</p>";
			$messagehtml .= "<p>".get_string("checkyourattendance", "local_paperattendance")." <a href='" . $CFG->wwwroot . "/local/paperattendance/discussion.php?action=view&courseid=". $courseid ."'>" . get_string('discussiontitle', 'local_paperattendance') . "</a></p>";
			$messagehtml .= "</html>";
		
			$messagetext = get_string("dear", "local_paperattendance") ." ". $teacher->firstname . " " . $teacher->lastname . ",\n";
			$messagetext .= get_string("newresponsestudent", "local_paperattendance") . "\n";
			$messagetext .= get_string("sessiondate", "local_paperattendance") ." ". $date . "\n";
			$messagetext .= get_string("coursebody", "local_paperattendance") ." ". $course . "\n";
			break;
		case "newresponseteacher":
			//subject
			$eventdata->subject = get_string("newdiscussionsubject", "local_paperattendance");
			//new discussion message
			$messagehtml = "<html>";
			$messagehtml .= "<p>".get_string("dear", "local_paperattendance") ." ". $teacher->firstname . " " . $teacher->lastname . ",</p>";
			$messagehtml .= "<p>".get_string("newresponse", "local_paperattendance") . "</p>";
			$messagehtml .= "<p>".get_string("sessiondate", "local_paperattendance") ." ". $date . "</p>";
			$messagehtml .= "<p>".get_string("coursebody", "local_paperattendance") ." ". $course . "</p>";
			$messagehtml .= "<p>".get_string("checkyourattendance", "local_paperattendance")." <a href='" . $CFG->wwwroot . "/local/paperattendance/discussion.php?action=view&courseid=". $courseid ."'>" . get_string('discussiontitle', 'local_paperattendance') . "</a></p>";
			$messagehtml .= "</html>";
		
			$messagetext = get_string("dear", "local_paperattendance") ." ". $teacher->firstname . " " . $teacher->lastname . ",\n";
			$messagetext .= get_string("newresponse", "local_paperattendance") . "\n";
			$messagetext .= get_string("sessiondate", "local_paperattendance") ." ". $date . "\n";
			$messagetext .= get_string("coursebody", "local_paperattendance") ." ". $course . "\n";
			break;
	}
	
	$eventdata->component = "local_paperattendance"; // your component name
	$eventdata->name = "paperattendance_notification"; // this is the message name from messages.php
	$eventdata->userfrom = $userfrom;
	$eventdata->userto = $teacherid;
	$eventdata->fullmessage = $messagetext;
	$eventdata->fullmessageformat = FORMAT_HTML;
	$eventdata->fullmessagehtml = $messagehtml;
	$eventdata->smallmessage = get_string("processconfirmationbodysubject", "local_paperattendance");
	$eventdata->notification = 1; // this is only set to 0 for personal messages between users
	message_send($eventdata);
}

function paperattendance_uploadattendances($file, $path, $filename, $context, $contextsystem){
	global $DB, $OUTPUT, $USER;
	$attendancepdffile = $path ."/unread/".$filename;
	$originalfilename = $file->get_filename();
	$file->copy_content_to($attendancepdffile);
	//first check if there's a readable QR code
	$qrtext = paperattendance_get_qr_text($path."/unread/", $filename);
	if($qrtext == "error"){
		//delete the unused pdf
		unlink($attendancepdffile);
		return $OUTPUT->notification(get_string("filename", "local_paperattendance").$originalfilename."<br>".get_string("couldntreadqrcode", "local_paperattendance"));
	}
	//read pdf and rewrite it
	$pdf = new FPDI();
	// get the page count
	$pagecount = $pdf->setSourceFile($attendancepdffile);
	if($pagecount){
		$idcourseexplode = explode("*",$qrtext);
		$idcourse = $idcourseexplode[0];
		
		//now we count the students in course
		$course = $DB->get_record("course", array("id" => $idcourse));
		$coursecontext = context_coursecat::instance($course->category);
		$students = paperattendance_students_list($coursecontext->id, $course);
		
		$count = count($students);
		$pages = ceil($count/26);
		if ($pages != $pagecount){
			unlink($attendancepdffile);
			return $OUTPUT->notification(get_string("filename", "local_paperattendance").$originalfilename."<br>".get_string("missingpages", "local_paperattendance"));
		}
		// iterate through all pages
		for ($pageno = 1; $pageno <= $pagecount; $pageno++) {
			// import a page
			$templateid = $pdf->importPage($pageno);
			// get the size of the imported page
			$size = $pdf->getTemplateSize($templateid);
	
			// create a page (landscape or portrait depending on the imported page size)
			if ($size['w'] > $size['h']) {
				$pdf->AddPage('L', array($size['w'], $size['h']));
			} else {
				$pdf->AddPage('P', array($size['w'], $size['h']));
			}
	
			// use the imported page
			$pdf->useTemplate($templateid);
		}
		$pdf->Output($attendancepdffile, "F"); // Se genera el nuevo pdf.
	
		$fs = get_file_storage();
	
		$file_record = array(
				'contextid' => $contextsystem->id,
				'component' => 'local_paperattendance',
				'filearea' => 'draft',
				'itemid' => 0,
				'filepath' => '/',
				'filename' => $filename,
				'timecreated' => time(),
				'timemodified' => time(),
				'userid' => $USER->id,
				'author' => $USER->firstname." ".$USER->lastname,
				'license' => 'allrightsreserved'
		);
	
		// If the file already exists we delete it
		if ($fs->file_exists($contextsystem->id, 'local_paperattendance', 'draft', 0, '/', $filename)) {
			$previousfile = $fs->get_file($context->id, 'local_paperattendance', 'draft', 0, '/', $filename);
			$previousfile->delete();
		}
	
		// Info for the new file
		$fileinfo = $fs->create_file_from_pathname($file_record, $attendancepdffile);
	
		//rotate pages of the pdf if necessary
		//paperattendance_rotate($path."/unread/", "paperattendance_".$courseid."_".$time.".pdf");
	
		//read pdf and save session and sessmodules
		$pdfprocessed = paperattendance_read_pdf_save_session($path."/unread/", $filename, $qrtext);
	
		if($pdfprocessed == "Perfect"){
			//delete unused pdf
			return $OUTPUT->notification(get_string("filename", "local_paperattendance").$originalfilename."<br>".get_string("uploadsuccessful", "local_paperattendance"), "notifysuccess");
		}
		else{
			//delete unused pdf
			unlink($attendancepdffile);
			return $OUTPUT->notification(get_string("filename", "local_paperattendance").$originalfilename."<br>".$pdfprocessed);
		}
	}
	else{
		//delete unused pdf
		unlink($attendancepdffile);
		return $OUTPUT->notification("File name: ".$originalfilename."<br>".get_string("pdfextensionunrecognized", "local_paperattendance"));
	}
}
function paperattendance_synctask($courseid, $sessionid){
	global $DB, $CFG;

	$return = false;

	// Sql that brings the unsynced students
	$sqlstudents = "SELECT p.id, p.userid AS userid, p.status AS status, s.username AS username
	 				FROM {paperattendance_presence} AS p
					INNER JOIN {user} AS s on ( p.userid = s.id AND p.sessionid = ? )";
	
	if($resources = $DB->get_records_sql($sqlstudents, array($sessionid))){
	
		$arrayalumnos = array();
	
		foreach ($resources as $student){
	
			$line = array();
			$line['emailAlumno'] = $student-> username;
			$line['resultado'] = "true";
	
			if($student->status == 1){
				$line['asistencia'] = "true";
			}
			else{
				$line['asistencia'] = "false";
			}
	
			$arrayalumnos[] = $line;
		}
	
		if(paperattendance_omegacreateattendance($courseid, $arrayalumnos, $sessionid)){
			$return = true;
		}
	}
	return $return;
}

function paperattendance_history_tabs($courseid) {
	$tabs = array();
	// Create sync
	$tabs[] = new tabobject(
			"attendancelist",
			new moodle_url("/local/paperattendance/history.php", array("courseid"=>$courseid)),
			get_string("historytitle", "local_paperattendance")
			);
	// Records.
	$tabs[] = new tabobject(
			"studentssummary",
			new moodle_url("/local/paperattendance/summary.php", array("courseid"=>$courseid)),
			get_string("summarytitle", "local_paperattendance")
			);
	// Export.
	$tabs[] = new tabobject(
			"export",
			new moodle_url("/local/paperattendance/export.php", array("courseid"=>$courseid)),
			get_string("exporttitle", "local_paperattendance")
			);
	return $tabs;
}

function paperattendance_returnattendancedescription($all, $descriptionnumber=null){
	if(!$all){
	$descriptionsarray = array(get_string('class', 'local_paperattendance'),
			get_string('assistantship', 'local_paperattendance'),
			get_string('extraclass', 'local_paperattendance'),
			get_string('test', 'local_paperattendance'),
			get_string('quiz', 'local_paperattendance'),
			get_string('exam', 'local_paperattendance'),
			get_string('labs', 'local_paperattendance'));
	
	return $descriptionsarray[$descriptionnumber];
	}
	else{
		$descriptionsarray = array(
				array('name'=>'class', 'string'=>get_string('class', 'local_paperattendance')),
				array('name'=>'assistantship', 'string'=>get_string('assistantship', 'local_paperattendance')),
				array('name'=>'extraclass', 'string'=>get_string('extraclass', 'local_paperattendance')),
				array('name'=>'test', 'string'=>get_string('test', 'local_paperattendance')),
				array('name'=>'quiz', 'string'=>get_string('quiz', 'local_paperattendance')),
				array('name'=>'exam', 'string'=>get_string('exam', 'local_paperattendance')),
				array('name'=>'labs', 'string'=>get_string('labs', 'local_paperattendance')));
		
		return $descriptionsarray;
	}
}

function paperattendance_cronlog($task, $result = NULL, $timecreated, $executiontime = NULL){
	global $DB;
	$cronlog = new stdClass();
	$cronlog->task = $task;
	$cronlog->result = $result;
	$cronlog->timecreated = $timecreated;
	$cronlog->executiontime = $executiontime;
	$DB->insert_record('paperattendance_cronlog', $cronlog);
	
}

function paperattendance_exporttoexcel($title, $header, $filename, $data, $descriptions, $dates, $tabs){
	global $CFG;
	$workbook = new MoodleExcelWorkbook("-");
	$workbook->send($filename);
	foreach ($tabs as $index=>$tab){
		$attxls = $workbook->add_worksheet($tab);
		$i = 0; //y axis
		$j = 0;//x axis
		$titleformat = $workbook->add_format();
		$titleformat->set_bold(1);
		$titleformat->set_size(12);
		$attxls->write($i,$j,$title,$titleformat);
		$i = 1;
		$j = 3;
		$headerformat = $workbook->add_format();
		$headerformat->set_bold(1);
		$headerformat->set_size(10);
		foreach ($descriptions[$index] as $descr){
			$attxls->write($i, $j, $descr, $headerformat);
			$j++;
		}
		$i = 2;
		$j = 3;
		foreach ($dates[$index] as $date){
			$attxls->write($i, $j, $date, $headerformat);
			$j++;
		}
		$i= 3;
		$j = 0;
		foreach($header[$index] as $cell){
			$attxls->write($i, $j, $cell, $headerformat);
			$j++;
		}
		$i=4;
		$j=0;
		foreach ($data[$index] as $row){
			foreach($row as $cell){
				$attxls->write($i, $j,$cell);
				$i++;
			}
			$j++;
			$i=4;
		}
	}
	$workbook->close();
	exit;
}

function paperattendance_read_csv($path, $csvname){
	global $DB, $CFG, $USER;

	$omegafailures = array();
	$row = 0;
	if (($handle = fopen($path."/".$csvname, "r")) !== FALSE) {
		while (($data = fgetcsv($handle, 50, ";")) !== FALSE) {
			if($row > 0){
				$filename = $data[0];
				$qrcode = $data[27];

				$qrinfo = explode("*",$qrcode);
				$course = $qrinfo[0];
				$requestorid = $qrinfo[1];
				$module = $qrinfo[2];
				$time = $qrinfo[3];
				$page = $qrinfo[4];
				$description = $qrinfo[5];

				$context = context_course::instance($course);
				$objcourse = new stdClass();
				$objcourse -> id = $course;
				$studentlist = paperattendance_students_list($context->id, $objcourse);

				$sessdoesntexist = paperattendance_check_session_modules($module, $course, $time);
				if( $sessdoesntexist == "perfect"){
					$sessid = paperattendance_insert_session($course, $requestorid, $USER-> id, $filename, $description);
					paperattendance_insert_session_module($module, $sessid, $time);
					foreach ($studentlist as $student){
						paperattendance_save_student_presence($sessid, $student->id, '0', NULL); //save all students as absents at first
					}
				}
				else{
					$sessid = $sessdoesntexist; //if session exist, then $sessdoesntexist contains the session id
				}

				$arrayalumnos = array();
				$init = ($page-1)*26+1;
				$end = $page*26;
				$count = 0; //start at one because $data[0] is the filename
				foreach ($studentlist as $student){
					if($count>=$init && $count<=$end){
						$line = array();
						$line['emailAlumno'] = paperattendance_getusername($student->id);
						$line['resultado'] = "true";
						$line['asistencia'] = "false";

						if($data[$count] == 'A'){
							paperattendance_save_student_presence($sessid, $student->id, '1', NULL);
							$line['asistencia'] = "true";
						}

						$arrayalumnos[] = $line;
					}
					$count++;
				}
				if(paperattendance_checktoken($CFG->paperattendance_omegatoken)){
					if(!paperattendance_omegacreateattendance($course, $arrayalumnos, $sessid)){
						$omegafailures[] = $sessid;
					}
				}

			}
			$row++;
		}
		fclose($handle);
	}
	return true;
}

