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
use AllStore\Services\NotificationService;
require_once APP_ROOT . '/includes/admin-crud.php';
Auth::requireAdmin('dashboard.view');
if (Request::isPost()) {
    Session::requireCsrf(); Auth::requireAdmin('settings.manage');
    try {
        $action=Request::str('action','',30);
        if($action==='create') {
            $title=trim(Request::str('title','',120)); $body=trim(Request::str('body','',500));
            if($title==='' || $body==='') throw new AppException('Title and message are required.',422);
            NotificationService::create(null,'admin_note',$title,$body,true);
            Session::flash('success','Administrator notification created.');
        } elseif($action==='read') {
            $id=Request::id('id'); Database::update('notifications',['is_read'=>1],'id=:i AND admin_only=1',['i'=>$id]); Session::flash('success','Marked as read.');
        } elseif($action==='read_all') {
            Database::exec('UPDATE notifications SET is_read=1 WHERE admin_only=1 AND is_read=0'); Session::flash('success','All notifications marked as read.');
        } elseif($action==='delete') {
            $id=Request::id('id'); Database::delete('notifications','id=:i AND admin_only=1',['i'=>$id]); Session::flash('success','Notification removed.');
        }
        Logger::audit('admin.notification.'.$action,'notifications');
    } catch(AppException $e) { Session::flash('error',$e->getMessage()); }
    Response::redirect('/admin/notifications');
}
$notes=Database::all('SELECT * FROM notifications WHERE admin_only=1 ORDER BY created_at DESC LIMIT 100');
admin_header(['title'=>'Notifications','subtitle'=>'Private administrator alerts and store events','active'=>'notifications']);
echo '<div class="admin-grid-2"><div class="card card-pad"><h3 style="margin-bottom:14px">Create admin notice</h3><form method="post">'.Session::csrfField().'<input type="hidden" name="action" value="create"><div class="field"><label>Title</label><input class="input" name="title" required maxlength="120"></div><div class="field"><label>Message</label><textarea class="textarea" name="body" required maxlength="500"></textarea></div><button class="btn btn-primary btn-sm" type="submit">Create notice</button></form></div><div class="card card-pad"><h3 style="margin-bottom:14px">Summary</h3><div class="stat-grid"><div class="stat-card"><div class="stat-label">Total</div><div class="stat-value">'.count($notes).'</div></div><div class="stat-card"><div class="stat-label">Unread</div><div class="stat-value">'.NotificationService::adminUnread().'</div></div></div><form method="post" style="margin-top:14px">'.Session::csrfField().'<input type="hidden" name="action" value="read_all"><button class="btn btn-outline btn-sm" type="submit">Mark all as read</button></form></div></div>';
echo '<div class="card" style="margin-top:16px"><div class="card-header"><h3>Administrator notifications</h3></div><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Title</th><th>Message</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead><tbody>';
foreach($notes as $n) {
    echo '<tr><td class="cell-strong">'.Helpers::e($n['title']).'</td><td>'.Helpers::e($n['body']).'</td><td>'.((int)$n['is_read']?'<span class="badge badge-neutral">Read</span>':'<span class="badge badge-brand">Unread</span>').'</td><td>'.admin_date($n,'created_at').'</td><td class="row" style="gap:5px">';
    if(!(int)$n['is_read']) echo '<form method="post" style="display:inline">'.Session::csrfField().'<input type="hidden" name="action" value="read"><input type="hidden" name="id" value="'.Helpers::e($n['id']).'"><button class="btn btn-outline btn-sm" type="submit">Mark read</button></form>';
    echo '<form method="post" style="display:inline">'.Session::csrfField().'<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="'.Helpers::e($n['id']).'"><button class="btn btn-danger btn-sm" type="submit" data-confirm="Delete this notification?">'.icon('trash',13).'</button></form></td></tr>';
}
echo '</tbody></table></div></div>';
admin_footer();
