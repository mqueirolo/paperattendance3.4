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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.
/**
 *
*
* @package    local
* @subpackage paperattendance
* @copyright  2016 Jorge Cabané (jcabane@alumnos.uai.cl)
* @copyright  2016 Hans Jeria (hansjeria@gmail.com)
* @copyright  2016 Matías Queirolo (mqueirolo@alumnos.uai.cl)
* @copyright  2016 Cristobal Silva (cristobal.isilvap@gmail.com)
* @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
*/

require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
require_once($CFG->dirroot . '/local/paperattendance/locallib.php');

global $DB, $OUTPUT, $USER;
// User must be logged in.
require_login();
if (isguestuser()) {
	print_error(get_string('notallowedprint', 'local_paperattendance'));
	die();
}

$categoryid = optional_param('categoryid', 1, PARAM_INT);
$action = optional_param('action', 'viewform', PARAM_TEXT);
//Page
$page = optional_param('page', 0, PARAM_INT);
$perpage = 30;

if(is_siteadmin()){
	$sqlcourses = "SELECT c.id,
				c.fullname,
				cat.name,
				CONCAT( u.firstname, ' ', u.lastname) as teacher
				FROM {user} AS u
				INNER JOIN {role_assignments} ra ON (ra.userid = u.id)
				INNER JOIN {context} ct ON (ct.id = ra.contextid)
				INNER JOIN {course} c ON (c.id = ct.instanceid)
				INNER JOIN {role} r ON (r.id = ra.roleid AND r.id IN ( 3, 4))
				INNER JOIN {course_categories} as cat ON (cat.id = c.category)
				WHERE c.timecreated > ? AND c.idnumber > 0
				GROUP BY c.id
				ORDER BY c.fullname";
	$year = strtotime("1 January".(date('Y')));
	$ncourses = count($DB->get_records_sql($sqlcourses, array($year)));
	$courses = $DB->get_records_sql($sqlcourses, array($year), $page*$perpage,$perpage);
	$path = 1;
	$categoryid = 406;
}
else{
	//Query to get the category of the secretary
	//It is assumed that one secretary has just one category on her charge
	$sqlcategory = "SELECT cc.*
					FROM {course_categories} cc
					INNER JOIN {role_assignments} ra ON (ra.userid = ?)
					INNER JOIN {role} r ON (r.id = ra.roleid)
					INNER JOIN {context} co ON (co.id = ra.contextid)
					WHERE cc.id = co.instanceid AND r.shortname = ?";
	$categoryparams = array($USER->id, "secrepaper");
	$category = $DB->get_record_sql($sqlcategory, $categoryparams);
	if($category){
		$categoryid = $category->id;
	}else{
		print_error(get_string('notallowedprint', 'local_paperattendance'));
	}
	
	$path = $categoryid;
	$sqlcourses =   "SELECT c.id,
		c.fullname,
		cat.name,
		CONCAT( u.firstname, ' ', u.lastname) as teacher
		FROM {user} AS u
		INNER JOIN {role_assignments} ra ON (ra.userid = u.id)
		INNER JOIN {context} ct ON (ct.id = ra.contextid)
		INNER JOIN {course} c ON (c.id = ct.instanceid)
		INNER JOIN {role} r ON (r.id = ra.roleid AND r.id IN ( 3, 4))
		INNER JOIN {course_categories} as cat ON (cat.id = c.category)
		WHERE cat.path like ? AND c.idnumber > 0
		GROUP BY c.id
		ORDER BY c.fullname";
	
	$ncourses = count($DB->get_records_sql($sqlcourses, array("%/".$path."%")));
	$courses = $DB->get_records_sql($sqlcourses, array("%/".$path."%"), $page*$perpage,$perpage);
}

$context = context_coursecat::instance($categoryid);
$contextsystem = context_system::instance();

if (! has_capability('local/paperattendance:printsearch', $context) && ! has_capability('local/paperattendance:printsearch', $contextsystem)) {
	print_error(get_string('notallowedprint', 'local_paperattendance'));
}

// This page url.
$url = new moodle_url('/local/paperattendance/printsearch.php', array(
		"categoryid" => $categoryid
));

