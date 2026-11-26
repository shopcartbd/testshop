<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Helpers;
use AllStore\Core\Request;
use AllStore\Core\Session;
use AllStore\Core\Database;
use AllStore\Core\Logger;
use AllStore\Core\Auth;
require_once __DIR__ . '/admin-layout.php';

/** CSRF-protected POST helper for admin pages. */
function admin_post(?string $action = null): bool
{
    if (!Request::isPost()) { return false; }
    Session::requireCsrf();
    if ($action !== null && Request::str('action','',40) !== $action) { return false; }
    return true;
}

function admin_flash_ok(string $message): void { Session::flash('success', $message); }
function admin_flash_err(string $message): void { Session::flash('error', $message); }

/**
 * Real, filtered admin table for secondary resources. Handles CSRF-protected
 * row actions (status change / delete) defined by the caller.
 */
function admin_resource_page(array $cfg): void
{
    Auth::requireAdmin($cfg['permission'] ?? null);
    $table = $cfg['table'];
    $title = $cfg['title']; $active = $cfg['active']; $subtitle = $cfg['subtitle'] ?? '';
    if (admin_post('row_action')) {
        $id = Request::id('id');
        $op = Request::str('op','',30);
        try {
            if ($id === '') { throw new \AllStore\Core\AppException('Missing record id.', 422); }
            if ($op === 'delete' && !empty($cfg['deletable'])) {
                Database::delete($table, 'id=:i', ['i'=>$id]);
                admin_flash_ok('Record deleted.');
            } elseif ($op === 'toggle' && !empty($cfg['toggle_column'])) {
                $col = $cfg['toggle_column'];
                $row = Database::first('SELECT * FROM ' . $table . ' WHERE id=:i', ['i'=>$id]);
                if ($row) { Database::update($table, [$col => ((int)($row[$col] ?? 0) ? 0 : 1)], 'id=:i', ['i'=>$id]); admin_flash_ok('Status updated.'); }
            } elseif ($op === 'status' && !empty($cfg['status_column'])) {
                $val = Request::str('value','',40);
                if (in_array($val, $cfg['status_values'] ?? [], true)) {
                    Database::update($table, [$cfg['status_column']=>$val], 'id=:i', ['i'=>$id]);
                    admin_flash_ok('Status updated.');
                }
            }
            Logger::audit('admin.resource_'.$op, $table, $id);
        } catch (\Throwable $e) { admin_flash_err($e instanceof \AllStore\Core\AppException ? $e->getMessage() : 'Could not update the record.'); }
        \AllStore\Core\Response::redirect(Request::path());
    }
    $q = Request::str('q','',80);
    $status = Request::str('status','',40);
    $where = ['1=1']; $params = [];
    if ($q !== '' && !empty($cfg['search_columns'])) {
        $parts = [];
        foreach ($cfg['search_columns'] as $i=>$col) { $parts[] = 'LOWER(' . $col . ') LIKE :q'.$i; $params['q'.$i] = '%'.mb_strtolower($q).'%'; }
        $where[] = '(' . implode(' OR ', $parts) . ')';
    }
    if ($status !== '' && !empty($cfg['status_column'])) { $where[] = $cfg['status_column'] . '=:st'; $params['st']=$status; }
    $sql = ($cfg['sql'] ?? 'SELECT * FROM ' . $table);
    $w = implode(' AND ', $where);
    $page = max(1, Request::int('page',1,1,10000)); $per = 25; $off = ($page-1)*$per;
    $rows = Database::all($sql . (str_contains(strtoupper($sql), ' WHERE ') ? ' AND ' : ' WHERE ') . $w . ' ORDER BY ' . ($cfg['order'] ?? 'created_at DESC') . " LIMIT $per OFFSET $off", $params);
    admin_header(['title'=>$title,'subtitle'=>$subtitle,'active'=>$active]);
    echo '<div class="admin-page-head">';
    if (!empty($cfg['create_url'])) { echo '<a class="btn btn-primary" href="'.Helpers::url($cfg['create_url']).'">'.icon('plus',16).Helpers::e($cfg['create_label'] ?? 'Add new').'</a>'; }
    echo '</div>';
    echo '<form class="admin-toolbar" method="get"><input class="input" type="search" name="q" value="'.Helpers::e($q).'" placeholder="'.Helpers::e($cfg['search_placeholder'] ?? 'Search…').'">';
    if (!empty($cfg['status_values'])) {
        echo '<select class="select" name="status"><option value="">All statuses</option>';
        foreach ($cfg['status_values'] as $v) { echo '<option value="'.Helpers::e($v).'" '.($status===$v?'selected':'').'>'.Helpers::e(ucwords(str_replace('_',' ',$v))).'</option>'; }
        echo '</select>';
    }
    echo '<button class="btn btn-outline" type="submit">'.icon('search',15).'Search</button></form>';
    echo '<div class="admin-table-wrap"><table class="admin-table"><thead><tr>';
    foreach ($cfg['columns'] as $label=>$fn) { echo '<th>'.Helpers::e($label).'</th>'; }
    echo '<th>Actions</th></tr></thead><tbody>';
    if ($rows === []) { echo '<tr><td colspan="'.(count($cfg['columns'])+1).'" style="text-align:center;padding:36px;color:var(--c-muted)">No records found.</td></tr>'; }
    foreach ($rows as $r) {
        echo '<tr>';
        foreach ($cfg['columns'] as $fn) { echo '<td>'.$fn($r).'</td>'; }
        echo '<td><form method="post" style="display:flex;gap:5px;align-items:center">'.Session::csrfField().'<input type="hidden" name="action" value="row_action"><input type="hidden" name="id" value="'.Helpers::e($r['id']).'">';
        if (!empty($cfg['status_values'])) {
            echo '<select class="select" name="value" style="min-height:32px;padding:4px 26px 4px 8px;font-size:12px;width:auto">';
            foreach ($cfg['status_values'] as $v) { echo '<option value="'.Helpers::e($v).'" '.((string)($r[$cfg['status_column']] ?? '')===$v?'selected':'').'>'.Helpers::e(ucwords(str_replace('_',' ',$v))).'</option>'; }
            echo '</select><button class="btn btn-sm btn-outline" name="op" value="status">Update</button>';
        } elseif (!empty($cfg['toggle_column'])) {
            echo '<button class="btn btn-sm btn-outline" name="op" value="toggle">Toggle</button>';
        }
        if (!empty($cfg['deletable'])) { echo '<button class="btn btn-sm btn-danger" name="op" value="delete" data-confirm="Delete this record permanently?">'.icon('trash',14).'</button>'; }
        echo '</form></td></tr>';
    }
    echo '</tbody></table></div>';
    if (count($rows) === $per) { echo '<div style="margin-top:14px"><a class="btn btn-outline btn-sm" href="?page='.($page+1).'&q='.rawurlencode($q).'&status='.rawurlencode($status).'">Next page →</a></div>'; }
    admin_footer();
}
