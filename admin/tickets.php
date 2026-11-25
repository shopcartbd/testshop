<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Auth;
use AllStore\Core\Helpers;
use AllStore\Core\Request;
use AllStore\Core\Session;
use AllStore\Core\Response;
use AllStore\Core\Database;
use AllStore\Core\Logger;
use AllStore\Core\AppException;
use AllStore\Services\SupportService;
use AllStore\Services\NotificationService;
require_once APP_ROOT . '/includes/admin-crud.php';
Auth::requireAdmin('tickets.manage');
if (Request::isPost()) {
    Session::requireCsrf();
    try {
        $id=Request::id('ticket_id'); $action=Request::str('action','',30);
        $ticket=$id!==''?Database::first('SELECT * FROM support_tickets WHERE id=:i',['i'=>$id]):null;
        if(!$ticket) throw new AppException('Ticket not found.',404);
        if($action==='status') {
            $status=Request::str('status','',20);
            if(!in_array($status,['open','pending','resolved','closed'],true)) throw new AppException('Invalid ticket status.',422);
            SupportService::updateStatus($id,$status); Session::flash('success','Ticket status updated.');
        } elseif($action==='reply') {
            $message=trim(Request::str('message','',4000));
            if(mb_strlen($message)<2) throw new AppException('Enter a reply.',422);
            SupportService::addMessage($id,Auth::id(),'admin',$message,$_FILES['attachment'] ?? null);
            if($ticket['user_id']) NotificationService::create((string)$ticket['user_id'],'ticket_reply','Support replied','Ticket '.$ticket['ticket_number'].' has a new reply.');
            Session::flash('success','Reply sent.');
        }
        Logger::audit('admin.ticket.'.$action,'support_tickets',$id);
    } catch(AppException $e) { Session::flash('error',$e->getMessage()); }
      catch(\Throwable $e) { Logger::exception($e); Session::flash('error','Ticket could not be updated.'); }
    Response::redirect('/admin/tickets'.(Request::id('ticket_id')!==''?'?id='.rawurlencode(Request::id('ticket_id')):''));
}
$tickets=SupportService::allForAdmin(Request::str('status','',20) ?: null);
$id=Request::str('id','',80); $ticket=null; foreach($tickets as $t) if($t['id']===$id) $ticket=$t;
if(!$ticket && $id!=='') $ticket=Database::first('SELECT * FROM support_tickets WHERE id=:i',['i'=>$id]);
$messages=$ticket?SupportService::messages((string)$ticket['id']):[];
admin_header(['title'=>'Support tickets','subtitle'=>'Private customer conversations and status workflow','active'=>'tickets']);
echo '<div class="admin-page-head"><div><h2>Support inbox</h2><p>'.count($tickets).' tickets in this view.</p></div><form method="get"><select class="select" name="status"><option value="">All statuses</option>';
foreach(['open','pending','resolved','closed'] as $s) echo '<option value="'.$s.'" '.(Request::str('status','',20)===$s?'selected':'').'>'.ucfirst($s).'</option>';
echo '</select><button class="btn btn-outline btn-sm" type="submit">Filter</button></form></div>';
echo '<div class="admin-split"><div class="card"><div class="card-header"><h3>Tickets</h3></div><div class="admin-table-wrap" style="max-height:640px;overflow:auto"><table class="admin-table"><thead><tr><th>Subject</th><th>Customer</th><th>Status</th><th>Priority</th></tr></thead><tbody>';
foreach($tickets as $t) echo '<tr style="cursor:pointer"><td><a class="cell-strong" href="?id='.Helpers::e($t['id']).'">'.Helpers::e($t['subject']).'</a><div class="text-subtle" style="font-size:11px">'.Helpers::e($t['ticket_number']).'</div></td><td>'.Helpers::e($t['guest_email'] ?? 'Registered customer').'</td><td>'.admin_status($t).'</td><td>'.Helpers::e(ucfirst((string)$t['priority'])).'</td></tr>';
echo '</tbody></table></div></div><div class="card card-pad">';
if(!$ticket) echo '<p class="text-muted">Select a ticket to view the conversation.</p>';
else {
    echo '<div class="row-between wrap"><div><h3>'.Helpers::e($ticket['subject']).'</h3><div class="text-subtle" style="font-size:11px">'.Helpers::e($ticket['ticket_number']).' · '.Helpers::e($ticket['guest_email'] ?? 'Registered customer').'</div></div>'.admin_status($ticket).'</div><hr>';
    foreach($messages as $m) echo '<div style="padding:12px;border-radius:var(--radius);background:'.($m['sender_role']==='admin'?'var(--c-brand-tint)':'var(--c-surface)').';margin-bottom:10px"><strong style="font-size:11px;color:var(--c-muted)">'.($m['sender_role']==='admin'?'Support':'Customer').' · '.Helpers::e(Helpers::timeAgo((string)$m['created_at'])).'</strong><p style="font-size:13px;margin:7px 0 0;white-space:pre-line">'.Helpers::e($m['message']).'</p></div>';
    echo '<form method="post" enctype="multipart/form-data" style="margin-top:16px">'.Session::csrfField().'<input type="hidden" name="action" value="reply"><input type="hidden" name="ticket_id" value="'.Helpers::e($ticket['id']).'"><div class="field"><label>Reply</label><textarea class="textarea" name="message" required maxlength="4000" style="min-height:90px"></textarea></div><div class="row wrap"><input class="input" type="file" name="attachment" accept="image/jpeg,image/png,image/webp,application/pdf" style="max-width:240px"><button class="btn btn-primary btn-sm" type="submit">Send reply</button></div></form>';
    echo '<form method="post" class="row" style="margin-top:14px;gap:8px">'.Session::csrfField().'<input type="hidden" name="action" value="status"><input type="hidden" name="ticket_id" value="'.Helpers::e($ticket['id']).'"><select class="select" name="status">';
    foreach(['open','pending','resolved','closed'] as $s) echo '<option value="'.$s.'" '.(($ticket['status']===$s)?'selected':'').'>'.ucfirst($s).'</option>';
    echo '</select><button class="btn btn-outline btn-sm" type="submit">Update status</button></form>';
}
echo '</div></div>';
admin_footer();