$pagetitle = get_string('printtitle', 'local_paperattendance');
$PAGE->navbar->add(get_string('printtitle', 'local_paperattendance'));
$PAGE->navbar->add(get_string('printtitle', 'local_paperattendance'),$url);
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('standard');
$PAGE->set_title($pagetitle);
// Require jquery for modal.
$PAGE->requires->jquery();
$PAGE->requires->jquery_plugin ( 'ui' );
$PAGE->requires->jquery_plugin ( 'ui-css' );
$PAGE->requires->js( new moodle_url('https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js') );
//string for print
$print = get_string("downloadprint", "local_paperattendance");
// Creating tables and adding columns header.
$table = new html_table();
$table->head = array(get_string('hashtag', 'local_paperattendance'),
		get_string('course', 'local_paperattendance'),
		get_string('teacher', 'local_paperattendance'),
		get_string('category', 'local_paperattendance'),
		'custom print',
		'quick print'
);
$table->id = "fbody";

$coursecount = $page*$perpage+1;
foreach($courses as $course){
	$printurl = new moodle_url('/local/paperattendance/print.php', array(
			'courseid' => $course->id
	));
	$historyurl = new moodle_url('/local/paperattendance/history.php', array(
			'courseid' => $course->id
	));
	$table->data[] = array(
			$coursecount,
			html_writer::nonempty_tag("a", $course->fullname, array("href"=>$historyurl)),
			$course->teacher,
			$course->name,
			html_writer::nonempty_tag("a", $print, array("href"=>$printurl)),
			html_writer::nonempty_tag("i", ' ', array("class"=>"icon icon-plus listcart", "courseid"=>$course->id))
	);
	$coursecount++;
}
echo $OUTPUT->header();
echo html_writer::div(get_string("searchprinthelp","local_paperattendance"),"alert alert-info", array("role"=>"alert"));
$filterinput = html_writer::empty_tag("input", array( "id"=>"filter", "type"=>"text", "style"=>"width:25%"));
echo html_writer::div($filterinput, "topbarmenu");

if ($ncourses>0){
	echo html_writer::table($table);
	echo $OUTPUT->paging_bar($ncourses, $page, $perpage, $url);
}
echo $OUTPUT->footer();

?>

<div class="modal fade bs-example-modal-lg" id="exampleModal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        <h4 class="modal-title" id="exampleModalLabel">Quick Print</h4>
      </div>
      <div class="modal-body quickprintappend" style="height:70vh">

      </div>
      <div class="modal-footer">
      	<button type="button" class="btn btn-info printbutton" data-dismiss="modal">Imprimir</button>
        <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>
<script>
jQuery('#exampleModal').modal({
	  keyboard: true,
	  show: false
})
</script>
<script type="text/javascript">
	var filter = $('#filter');
	var $table = $("#fbody").find("tbody");
	var $paging = $(".paging");
	filter.keyup(function(event){
		if(this.value.length >= 3 ){
			$table.find("tr").not(".ajaxtr").hide();
			$paging.hide();
		    var data = this.value;
		    var path = <?php echo $path;?>;
		    var print = <?php echo json_encode($print);?>;
			var categoryid = <?php echo $categoryid; ?>;
		    callAjax(data, path, print, categoryid);
		}
		else{
			$table.find("tr").not(".ajaxtr").show();
			$paging.show();
		}
		$(".ajaxtr").remove();
	});
	function callAjax(data, path, print, categoryid) {
		
		var count = 1;
		$.getJSON("ajax/ajaxquerys.php?result="+data+"&path="+path+"&category="+categoryid+"&action=getcourses", function(result){
			$(".ajaxtr").remove();
	        $.each(result, function(i, field){
	        	var printicon = "<a href='print.php?courseid="+field['id']+"&categoryid="+path+"'>"+print+"</a>"; 
	        	$table.append("<tr class='ajaxtr'><td>"+count+"</td><td>"+field['fullname']+"</td><td>"+field['teacher']+"</td><td>"+field['name']+"</td><td>"+printicon+"</td><td><i class='icon icon-plus listcart' courseid='"+field['id']+"'></i></td></tr>");
				count++;	
	        });
    	});
	}

	$( document ).on( "click", ".listcart", function() {
		$(this).removeClass('icon-plus').addClass('icon-ok');
		var courseid = $(this).attr('courseid');

		$('.quickprintappend').html('<center><img src="img/loading.gif"></center>');

		$.ajax({
		    type: 'POST',
		    url: 'quickprint.php',
		    data: {
			      'courseid' : courseid
		    	},
		    success: function (response) {
				$('.quickprintappend').html(response);
				if(response == "There's nothing to print for today"){
					$('.printbutton').attr("disabled", true);
		    	}  	
				else{
					$('.printbutton').removeAttr("disabled");
				}
		    }
		});
		
		jQuery('#exampleModal').modal('show'); 
	
		});

	$( document ).on( "click", ".modal-backdrop", function() {
		jQuery('#exampleModal').modal('hide'); 
	});
</script>
