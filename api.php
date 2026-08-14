<?php
declare(strict_types=1);
require __DIR__.'/db.php';
$db=db(); $action=$_GET['action'] ?? ''; $in=jsonInput();
try {
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
        else { if(!$order){$s=$db->prepare('SELECT COALESCE(MAX(sort_order),0)+10 FROM servers WHERE company_id=?');$s->execute([$in['company_id']]);$order=(int)$s->fetchColumn();} $s=$db->prepare('INSERT INTO servers(company_id,name,active,enabled_fields,sort_order,is_separator) VALUES (?,?,1,?,?,?)'); $s->execute([$in['company_id'],$name,json_encode($fields),$order,$separator]); }
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
        $s=$db->prepare("SELECT s.id server_id,s.name,s.enabled_fields,s.is_separator,l.id log_id,l.values_json,l.start_time,l.end_time,l.comment,l.updated_at
          FROM servers s LEFT JOIN logs l ON l.server_id=s.id AND l.log_date=? WHERE s.company_id=? AND s.active=1 ORDER BY s.sort_order,s.name");
        $s->execute([$date,$cid]); $rows=$s->fetchAll();
        foreach($rows as &$r){$r['enabled_fields']=json_decode($r['enabled_fields'],true);$r['values']=json_decode($r['values_json']??'{}',true);unset($r['values_json']);}
        reply(['date'=>$date,'rows'=>$rows,'version'=>$db->query("SELECT COALESCE(MAX(updated_at),'') FROM logs")->fetchColumn()]);
    }
    if ($action==='log_save') {
        $date=$in['date']??date('Y-m-d'); $sid=(int)$in['server_id'];
        $s=$db->prepare('SELECT company_id,enabled_fields FROM servers WHERE id=?');$s->execute([$sid]);$server=$s->fetch(); if(!$server)reply(['error'=>'Servern saknas'],404);
        $s=$db->prepare('INSERT INTO logs(company_id,server_id,log_date,updated_at) VALUES (?,?,?,?) ON CONFLICT(server_id,log_date) DO NOTHING');
        $now=(new DateTimeImmutable())->format('Y-m-d H:i:s.u');$s->execute([$server['company_id'],$sid,$date,$now]);
        if (in_array(($in['field']??''),['comment','start_time','end_time'],true)) { $column=$in['field'];$value=(string)($in['value']??'');if($column!=='comment'&&!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$value)&&$value!=='')reply(['error'=>'Ogiltig tid'],422);$s=$db->prepare("UPDATE logs SET $column=?,updated_at=? WHERE server_id=? AND log_date=?");$s->execute([$value,$now,$sid,$date]); }
        else { $field=$in['field']??''; $enabled=json_decode($server['enabled_fields'],true); if(!in_array($field,$enabled,true))reply(['error'=>'Fältet är inte aktivt'],422);
          $s=$db->prepare('SELECT values_json FROM logs WHERE server_id=? AND log_date=?');$s->execute([$sid,$date]);$v=json_decode($s->fetchColumn()?:'{}',true);$v[$field]=(bool)($in['value']??false);
          $s=$db->prepare('UPDATE logs SET values_json=?,updated_at=? WHERE server_id=? AND log_date=?');$s->execute([json_encode($v),$now,$sid,$date]); }
        reply(['ok'=>true,'updated_at'=>$now]);
    }
    if ($action==='history') { $s=$db->prepare('SELECT l.id,l.log_date,l.updated_at,s.name server_name,c.name company_name FROM logs l JOIN servers s ON s.id=l.server_id JOIN companies c ON c.id=l.company_id ORDER BY l.log_date DESC,l.updated_at DESC LIMIT 200');$s->execute();reply($s->fetchAll()); }
    if ($action==='log_delete') { $s=$db->prepare('DELETE FROM logs WHERE id=?');$s->execute([$in['id']]);reply(['ok'=>true]); }
    reply(['error'=>'Okänd åtgärd'],404);
} catch(Throwable $e){ reply(['error'=>$e->getMessage()],500); }
