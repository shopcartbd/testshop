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
require_once APP_ROOT . '/includes/admin-crud.php';
Auth::requireAdmin('users.manage');
if (Request::isPost()) {
    Session::requireCsrf();
    try {
        $id=Request::id('id'); $name=trim(Request::str('full_name','',120)); $email=strtolower(trim(Request::str('email','',254)));
        $role=Request::id('role_id'); $status=Request::str('status','active',20); $password=(string)Request::input('password','');
        if($name==='' || !filter_var($email,FILTER_VALIDATE_EMAIL)) throw new AppException('Enter a valid name and email.',422);
        if(!in_array($status,['active','suspended'],true)) throw new AppException('Invalid status.',422);
        $roleRow=Database::first("SELECT id,slug FROM roles WHERE id=:i AND slug IN ('super_admin','admin','staff')",['i'=>$role]);
        if(!$roleRow) throw new AppException('Choose an administrator or staff role.',422);
        if(Database::first('SELECT id FROM profiles WHERE email=:e AND id<>:i',['e'=>$email,'i'=>$id])) throw new AppException('That email is already registered.',409);
        if($id==='') {
            if(strlen($password)<10 || !preg_match('/[A-Za-z]/',$password) || !preg_match('/[0-9]/',$password)) throw new AppException('New administrators need a password of at least 10 characters with a letter and a number.',422);
            $id=Helpers::uuid();
            Database::insert('profiles',['id'=>$id,'email'=>$email,'password_hash'=>password_hash($password,PASSWORD_DEFAULT),'full_name'=>$name,'phone'=>Request::str('phone','',30),'role_id'=>$role,'status'=>$status,'created_at'=>Helpers::now(),'updated_at'=>Helpers::now()]);
        } else {
            if($id===(string)Auth::id() && $status!=='active') throw new AppException('You cannot suspend your own account.',422);
            $data=['email'=>$email,'full_name'=>$name,'phone'=>Request::str('phone','',30),'role_id'=>$role,'status'=>$status,'updated_at'=>Helpers::now()];
            if($password!=='') {
                if(strlen($password)<10 || !preg_match('/[A-Za-z]/',$password) || !preg_match('/[0-9]/',$password)) throw new AppException('Use at least 10 characters with a letter and a number.',422);
                $data['password_hash']=password_hash($password,PASSWORD_DEFAULT);
            }
            Database::update('profiles',$data,'id=:i',['i'=>$id]);
        }
        Logger::audit('admin.user.saved','profiles',$id); Session::flash('success','Administrator account saved.'); Response::redirect('/admin/admin-users');
    } catch(AppException $e) { Session::flash('error',$e->getMessage()); Response::redirect('/admin/admin-users'); }
      catch(\Throwable $e) { Logger::exception($e); Session::flash('error','Administrator account could not be saved.'); Response::redirect('/admin/admin-users'); }
}
$roles=Database::all("SELECT id,name,slug FROM roles WHERE slug IN ('super_admin','admin','staff') ORDER BY name");
$users=Database::all("SELECT p.id,p.email,p.full_name,p.phone,p.status,p.last_login_at,r.name AS role_name,r.slug AS role_slug FROM profiles p LEFT JOIN roles r ON r.id=p.role_id WHERE r.slug IN ('super_admin','admin','staff') AND p.deleted_at IS NULL ORDER BY p.created_at DESC");
$edit=null; $eid=Request::str('edit','',80); foreach($users as $u) if($u['id']===$eid) $edit=$u;
admin_header(['title'=>'Admin users','subtitle'=>'Role-based administrator accounts — no default password','active'=>'admin-users']);
echo '<div class="admin-split"><div class="card card-pad" style="align-self:start"><h3 style="margin-bottom:14px">'.($edit?'Edit':'Invite').' administrator</h3><form method="post">'.Session::csrfField().'<input type="hidden" name="id" value="'.Helpers::e((string)($edit['id'] ?? '')).'"><div class="form-grid">';
echo '<div class="field span-2"><label>Full name *</label><input class="input" name="full_name" required value="'.Helpers::e($edit['full_name'] ?? '').'" maxlength="120"></div>';
echo '<div class="field span-2"><label>Email *</label><input class="input" type="email" name="email" required value="'.Helpers::e($edit['email'] ?? '').'" maxlength="254"></div>';
echo '<div class="field"><label>Phone</label><input class="input" name="phone" value="'.Helpers::e($edit['phone'] ?? '').'" maxlength="30"></div>';
echo '<div class="field"><label>Role</label><select class="select" name="role_id" required>';
foreach($roles as $r) echo '<option value="'.Helpers::e($r['id']).'" '.(($edit['role_slug'] ?? '')===$r['slug']?'selected':'').'>'.Helpers::e($r['name']).'</option>';
echo '</select></div><div class="field"><label>Status</label><select class="select" name="status"><option value="active"'.(($edit['status'] ?? 'active')==='active'?' selected':'').'>Active</option><option value="suspended"'.(($edit['status'] ?? '')==='suspended'?' selected':'').'>Suspended</option></select></div>';
echo '<div class="field"><label>Password '.($edit?'(leave blank to keep)':'*').'</label><input class="input" type="password" name="password" minlength="10" autocomplete="new-password"'.($edit?'':' required').'></div></div>';
echo '<button class="btn btn-primary" type="submit" style="margin-top:14px">Save administrator</button></form></div>';
echo '<div class="card"><div class="card-header"><h3>Administrators</h3></div><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Last login</th><th>Actions</th></tr></thead><tbody>';
foreach($users as $u) echo '<tr><td class="cell-strong">'.Helpers::e($u['full_name']).'</td><td>'.Helpers::e($u['email']).'</td><td>'.Helpers::e($u['role_name']).'</td><td>'.admin_status($u).'</td><td>'.admin_date($u,'last_login_at').'</td><td><a class="btn btn-outline btn-sm" href="?edit='.Helpers::e($u['id']).'">Edit</a></td></tr>';
echo '</tbody></table></div></div></div>';
admin_footer();
