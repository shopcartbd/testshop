<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once __DIR__ . '/_common.php';
use AllStore\Core\Request;
use AllStore\Core\Response;
use AllStore\Core\Database;
use AllStore\Core\Helpers;
use AllStore\Core\Logger;
use AllStore\Models\CmsRepository;
api_method('GET','POST'); api_require_admin('cms.manage');
if(Request::method()==='POST'){
 api_csrf(); api_rate('admin.sections',30,300);
 $action=Request::str('action','save',20); $id=Request::id('id');
 if($action==='delete'){ if($id==='') Response::fail('Missing id.',[],422); Database::delete('homepage_sections','id=:i',['i'=>$id]); Logger::audit('api.admin.section.deleted','homepage_sections',$id); Response::ok('Section removed.'); }
 if($action==='toggle'){ $s=Database::first('SELECT is_enabled FROM homepage_sections WHERE id=:i',['i'=>$id]); if(!$s) Response::fail('Section not found.',[],404); Database::update('homepage_sections',['is_enabled'=>(int)$s['is_enabled']?0:1,'updated_at'=>Helpers::now()],'id=:i',['i'=>$id]); Response::ok('Section visibility updated.'); }
 if($action!=='save') Response::fail('Unknown action.',[],422);
 $cfg=trim(Request::str('config','{}',4000)); if($cfg==='') $cfg='{}'; $decoded=json_decode($cfg,true); if(!is_array($decoded)) Response::fail('Config must be valid JSON.',[],422);
 $in=Request::all(); $in['config']=Helpers::json($decoded);
 $saved=CmsRepository::saveSection($in,$id!==''?$id:null); Logger::audit('api.admin.section.saved','homepage_sections',$saved); Response::ok('Section saved.',['id'=>$saved],201);
}
Response::ok('Sections loaded.',['items'=>array_map(static fn($r)=>api_pick($r,['id','section_key','title','subtitle','config','sort_order','is_enabled','starts_at','expires_at']),CmsRepository::sections(false))]);
