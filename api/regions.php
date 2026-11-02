<?php
require_once __DIR__.'/../config/database.php';
header('Content-Type: application/json; charset=utf-8');
$parent=(int)($_GET['parent_id']??0); $type=$_GET['type']??'';
$allowed=['city','district','village'];
if(!$parent || !in_array($type,$allowed,true)){echo json_encode([]);exit;}
$s=$conn->prepare("SELECT id,name FROM regions WHERE parent_id=? AND region_type=? ORDER BY name");
$s->bind_param('is',$parent,$type);$s->execute();echo json_encode($s->get_result()->fetch_all(MYSQLI_ASSOC));
