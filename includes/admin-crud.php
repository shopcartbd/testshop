<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }

use AllStore\Core\Auth;
use AllStore\Core\Helpers;
use AllStore\Core\Request;
use AllStore\Core\Session;
use AllStore\Core\Database;
use AllStore\Core\Logger;
use AllStore\Core\Response;
use AllStore\Core\AppException;
use AllStore\Core\Security;
use AllStore\Services\UploadService;

require_once __DIR__ . '/admin-helpers.php';

/** Exact column names from the configured table (portable SQLite/PostgreSQL). */
function admin_columns(string $table): array
{
    if (!preg_match('/^[a-z_]+$/', $table)) { throw new AppException('Invalid table.', 500); }
    if (Database::driver() === 'pgsql') {
        return array_column(Database::all('SELECT column_name FROM information_schema.columns WHERE table_name=:t', ['t'=>$table]), 'column_name');
    }
    return array_column(Database::all('PRAGMA table_info("' . $table . '")'), 'name');
}

function admin_safe_url(string $v): string
{
    $v = trim($v);
    if ($v === '') { return ''; }
    if (str_starts_with($v, '/') && !str_starts_with($v, '//')) { return $v; }
    if (preg_match('#^https://[a-z0-9.\-]+/#i', $v) === 1) { return $v; }
    throw new AppException('Use an internal /path or a valid https:// URL.', 422);
}

function admin_choices(array $f): array
{
    if (isset($f['choices']) && is_array($f['choices'])) { return $f['choices']; }
    if (!empty($f['choices_sql'])) {
        $rows = Database::all((string)$f['choices_sql']);
        $label = (string)($f['choice_label'] ?? 'name');
        $out = ['' => (string)($f['empty_label'] ?? '— None —')];
        foreach ($rows as $r) { $out[(string)$r['id']] = (string)($r[$label] ?? $r['id']); }
        return $out;
    }
    return [];
}

function admin_field_value(array $f, string $key, array $row): mixed
{
    $type = $f['type'] ?? 'text';
    $default = (string)($row[$key] ?? ($f['default'] ?? ''));
    if ($type === 'password_hash') {
        $password = (string)Request::input('password', '');
        if (Request::isPost() && $password !== '') { return password_hash($password, PASSWORD_DEFAULT); }
        return $default;
    }
    if ($type === 'image') {
        $fileKey = $key . '_file';
        if (isset($_FILES[$fileKey]) && is_array($_FILES[$fileKey]) && (int)($_FILES[$fileKey]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            return UploadService::store($_FILES[$fileKey]);
        }
        $url = trim((string)Request::input($key, $default));
        return $url === '' ? '' : admin_safe_url($url);
    }
    if ($type === 'checkbox') { return Request::isPost() ? (Request::bool($key) ? 1 : 0) : (int)$default; }
    if ($type === 'number' || $type === 'money') {
        $v = Request::isPost() ? Request::float($key, (float)($f['default'] ?? 0)) : (float)$default;
        if ($type === 'money') { return (int)round($v * 100); }
        return isset($f['integer']) ? (int)round($v) : $v;
    }
    if ($type === 'select' || $type === 'reference') {
        $v = (string)Request::input($key, $default);
        $allowed = admin_choices($f);
        if ($allowed !== [] && !array_key_exists($v, $allowed)) { $v = (string)array_key_first($allowed); }
        return $v;
    }
    if ($type === 'datetime') {
        $v = trim((string)Request::input($key, $default));
        if ($v === '') { return null; }
        $ts = strtotime($v);
        return $ts ? gmdate('Y-m-d\TH:i:s\Z', $ts) : throw new AppException('Enter a valid date and time.', 422);
    }
    if ($type === 'json') {
        $v = trim((string)Request::input($key, $default));
        if ($v === '') { return '{}'; }
        json_decode($v, true, 32, JSON_THROW_ON_ERROR);
        return $v;
    }
    $max = (int)($f['max'] ?? 2000);
    $v = Security::plain((string)Request::input($key, $default), $max);
    if ($type === 'slug') {
        $src = (string)($f['slug_from'] ?? 'name');
        if ($v === '' && Request::isPost()) { $v = Helpers::slugify((string)Request::input($src, '')); }
        if ($v === '' && $row === []) { $v = 'item-' . strtolower(substr(Helpers::token(5), 0, 10)); }
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $v)) { throw new AppException(ucfirst($key) . ' may contain lowercase letters, numbers and hyphens only.', 422); }
    }
    if ($type === 'email' && $v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) { throw new AppException('Enter a valid email address.', 422); }
    if (!empty($f['required']) && $v === '') { throw new AppException(Helpers::e($f['label'] ?? $key) . ' is required.', 422); }
    return $v;
}

