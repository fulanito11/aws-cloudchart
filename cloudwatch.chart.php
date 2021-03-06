<?php
require_once dirname(__FILE__) . 'AWS-sdk/sdk.class.php';

$cw = new AmazonCloudWatch();
$cw->set_region(AmazonCloudWatch::REGION_US_E1);

function compare( $v1, $v2) {
	if($v1['Timestamp'] == $v2['Timestamp'])
		return 0;
	return ($v1['Timestamp'] > $v2['Timestamp'])?1:-1;
}

function getCloudWatchData($namespace, $metric, $fromtime, $endtime, $period, $result, $unit, $dimensions, $multiplier) {
	$opt = array();
	$opt['Dimensions'] = array();
	foreach ($dimensions as $Name => $Value)
	{
		array_push($opt['Dimensions'], array('Name'=>$Name, 'Value'=>$Value));
	}
	global $cw;
	$response = $cw->get_metric_statistics($namespace, $metric, $fromtime, $endtime, $period,$result, $unit,$opt);
	$data = array();
	foreach ($response->body->GetMetricStatisticsResult->Datapoints->member as $item)
	{
		if (empty($item)) continue;
		$p = array();
		$p['Timestamp'] = (integer) strtotime((string) $item->Timestamp)*1000;
		$p[$result] = (float)$item->$result * $multiplier;
		array_push($data,$p);
	}
	usort($data,"compare");
	return $data;
}

function getCloudWatchChartData($namespace, $metric, $fromtime, $endtime, $period, $result, $unit, $dimensions, $multiplier, $graph_variable_name, $graph_label_name, $graph_color, $graph_chart_name) {
	$data = array(
			'data' => getCloudWatchData($namespace, $metric, $fromtime, $endtime, $period, $result, $unit, $dimensions, $multiplier),
			'name' => $graph_variable_name,
			'label' => $graph_label_name,
			'color' => $graph_color,
			'result' => $result,
			'chart_name' => $graph_chart_name
			);
	return $data;
}

function print_raw_graph_data($graph_data, $value) 
{
	foreach ($graph_data as $data)
	{
		print "[".$data['Timestamp'].", ".$data[$value]."],";
	}
}

$graph_data = array();

function load_graph_data() {
	global $graph_data, $chart_parameters;
	//getCloudWatchChartData($namespace, $metric, $fromtime, $endtime, $period, $result, $unit, $dimensions, $multiplier, $graph_variable_name, $graph_label_name, $graph_color,$graph_chart_name)
	foreach ($chart_parameters as $chart_name => $chart) {
		array_push($graph_data, getCloudWatchChartData($chart['namespace'], $chart['metric'], $chart['fromtime'], $chart['endtime'], $chart['period'], $chart['result'], $chart['unit'], $chart['dimensions'], $chart['multiplier'], $chart['graph_variable_name'], $chart['graph_label_name'], $chart['graph_color'], $chart['chart_name']));
	}
}

$chart_data = array();

function load_chart_data() {
	global $chart_parameters, $chart_data;
	foreach ($chart_parameters as $chart_name => $data)
	{
		if(!in_array($data['chart_name'], $chart_data)) {
			array_push($chart_data,$data['chart_name']);
		}
	}
}

