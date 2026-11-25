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
        $id=Request::id('role_id'); $name=trim(Request::str('name','',100)); $slug=Helpers::slugify(Request::str('slug','',100) ?: $name);
        if($name==='' || !preg_match('/^[a-z0-9_]{2,40}$/',$slug)) throw new AppException('Enter a name and a lowercase slug.',422);
        if(Database::first('SELECT id FROM roles WHERE slug=:s AND id<>:i',['s'=>$slug,'i'=>$id])) throw new AppException('That role slug already exists.',409);
        $data=['name'=>$name,'slug'=>$slug,'description'=>Request::str('description','',500),'is_system'=>Request::bool('is_system')?1:0];
        if($id!=='') {
            $old=Database::first('SELECT * FROM roles WHERE id=:i',['i'=>$id]); if(!$old) throw new AppException('Role not found.',404);
            if((int)$old['is_system']===1 && $slug!==(string)$old['slug']) throw new AppException('System role slugs cannot be changed.',422);
            Database::update('roles',$data,'id=:i',['i'=>$id]); $saved=$id;
        } else { $saved=Helpers::uuid(); $data['id']=$saved; $data['created_at']=Helpers::now(); Database::insert('roles',$data); }
        Database::delete('role_permissions','role_id=:r',['r'=>$saved]);
        $allowed=array_column(Database::all('SELECT id FROM permissions'),'id');
        foreach(Request::ids('permission_ids') as $pid) if(in_array($pid,$allowed,true)) Database::insert('role_permissions',['role_id'=>$saved,'permission_id'=>$pid]);
        Logger::audit('admin.role.saved','roles',$saved); Session::flash('success','Role and permissions saved.'); Response::redirect('/admin/roles');
    } catch(AppException $e) { Session::flash('error',$e->getMessage()); Response::redirect('/admin/roles'); }
      catch(\Throwable $e) { Logger::exception($e); Session::flash('error','Role could not be saved.'); Response::redirect('/admin/roles'); }
}
$roles=Database::all('SELECT * FROM roles ORDER BY is_system DESC,name');
$perms=Database::all('SELECT * FROM permissions ORDER BY module,slug');
$editId=Request::str('edit','',80); $edit=null; foreach($roles as $r) if($r['id']===$editId) $edit=$r;
$selected=$edit?array_column(Database::all('SELECT permission_id FROM role_permissions WHERE role_id=:r',['r'=>$edit['id']]),'permission_id'):[];
admin_header(['title'=>'Roles','subtitle'=>'Explicit capability assignment — deny by default','active'=>'roles']);
echo '<div class="admin-split"><div class="card card-pad" style="align-self:start"><h3 style="margin-bottom:14px">'.($edit?'Edit':'Create').' role</h3><form method="post">'.Session::csrfField().'<input type="hidden" name="role_id" value="'.Helpers::e((string)($edit['id'] ?? '')).'"><div class="form-grid"><div class="field"><label>Name *</label><input class="input" name="name" required value="'.Helpers::e($edit['name'] ?? '').'" maxlength="100"></div><div class="field"><label>Slug *</label><input class="input" name="slug" value="'.Helpers::e($edit['slug'] ?? '').'" maxlength="40"></div><div class="field span-2"><label>Description</label><textarea class="textarea" name="description" maxlength="500">'.Helpers::e($edit['description'] ?? '').'</textarea></div><div class="field span-2"><label class="checkbox"><input type="checkbox" name="is_system" value="1" '.((int)($edit['is_system'] ?? 0)?'checked':'').'><span>System role (protect slug)</span></label></div></div>';
echo '<h4 style="margin:18px 0 10px">Permissions</h4><div class="row wrap" style="gap:12px">';
foreach($perms as $p) echo '<label class="checkbox"><input type="checkbox" name="permission_ids[]" value="'.Helpers::e($p['id']).'" '.(in_array($p['id'],$selected,true)?'checked':'').'><span>'.Helpers::e($p['slug']).'</span></label>';
echo '</div><button class="btn btn-primary" type="submit" style="margin-top:18px">Save role</button></form></div>';
echo '<div class="card"><div class="card-header"><h3>Roles</h3><a class="section-link" href="/admin/permissions">Manage permission slugs '.icon('arrow-right',14).'</a></div><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Role</th><th>Slug</th><th>Permissions</th><th>Type</th><th>Actions</th></tr></thead><tbody>';
foreach($roles as $r) { $n=(int)Database::value('SELECT COUNT(*) FROM role_permissions WHERE role_id=:r',['r'=>$r['id']]); echo '<tr><td class="cell-strong">'.Helpers::e($r['name']).'</td><td>'.Helpers::e($r['slug']).'</td><td>'.$n.' / '.count($perms).'</td><td>'.((int)$r['is_system']?'<span class="badge badge-brand">System</span>':'<span class="badge badge-neutral">Custom</span>').'</td><td><a class="btn btn-outline btn-sm" href="?edit='.Helpers::e($r['id']).'">Edit</a></td></tr>'; }
echo '</tbody></table></div></div></div>';
admin_footer();
