<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Auth;
use AllStore\Core\Helpers;
use AllStore\Core\Request;
use AllStore\Core\Response;
use AllStore\Core\Database;
require_once APP_ROOT . '/includes/admin-crud.php';
Auth::requireAdmin('security.manage');
$q=Request::str('q','',80); $severity=Request::str('severity','',20); $where=['1=1']; $p=[];
if($q!=='') { $where[]='(LOWER(action) LIKE :q OR LOWER(COALESCE(entity,\'\')) LIKE :q2)'; $p['q']='%'.mb_strtolower($q).'%'; $p['q2']='%'.mb_strtolower($q).'%'; }
if(in_array($severity,['info','warning','error','critical'],true)) { $where[]='severity=:s'; $p['s']=$severity; }
$logs=Database::all('SELECT id,actor_email,action,entity,entity_id,severity,ip_address,created_at FROM audit_logs WHERE '.implode(' AND ',$where).' ORDER BY created_at DESC LIMIT 150',$p);
admin_header(['title'=>'Activity logs','subtitle'=>'Append-only administrative and security audit trail','active'=>'activity']);
echo '<form class="admin-toolbar" method="get"><input class="input" type="search" name="q" value="'.Helpers::e($q).'" placeholder="Action or entity…"><select class="select" name="severity"><option value="">All severities</option>';
foreach(['info','warning','error','critical'] as $s) echo '<option value="'.$s.'" '.($severity===$s?'selected':'').'>'.ucfirst($s).'</option>';
echo '</select><button class="btn btn-outline" type="submit">'.icon('search',15).'Filter</button></form>';
echo '<div class="card"><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Action</th><th>Actor</th><th>Entity</th><th>Severity</th><th>IP</th><th>Date</th></tr></thead><tbody>';
foreach($logs as $l) echo '<tr><td class="cell-strong">'.Helpers::e($l['action']).'</td><td>'.Helpers::e($l['actor_email'] ?: 'System').'</td><td>'.Helpers::e($l['entity'] ?? '—').'<div class="text-subtle" style="font-size:11px">'.Helpers::e($l['entity_id'] ?? '').'</div></td><td>'.admin_status($l,'severity').'</td><td>'.Helpers::e($l['ip_address'] ?? '—').'</td><td>'.admin_date($l,'created_at').'</td></tr>';
echo '</tbody></table></div></div>';
echo '<div class="alert alert-info" style="margin-top:16px">'.icon('info',16).'<span>Audit metadata is stored server-side and may include field names, never passwords, keys or full request bodies.</span></div>';
admin_footer();