/**
 * Full CSRF-protected CRUD page for admin-managed tables. All identifiers are
 * allowlisted in each page's config; values are bound through PDO.
 */
function admin_crud_page(array $cfg): void
{
    Auth::requireAdmin($cfg['permission'] ?? null);
    $table = (string)$cfg['table'];
    $columns = admin_columns($table);
    $fields = (array)($cfg['fields'] ?? []);
    $id = Request::id('id');
    $editing = $id !== '' || (Request::str('edit','',40) !== '');
    if ($editing && $id === '') { $id = Request::str('edit','',80); }
    $row = [];
    if ($id !== '') { $row = Database::first('SELECT * FROM ' . $table . ' WHERE id=:i', ['i'=>$id]) ?: []; }

    if (Request::isPost()) {
        Session::requireCsrf();
        $op = Request::str('op','save',30);
        try {
            if ($op === 'delete' && !empty($cfg['deletable'])) {
                if ($id === '') { throw new AppException('Missing record id.', 422); }
                if (in_array('deleted_at', $columns, true)) {
                    $data = ['deleted_at'=>Helpers::now()];
                    if (in_array('updated_at', $columns, true)) { $data['updated_at'] = Helpers::now(); }
                    Database::update($table, $data, 'id=:i', ['i'=>$id]);
                } elseif (!empty($cfg['delete_sql'])) {
                    Database::exec((string)$cfg['delete_sql'], ['i'=>$id]);
                } else {
                    Database::delete($table, 'id=:i', ['i'=>$id]);
                }
                Logger::audit('admin.delete', $table, $id);
                Session::flash('success','Record removed.');
                Response::redirect(Request::path());
            }
            if ($op === 'toggle' && !empty($cfg['toggle_column'])) {
                $col = (string)$cfg['toggle_column'];
                if (!in_array($col, $columns, true)) { throw new AppException('Invalid column.', 500); }
                Database::update($table, [$col => ((int)($row[$col] ?? 0) ? 0 : 1)], 'id=:i', ['i'=>$id]);
                Logger::audit('admin.toggle', $table, $id);
                Session::flash('success','Status updated.');
                Response::redirect(Request::path());
            }
            $data = [];
            foreach ($fields as $key=>$f) {
                if (!in_array($key, $columns, true)) { continue; }
                $data[$key] = admin_field_value($f, (string)$key, Request::isPost() && $op === 'save' ? $row : []);
            }
            if (!empty($cfg['slug_field']) && in_array((string)$cfg['slug_field'], $columns, true)) {
                $slug = (string)($data[(string)$cfg['slug_field']] ?? '');
                $dup = Database::first('SELECT id FROM ' . $table . ' WHERE ' . (string)$cfg['slug_field'] . '=:s AND id<>:i', ['s'=>$slug,'i'=>$id]);
                if ($dup) { throw new AppException('That slug is already in use. Choose another.', 409); }
            }
            if (is_callable($cfg['before_save'] ?? null)) {
                $data = (array)call_user_func($cfg['before_save'], $data, $row, $id);
            }
            if ($id !== '' && $row !== []) {
                if (in_array('updated_at', $columns, true)) { $data['updated_at'] = Helpers::now(); }
                Database::update($table, $data, 'id=:i', ['i'=>$id]);
                $savedId = $id;
                Logger::audit('admin.update', $table, $id, ['fields'=>array_keys($data)]);
            } else {
                $savedId = Helpers::uuid();
                if (in_array('id', $columns, true)) { $data['id'] = $savedId; }
                if (in_array('created_at', $columns, true)) { $data['created_at'] = Helpers::now(); }
                if (in_array('updated_at', $columns, true)) { $data['updated_at'] = Helpers::now(); }
                Database::insert($table, $data);
                Logger::audit('admin.create', $table, $savedId, ['fields'=>array_keys($data)]);
            }
            if (is_callable($cfg['after_save'] ?? null)) { call_user_func($cfg['after_save'], $savedId, $data, $row); }
            Session::flash('success',(string)($cfg['singular'] ?? 'Record') . ' saved.');
            Response::redirect((string)($cfg['redirect'] ?? Request::path()));
        } catch (AppException $e) {
            Session::flash('error', $e->getMessage());
            Response::redirect(Request::path() . ($id !== '' ? '?edit=' . rawurlencode($id) : ''));
        } catch (\Throwable $e) {
            Logger::exception($e);
            Session::flash('error','Could not save: the input was rejected or the database is unavailable.');
            Response::redirect(Request::path() . ($id !== '' ? '?edit=' . rawurlencode($id) : ''));
        }
    }

    admin_header(['title'=>(string)$cfg['title'],'subtitle'=>(string)($cfg['subtitle'] ?? ''),'active'=>(string)$cfg['active']]);
    if (!empty($cfg['notice'])) { echo '<div class="alert alert-info">'.icon('info',16).'<span>'.Helpers::e((string)$cfg['notice']).'</span></div>'; }
    if (is_callable($cfg['before_table'] ?? null)) { echo (string)call_user_func($cfg['before_table']); }

    echo '<div class="admin-split"><div class="card" style="align-self:start"><div class="card-header"><h3>'.($row !== [] ? 'Edit ' : 'Add ').Helpers::e((string)($cfg['singular'] ?? 'record')).'</h3></div><div class="card-body">';
    echo '<form method="post" enctype="multipart/form-data">'.Session::csrfField().'<input type="hidden" name="id" value="'.Helpers::e($id).'"><input type="hidden" name="op" value="save"><div class="form-grid">';
    foreach ($fields as $key=>$f) {
        $type = $f['type'] ?? 'text'; $label = (string)($f['label'] ?? ucwords(str_replace('_',' ',(string)$key)));
        $v = (string)($row[$key] ?? ($f['default'] ?? ''));
        if ($type === 'money') { $v = number_format(((int)$v)/100,2,'.',''); }
        $span = !empty($f['span']) ? ' span-2' : '';
        echo '<div class="field'.$span.'"><label for="f_'.Helpers::e($key).'">'.Helpers::e($label).(!empty($f['required'])?' *':'').'</label>';
        if ($type === 'textarea' || $type === 'json') {
            echo '<textarea class="textarea" id="f_'.Helpers::e($key).'" name="'.Helpers::e($key).'"'.(!empty($f['required'])?' required':'').' style="min-height:'.($type==='json'?'80':'110').'px">'.Helpers::e($v).'</textarea>';
        } elseif ($type === 'checkbox') {
            echo '<label class="checkbox"><input type="checkbox" id="f_'.Helpers::e($key).'" name="'.Helpers::e($key).'" value="1" '.((int)$v?'checked':'').'><span>'.Helpers::e((string)($f['hint'] ?? 'Enabled')).'</span></label>';
        } elseif ($type === 'select' || $type === 'reference') {
            echo '<select class="select" id="f_'.Helpers::e($key).'" name="'.Helpers::e($key).'">';
            foreach (admin_choices($f) as $val=>$lab) { echo '<option value="'.Helpers::e($val).'" '.($v===(string)$val?'selected':'').'>'.Helpers::e((string)$lab).'</option>'; }
            echo '</select>';
        } elseif ($type === 'image') {
            echo '<input class="input" type="url" id="f_'.Helpers::e($key).'" name="'.Helpers::e($key).'" value="'.Helpers::e($v).'" placeholder="/assets/img/... or https://...">';
            echo '<input class="input" type="file" name="'.Helpers::e($key).'_file" accept="image/jpeg,image/png,image/webp" style="margin-top:8px">';
            if ($v !== '') { $url = UploadService::url($v); echo '<img src="'.Helpers::e($url !== '' ? $url : $v).'" alt="" style="width:72px;height:72px;object-fit:cover;border-radius:8px;margin-top:8px">'; }
        } elseif ($type === 'password_hash') {
            echo '<input class="input" type="password" id="f_'.Helpers::e($key).'" name="password" autocomplete="new-password" minlength="10"'.($row===[]?' required':'').'><span class="field-hint">'.($row!==[]?'Leave blank to keep the current password.':'At least 10 characters with a letter and a number.').'</span>';
        } else {
            $inputType = match ($type) { 'number','money'=>'number', 'email'=>'email', 'datetime'=>'datetime-local', default=>'text' };
            $step = $type === 'money' ? '0.01' : (isset($f['integer']) ? '1' : 'any');
            $attrs = $inputType === 'number' ? ' step="'.$step.'"'.(isset($f['min'])?' min="'.$f['min'].'"':'').(isset($f['max'])?' max="'.$f['max'].'"':'') : '';
            if ($type === 'datetime' && str_contains($v,'T')) { $v = substr($v,0,16); }
            echo '<input class="input" type="'.$inputType.'" id="f_'.Helpers::e($key).'" name="'.Helpers::e($key).'" value="'.Helpers::e($v).'"'.(!empty($f['required'])?' required':'').(isset($f['maxlength'])?' maxlength="'.(int)$f['maxlength'].'"':'').$attrs.'>';
        }
        if (!empty($f['help'])) { echo '<span class="field-hint">'.Helpers::e((string)$f['help']).'</span>'; }
        echo '</div>';
    }
    echo '</div><div class="row" style="margin-top:18px"><button class="btn btn-primary" type="submit">'.icon('check',16).'Save '.Helpers::e((string)($cfg['singular'] ?? 'record')).'</button>';
    if ($row !== []) { echo '<a class="btn btn-outline" href="'.Helpers::e(Request::path()).'">Cancel</a>'; }
    echo '</div></form></div></div>';

    echo '<div class="card" style="min-width:0"><div class="card-header"><h3>All '.Helpers::e((string)($cfg['plural'] ?? 'records')).'</h3></div><div class="card-body">';
    echo '<form class="admin-toolbar" method="get"><input class="input" type="search" name="q" value="'.Helpers::e(Request::str('q','',80)).'" placeholder="'.Helpers::e((string)($cfg['search_placeholder'] ?? 'Search…')).'"><button class="btn btn-outline" type="submit">'.icon('search',15).'Search</button></form>';
    $q = Request::str('q','',80);
    $where = ['1=1']; $params = [];
    if ($q !== '' && !empty($cfg['search_columns'])) {
        $parts = [];
        foreach ((array)$cfg['search_columns'] as $i=>$col) { $parts[] = 'LOWER(' . $col . ') LIKE :q'.$i; $params['q'.$i] = '%'.mb_strtolower($q).'%'; }
        $where[] = '(' . implode(' OR ', $parts) . ')';
    }
    if (!empty($cfg['soft_delete']) && in_array('deleted_at',$columns,true)) { $where[] = 'deleted_at IS NULL'; }
    if (trim((string)($cfg['where'] ?? '')) !== '') { $where[] = (string)$cfg['where']; }
    $sql = (string)($cfg['sql'] ?? 'SELECT * FROM ' . $table);
    $page = Request::int('page',1,1,10000); $per = 20; $off = ($page-1)*$per;
    $rows = Database::all($sql . (str_contains(strtoupper($sql),' WHERE ') ? ' AND ' : ' WHERE ') . implode(' AND ',$where) . ' ORDER BY ' . (string)($cfg['order'] ?? 'created_at DESC') . " LIMIT $per OFFSET $off", $params);
    echo '<div class="admin-table-wrap"><table class="admin-table"><thead><tr>';
    foreach ((array)$cfg['columns'] as $label=>$fn) { echo '<th>'.Helpers::e((string)$label).'</th>'; }
    echo '<th>Actions</th></tr></thead><tbody>';
    if ($rows === []) { echo '<tr><td colspan="'.(count((array)$cfg['columns'])+1).'" style="text-align:center;padding:30px;color:var(--c-muted)">No records found.</td></tr>'; }
    foreach ($rows as $r) {
        echo '<tr>';
        foreach ((array)$cfg['columns'] as $fn) { echo '<td>'.(is_callable($fn) ? $fn($r) : Helpers::e($r[(string)$fn] ?? '')).'</td>'; }
        echo '<td><div class="row" style="gap:5px;flex-wrap:wrap"><a class="btn btn-sm btn-outline" href="?edit='.Helpers::e($r['id']).'">Edit</a>';
        if (!empty($cfg['toggle_column'])) { echo '<form method="post" style="display:inline">'.Session::csrfField().'<input type="hidden" name="id" value="'.Helpers::e($r['id']).'"><input type="hidden" name="op" value="toggle"><button class="btn btn-sm btn-outline" type="submit">Toggle</button></form>'; }
        if (!empty($cfg['deletable'])) { echo '<form method="post" style="display:inline">'.Session::csrfField().'<input type="hidden" name="id" value="'.Helpers::e($r['id']).'"><input type="hidden" name="op" value="delete"><button class="btn btn-sm btn-danger" type="submit" data-confirm="Remove this record?">'.icon('trash',13).'</button></form>'; }
        echo '</div></td></tr>';
    }
    echo '</tbody></table></div>';
    echo '<div class="row" style="margin-top:12px;gap:8px">';
    if ($page>1) echo '<a class="btn btn-outline btn-sm" href="?page='.($page-1).'&q='.rawurlencode($q).'">← Previous</a>';
    if (count($rows)===$per) echo '<a class="btn btn-outline btn-sm" href="?page='.($page+1).'&q='.rawurlencode($q).'">Next →</a>';
    echo '</div></div></div></div>';
    admin_footer();
}

function admin_col(array $r, string $key): string { return Helpers::e($r[$key] ?? ''); }
function admin_money(array $r, string $key): string { return Helpers::money((int)($r[$key] ?? 0)); }
function admin_bool(array $r, string $key, string $yes = 'Active', string $no = 'Inactive'): string
{
    return '<span class="badge '.((int)($r[$key] ?? 0) ? 'badge-success':'badge-neutral').'">'.Helpers::e((int)($r[$key] ?? 0) ? $yes : $no).'</span>';
}
function admin_date(array $r, string $key): string { return Helpers::e(Helpers::dateNice($r[$key] ?? null)); }
function admin_status(array $r, string $key = 'status'): string
{
    $v = (string)($r[$key] ?? '');
    return '<span class="badge '.(in_array($v,['active','paid','delivered','approved','completed'],true)?'badge-success':(in_array($v,['pending','processing','draft'],true)?'badge-warning':'badge-neutral')).'">'.Helpers::e(ucwords(str_replace('_',' ',$v))).'</span>';
}
