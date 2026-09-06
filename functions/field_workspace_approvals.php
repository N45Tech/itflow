<?php

function fieldApprovalDefinitions(string $kind): array
{
    return match ($kind) {
        'ticket' => ['table'=>'ticket_approvals','prefix'=>'ticket_approval_', 'parent'=>'ticket', 'decider'=>'decided_by'],
        'task' => ['table'=>'task_approvals','prefix'=>'approval_', 'parent'=>'task', 'decider'=>'approved_by'],
        default => throw new DomainException('Choose a ticket or task approval.'),
    };
}

function fieldApprovalCanDecide(array $row, int $user_id): bool
{
    return $row['scope'] === 'internal' && $row['status'] === 'pending'
        && (int) $row['created_by'] !== $user_id
        && ($row['type'] === 'any' || ($row['type'] === 'specific' && (int) $row['required_user_id'] === $user_id));
}

function fieldApprovalSnapshot(array $row, string $prefix): array
{
    $result=[];
    foreach (['id','scope','type','required_user_id','required_contact_id','status','created_by','url_expires_at'] as $key) {
        $result[$key]=$row[$prefix.$key];
    }
    return $result;
}

function fieldWorkspaceApprovals(int $ticket_id, int $user_id): array
{
    fieldTicket($ticket_id); $result=[];
    foreach (['ticket','task'] as $kind) {
        ['table'=>$table,'prefix'=>$p] = fieldApprovalDefinitions($kind);
        $join=$kind==='task'?' JOIN tasks ON task_id = approval_task_id':'';
        $where=$kind==='task'?"task_ticket_id = $ticket_id":"ticket_approval_ticket_id = $ticket_id";
        $extra=$kind==='task'?', task_id, task_name, task_state':", 0 AS task_id, 'Entire ticket' AS task_name, '' AS task_state";
        $columns=implode(',',array_map(static fn($key)=>'a.'.$p.$key,['id','scope','type','required_user_id','required_contact_id','status','created_by','url_expires_at']));
        foreach (fieldRows("SELECT $columns, u.user_name, c.contact_name $extra FROM $table a $join
            LEFT JOIN users u ON u.user_id = a.{$p}required_user_id
            LEFT JOIN contacts c ON c.contact_id = a.{$p}required_contact_id
            WHERE $where ORDER BY a.{$p}id DESC") as $row) {
            $approval=fieldApprovalSnapshot($row,$p);
            $approval['kind']=$kind;$approval['task_id']=(int)$row['task_id'];$approval['task_name']=$row['task_name'];
            $approval['route_label']=approvalRouteLabel($approval['scope'],$approval['type'],$row['user_name']??'',$row['contact_name']??'');
            $approval['actionable']=!in_array($row['task_state'],['Completed','Skipped'],true);
            $approval['can_decide']=$approval['actionable']&&fieldApprovalCanDecide($approval,$user_id);
            $approval['expires_at']=fieldLocalTime($approval['url_expires_at']);unset($approval['url_expires_at']);
            $result[]=$approval;
        }
    }
    return $result;
}

function fieldWorkspaceApproval(array $input, int $user_id): array
{
    global $mysqli, $session_name;
    $ticket_id=(int)$input['ticket_id'];$ticket=fieldLockTickets([$ticket_id])[$ticket_id];
    $kind=(string)($input['kind']??'ticket');
    ['table'=>$table,'prefix'=>$p,'parent'=>$parent,'decider'=>$decider]=fieldApprovalDefinitions($kind);
    $task_id=$kind==='task'?(int)($input['task_id']??0):0;$parent_id=$kind==='task'?$task_id:$ticket_id;
    $task=null;
    if ($kind==='task') {
        $task=mysqli_fetch_assoc(fieldDb("SELECT * FROM tasks WHERE task_id = $task_id AND task_ticket_id = $ticket_id FOR UPDATE"));
        if (!$task || in_array($task['task_state'],['Completed','Skipped'],true)) { throw new DomainException('This task no longer accepts approval changes.'); }
    }
    $id=(int)($input['approval_id']??0);$operation=(string)($input['operation']??'request');$before=[];
    if ($id) {
        $row=mysqli_fetch_assoc(fieldDb("SELECT * FROM $table WHERE {$p}id = $id AND {$p}{$parent}_id = $parent_id FOR UPDATE"));
        if (!$row) { throw new DomainException('This approval is unavailable for the job.'); }
        $before=fieldApprovalSnapshot($row,$p);
    }
    $reason=fieldText($input['reason']??'','a reason for this approval action',1000);
    $expires=null;$token=null;
    if ($operation==='decide') {
        if (!$id || !fieldApprovalCanDecide($before,$user_id)) { throw new DomainException('This approval requires another authorized approver. You cannot decide your own request.'); }
        $decision=(string)($input['decision']??'');
        if (!in_array($decision,['approved','declined'],true)) { throw new DomainException('Choose approve or decline.'); }
        fieldDb("UPDATE $table SET {$p}status = ".fieldSql($decision).", {$p}$decider = $user_id,
            {$p}decided_at = NOW(), {$p}url_key = '', {$p}url_expires_at = NULL
            WHERE {$p}id = $id AND {$p}status = 'pending'");
        if (mysqli_affected_rows($mysqli)!==1) { throw new DomainException('The approval was already decided. Refresh the job.'); }
        $after=array_replace($before,['status'=>$decision]);$event=$decision;
    } elseif (in_array($operation,['request','retry','reroute'],true)) {
        if (($operation==='request' && $id) || ($operation!=='request' && (!$id || !in_array($before['status'],['pending','declined'],true)))) {
            throw new DomainException('This approval cannot be requested again.');
        }
        if ($operation==='request' && fieldRows("SELECT {$p}id FROM $table WHERE {$p}{$parent}_id = $parent_id AND {$p}status <> 'approved' FOR UPDATE")) {
            throw new DomainException('An unresolved approval already exists. Manage that request first.');
        }
        [$scope,$type]=$operation==='retry'?[$before['scope'],$before['type']]:approvalRouteParts($input['route']??'');
        $required_user=$scope==='internal'&&$type==='specific'?(int)($operation==='retry'?$before['required_user_id']:($input['required_user_id']??0)):0;
        $required_contact=$scope==='client'&&$type==='specific'?(int)($operation==='retry'?$before['required_contact_id']:($input['required_contact_id']??0)):0;
        $creator=$id?(int)$before['created_by']:$user_id;
        [$available,$error]=runbookApprovalRouteAvailability($scope,$type,$required_user,$ticket,$creator,$required_contact);
        if (!$scope || !$available) { throw new DomainException(fieldPlainText($error?:'Choose an available approval route.')); }
        $token=randomString(32);$expires=runbookApprovalTokenExpiry();
        $values="{$p}scope = ".fieldSql($scope).", {$p}type = ".fieldSql($type)
            .", {$p}required_user_id = ".($required_user?:'NULL').", {$p}required_contact_id = ".($required_contact?:'NULL')
            .", {$p}status = 'pending', {$p}$decider = NULL, {$p}decided_at = NULL, {$p}url_key = ".fieldSql(runbookApprovalTokenHash($token))
            .", {$p}url_expires_at = ".fieldSql($expires);
        if ($id) { fieldDb("UPDATE $table SET $values WHERE {$p}id = $id"); }
        else { fieldDb("INSERT INTO $table SET $values, {$p}created_by = $creator, {$p}{$parent}_id = $parent_id");$id=(int)mysqli_insert_id($mysqli); }
        $after=['status'=>'pending','scope'=>$scope,'type'=>$type,'required_user_id'=>$required_user,'required_contact_id'=>$required_contact];
        $event=['request'=>'created','retry'=>'re_requested','reroute'=>'rerouted'][$operation];
        if ($kind==='ticket') { ticketApprovalQueueNotification($id,$ticket,$scope,$type,$required_user,$token,$creator,$required_contact); }
        else { runbookQueueApprovalNotification($id,$ticket,[
            'runbook_version_task_name'=>$task['task_name'],'runbook_version_task_approval_scope'=>$scope,
            'runbook_version_task_approval_type'=>$type,'runbook_version_task_approval_user_id'=>$required_user,
            'runbook_version_task_approval_contact_id'=>$required_contact],$token,$creator); }
    } else { throw new DomainException('Choose a valid approval action.'); }
    if ($kind==='ticket') { ticketApprovalRecordEvent($id,$ticket_id,$event,$before,$after,'agent',$user_id,$session_name,$reason,$expires); }
    else {
        runbookRecordApprovalEvent($id,$task_id,$event,$before,$after,'agent',$user_id,$session_name,$reason,$expires);
        fieldDb("INSERT INTO task_evidence SET task_evidence_task_id = $task_id, task_evidence_type = 'approval_audit',
            task_evidence_note = ".fieldSql("Approval $id $event: $reason").", task_evidence_submitted_by = $user_id");
        refreshRunbookTaskStates($ticket_id);
    }
    fieldWorkspaceAudit($ticket_id,$user_id,"Approval $id $event: $reason");
    return ['message'=>$operation==='decide'?'Approval decision recorded.':'Approval requested.','approval_id'=>$id];
}