//print chart function
function print_cloudwatch_charts() {

load_graph_data();

global $chart_data;

//Print document.onload javascript block
print <<< JSCRIPT
<script type="text/javascript">
$(document).ready(function() {
JSCRIPT;

//Print raw data variables
global $graph_data;
foreach ($graph_data as $data) {
print "var ". $data['name']." = [";
print_raw_graph_data($data['data'], $data['result']);
print "];\n";
}

foreach($chart_data as $chart_name) {
	print "var ".$chart_name."_series = []\n";
}

$counter = array();
global $chart_parameters;
foreach ($graph_data as $data) {
	if(!isset($counter[$data['chart_name']])) {
		$counter[$data['chart_name']] = 0;
	}

	print $data['chart_name']."_series.push({ color: \"".$data['color']."\", label : \"".$data['label']."\", data : ".$data['name'].", shadowSize: 3});";
	$chart_parameters[$data['name']]['graph_index'] = $counter[$data['chart_name']]; 
	$counter[$data['chart_name']] = $counter[$data['chart_name']] + 1;
}

//Print plot chart
print <<< PLOTCHART
var plotoptions = {
grid: {
borderWidth: 1,
minBorderMargin: 20,
labelMargin: 10,
backgroundColor: {
colors: ["#fff", "#ecf6fe"]
},
hoverable: true,
mouseActiveRadius: 50,
margin: {
top: 8,
bottom: 20,
left: 20
},
markings: function(axes) {
var markings = [];
var xaxis = axes.xaxis;
for (var x = Math.floor(xaxis.min); x < xaxis.max; x += xaxis.tickSize * 2) {
	markings.push({ xaxis: { from: x, to: x + xaxis.tickSize }, color: "rgba(232, 232, 255, 0.2)" });
}
return markings;
}
},
yaxis: {},
	xaxis: { mode: "time",timeformat: "%m/%d/%y<br/>%H:%M:%S"},
	"lines": {"show": "true"},
	"points": {"show": "true"},
	clickable:true,
	hoverable: true
};

PLOTCHART;
foreach($chart_data as $chart_name) {
	print "var ".$chart_name."_plot = $.plot($(\"#".$chart_name."_div\"),".$chart_name."_series,plotoptions);";
}

print <<< PLOTCHART
//var plotchartvar = $.plot($("#plotchart"),series,plotoptions);

function showTooltip(x, y, contents) {
	$('<div id="tooltip">' + contents + '</div>').css( {
position: 'absolute',
display: 'none',
top: y + 5,
left: x + 5,
border: '1px solid #fdd',
padding: '2px',
'background-color': '#fee',
opacity: 0.80
}).appendTo("body").fadeIn(200);
}

var previousPoint = null;
$(".chart").bind("plothover", function (event, pos, item) {
if (item) {
if (previousPoint != item.datapoint) {
previousPoint = item.datapoint;

$("#tooltip").remove();
var t = item.datapoint[0].toFixed(2),
y = item.datapoint[1].toFixed(2);

var d = new Date(0);
d.setUTCSeconds(t/1000);
showTooltip(item.pageX, item.pageY,
item.series.label + "<br/><b>" + y + "</b><br/>" + d);
}
}
else {
$("#tooltip").remove();
clicksYet = false;
previousPoint = null;        
}
});
PLOTCHART;


//Print update chart logic
print <<< UPDATECHART
function updateChart(chart, chart_data, chart_index, chart_result, series, plotchartvar) {
	$.ajax({
url: 'http://184.106.157.233/aws/updatedmindata.php',
data: {'chart': chart },
success: function(data) {
res = JSON.parse(data);
if(res.Status == 200) {
if(chart_data[chart_data.length-1][0] < res.Timestamp) {
chart_data.shift();
chart_data.push([res.Timestamp, res[chart_result]]);
series[chart_index].data = chart_data;
plotchartvar.setData(series);
plotchartvar.setupGrid();
plotchartvar.draw();
}           
}
}
});
}
UPDATECHART;

foreach($chart_parameters as $graph_name => $graph) {
	print "setInterval(function() { updateChart('".$graph_name."',".$graph['graph_variable_name'].",".$graph['graph_index'].",'".$graph['result']."',".$graph['chart_name']."_series,".$graph['chart_name']."_plot);}, ".$graph['period']*1000.");\n";
}

//Complete javascript block
print <<< JSCRIPT
});
</script>
JSCRIPT;
}

//add dimension data here
$dimension = array(
		'PROD_LOAD_BALANCER' => array('LoadBalancerName'=>'myapp-prod-elb'),
		'PROD_SECURITY_GROUP' => array('Distribution' => 'PRODUCTION', 'SecurityGroupName'=>'myapp-prod'),
		);
//add chart data to the array
$chart_parameters = array(
		'request_count_data' => array(
			'namespace' => 'AWS/ELB',				//Namespace
			'metric' => 'RequestCount', 				//Metric name
			'fromtime' => '-4 hour', 				//from time when chart should be plotted, let the time be relative. Check http://php.net/manual/en/function.strtotime.php for more deatils on supported formats
			'endtime' => 'now', 					//Until when the chart should be plotted
			'period' => 60, 					//Minimum interval between two plots on graph in seconds ( >= 60 )
			'result' => 'Sum', 					//Aggregation of required metric
			'unit' => 'Count', 					//Unit of metric
			'dimensions' => $dimension['PROD_LOAD_BALANCER'], 	//Filter based on dimensions - Define the value on $dimension array above and use the name here
			'multiplier' => 1, 					//If the value of plot has to be multiplied before plotting on chart
			'graph_variable_name' => "request_count_data", 		// ** Please set the value of the variable to be key value of this element in $chart_parameters array
			'graph_label_name' => "Request count", 			//Label name when plotted on chart
			'graph_color' => "#EDC240",				//Color of chart to be plotted
			'chart_name' => "d1"					//id of html <div> where the chart has to be plotted, divname
			),

		'latency_data' => array(
			'namespace' => 'AWS/ELB',
			'metric' => 'Latency',
			'fromtime' => '-4 hour',
			'endtime' => 'now',
			'period' => 60,
			'result' => 'Maximum',
			'unit' => 'Seconds',
			'dimensions' => $dimension['PROD_LOAD_BALANCER'],
			'multiplier' => 1,
			'graph_variable_name' => "latency_data",
			'graph_label_name' => "Maximum Latency seconds",
			'graph_color' => "#EF8080",
			'chart_name' => "d2"
			),
		);

load_chart_data();
?>
