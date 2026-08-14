<?php
declare(strict_types=1);
require __DIR__.'/db.php';
$db=db(); $action=$_GET['action'] ?? ''; $in=jsonInput();
try {
    if ($action==='export' && $_SERVER['REQUEST_METHOD']==='GET') {
        $companies=$db->query('SELECT id,name,info,active FROM companies ORDER BY id')->fetchAll();
        $servers=$db->query('SELECT id,company_id,name,active,enabled_fields,sort_order,is_separator FROM servers ORDER BY company_id,sort_order,id')->fetchAll();
        foreach($servers as &$server)$server['enabled_fields']=json_decode($server['enabled_fields'],true);unset($server);
        $daily=$db->query('SELECT id,company_id,log_date,start_time,end_time,updated_at FROM daily_logs ORDER BY log_date,company_id')->fetchAll();
        $logs=$db->query('SELECT id,company_id,server_id,log_date,values_json,comment,follow_up,followup_resolved,resolution_comment,resolved_at,updated_at FROM logs ORDER BY log_date,company_id,server_id')->fetchAll();
        foreach($logs as &$log){$log['values']=json_decode($log['values_json'],true);unset($log['values_json']);}unset($log);
        $export=['format'=>'EventLogger','version'=>1,'exported_at'=>(new DateTimeImmutable())->format(DateTimeInterface::ATOM),'companies'=>$companies,'servers'=>$servers,'daily_logs'=>$daily,'logs'=>$logs];
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="eventlogger-export-'.date('Y-m-d-His').'.json"');
        echo json_encode($export,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);exit;
    }
    if ($action==='companies' && $_SERVER['REQUEST_METHOD']==='GET') reply($db->query('SELECT * FROM companies ORDER BY active DESC,name')->fetchAll());
    if ($action==='company_save') {
        if (!empty($in['id'])) { $s=$db->prepare('UPDATE companies SET name=?,info=?,active=? WHERE id=?'); $s->execute([trim($in['name']),$in['info']??'',!empty($in['active']),$in['id']]); }
        else { $s=$db->prepare('INSERT INTO companies(name,info,active) VALUES (?,?,1)'); $s->execute([trim($in['name']),$in['info']??'']); }
        reply(['ok'=>true]);
    }
    if ($action==='company_delete') { $s=$db->prepare('DELETE FROM companies WHERE id=?'); $s->execute([$in['id']]); reply(['ok'=>true]); }
    if ($action==='servers') { $s=$db->prepare('SELECT * FROM servers WHERE company_id=? ORDER BY active DESC,sort_order,name'); $s->execute([$_GET['company_id']]); $rows=$s->fetchAll(); foreach($rows as &$r)$r['enabled_fields']=json_decode($r['enabled_fields'],true); reply($rows); }
    if ($action==='server_save') {
        $fields=array_values(array_intersect(SERVICES,$in['enabled_fields']??[]));
        $order=max(0,(int)($in['sort_order']??0));
        $separator=!empty($in['is_separator']); $name=$separator ? trim($in['name']??'') : trim($in['name']);
        if (!empty($in['id'])) { $s=$db->prepare('UPDATE servers SET company_id=?,name=?,active=?,enabled_fields=?,sort_order=?,is_separator=? WHERE id=?'); $s->execute([$in['company_id'],$name,!empty($in['active']),json_encode($fields),$order,$separator,$in['id']]); }
        else { if(!$order){$s=$db->prepare('SELECT COALESCE(MAX(sort_order),0)+10 FROM servers WHERE company_id=?');$s->execute([$in['company_id']]);$order=(int)$s->fetchColumn();} $s=$db->prepare('INSERT INTO servers(company_id,name,active,enabled_fields,sort_order,is_separator) VALUES (?,?,?,?,?,?)'); $s->execute([$in['company_id'],$name,!empty($in['active']),json_encode($fields),$order,$separator]); }
        reply(['ok'=>true]);
    }
    if ($action==='server_reorder') {
        $id=(int)$in['id']; $direction=($in['direction']??'')==='up'?-1:1;
        $s=$db->prepare('SELECT id,company_id FROM servers WHERE id=?');$s->execute([$id]);$current=$s->fetch();if(!$current)reply(['error'=>'Raden saknas'],404);
        $s=$db->prepare('SELECT id FROM servers WHERE company_id=? ORDER BY active DESC,sort_order,name');$s->execute([$current['company_id']]);$ids=array_map('intval',array_column($s->fetchAll(),'id'));$pos=array_search($id,$ids,true);$other=$pos+$direction;
        if($pos!==false && isset($ids[$other])){[$ids[$pos],$ids[$other]]=[$ids[$other],$ids[$pos]];$u=$db->prepare('UPDATE servers SET sort_order=? WHERE id=?');foreach($ids as $i=>$rowId)$u->execute([($i+1)*10,$rowId]);}
        reply(['ok'=>true]);
    }
    if ($action==='server_delete') { $s=$db->prepare('DELETE FROM servers WHERE id=?'); $s->execute([$in['id']]); reply(['ok'=>true]); }
    if ($action==='logs') {
        $date=$_GET['date']??date('Y-m-d'); $cid=(int)$_GET['company_id'];
        $s=$db->prepare("SELECT s.id server_id,s.name,s.enabled_fields,s.is_separator,l.id log_id,l.values_json,l.follow_up,l.start_time,l.end_time,l.comment,l.updated_at
          FROM servers s LEFT JOIN logs l ON l.server_id=s.id AND l.log_date=? WHERE s.company_id=? AND s.active=1 ORDER BY s.sort_order,s.name");
        $s->execute([$date,$cid]); $rows=$s->fetchAll();
        foreach($rows as &$r){$r['enabled_fields']=json_decode($r['enabled_fields'],true);$r['values']=json_decode($r['values_json']??'{}',true);unset($r['values_json']);}
        $m=$db->prepare("SELECT start_time,end_time,updated_at FROM daily_logs WHERE company_id=? AND log_date=?");$m->execute([$cid,$date]);$meta=$m->fetch()?:['start_time'=>'','end_time'=>'','updated_at'=>''];
        $version=max((string)$db->query("SELECT COALESCE(MAX(updated_at),'') FROM logs")->fetchColumn(),(string)$meta['updated_at']);
        reply(['date'=>$date,'rows'=>$rows,'start_time'=>$meta['start_time'],'end_time'=>$meta['end_time'],'version'=>$version]);
    }
    if ($action==='daily_log_save') {
        $cid=(int)$in['company_id'];$date=$in['date']??date('Y-m-d');$field=$in['field']??'';$value=(string)($in['value']??'');
        if(!in_array($field,['start_time','end_time'],true))reply(['error'=>'Okänt tidsfält'],422);
        if(!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$value)&&$value!=='')reply(['error'=>'Ogiltig tid'],422);
        $now=(new DateTimeImmutable())->format('Y-m-d H:i:s.u');
        insertIfMissing($db,'daily_logs',['company_id'=>$cid,'log_date'=>$date,'updated_at'=>$now]);
        $s=$db->prepare("UPDATE daily_logs SET $field=?,updated_at=? WHERE company_id=? AND log_date=?");$s->execute([$value,$now,$cid,$date]);
        reply(['ok'=>true,'updated_at'=>$now]);
    }
    if ($action==='log_save') {
        $date=$in['date']??date('Y-m-d'); $sid=(int)$in['server_id'];
        $s=$db->prepare('SELECT company_id,enabled_fields FROM servers WHERE id=?');$s->execute([$sid]);$server=$s->fetch(); if(!$server)reply(['error'=>'Servern saknas'],404);
        $now=(new DateTimeImmutable())->format('Y-m-d H:i:s.u');insertIfMissing($db,'logs',['company_id'=>$server['company_id'],'server_id'=>$sid,'log_date'=>$date,'values_json'=>'{}','comment'=>'','updated_at'=>$now,'resolution_comment'=>'']);
        if (($in['field']??'')==='comment') { $s=$db->prepare('UPDATE logs SET comment=?,updated_at=? WHERE server_id=? AND log_date=?');$s->execute([$in['value']??'',$now,$sid,$date]); }
        elseif (($in['field']??'')==='follow_up') { $v=!empty($in['value'])?1:0;if(!$v)reply(['error'=>'Uppföljningen avslutas från administrationssidan'],422);$s=$db->prepare("UPDATE logs SET follow_up=1,followup_resolved=0,resolution_comment='',resolved_at='',updated_at=? WHERE server_id=? AND log_date=?");$s->execute([$now,$sid,$date]); }
        else { $field=$in['field']??''; $enabled=json_decode($server['enabled_fields'],true); if(!in_array($field,$enabled,true))reply(['error'=>'Fältet är inte aktivt'],422);
          $s=$db->prepare('SELECT values_json FROM logs WHERE server_id=? AND log_date=?');$s->execute([$sid,$date]);$v=json_decode($s->fetchColumn()?:'{}',true);$v[$field]=(bool)($in['value']??false);
          $s=$db->prepare('UPDATE logs SET values_json=?,updated_at=? WHERE server_id=? AND log_date=?');$s->execute([json_encode($v),$now,$sid,$date]); }
        reply(['ok'=>true,'updated_at'=>$now]);
    }
    if ($action==='history') { $s=$db->prepare('SELECT l.id,l.log_date,l.updated_at,s.name server_name,c.name company_name FROM logs l JOIN servers s ON s.id=l.server_id JOIN companies c ON c.id=l.company_id ORDER BY l.log_date DESC,l.updated_at DESC LIMIT 200');$s->execute();reply($s->fetchAll()); }
    if ($action==='followups') { $s=$db->query("SELECT l.id,l.log_date,l.comment,l.updated_at,s.name server_name,c.name company_name FROM logs l JOIN servers s ON s.id=l.server_id JOIN companies c ON c.id=l.company_id WHERE l.follow_up=1 AND l.followup_resolved=0 ORDER BY l.log_date,l.updated_at");reply($s->fetchAll()); }
    if ($action==='followup_resolve') { $comment=trim((string)($in['comment']??''));if($comment==='')reply(['error'=>'Skriv vad som har åtgärdats'],422);$now=(new DateTimeImmutable())->format('Y-m-d H:i:s.u');$s=$db->prepare('UPDATE logs SET follow_up=0,followup_resolved=1,resolution_comment=?,resolved_at=?,updated_at=? WHERE id=? AND follow_up=1');$s->execute([$comment,$now,$now,$in['id']]);reply(['ok'=>true]); }
    if ($action==='log_delete') { $s=$db->prepare('DELETE FROM logs WHERE id=?');$s->execute([$in['id']]);reply(['ok'=>true]); }
    reply(['error'=>'Okänd åtgärd'],404);
} catch(Throwable $e){ reply(['error'=>$e->getMessage()],500); }
