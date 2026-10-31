"""Read-only source metadata and strictly isolated local restore validation."""
import json
import re

TARGET = re.compile(r'kiotrad_local_backup_check_\d{8}T\d{12}Z_[a-f0-9]{8}')


def target_guard(target):
    if not TARGET.fullmatch(target):
        raise ValueError('Restore checks require a fresh disposable local backup-check namespace.')
    return target


def metadata_script(uri):
    return ('const URI=' + json.dumps(uri) + ';\n' + '''
try {
 const d=new Mongo(URI).getDB('kiotrad');
 const roles=d.runCommand({connectionStatus:1}).authInfo.authenticatedUserRoles;
 if(!roles.length || roles.some(r=>r.role!=='read'||r.db!=='kiotrad'))throw Error('read_role_required');
 const infos=d.getCollectionInfos().filter(c=>!c.name.startsWith('system.'));
 if(!infos.length || infos.some(c=>c.type!=='collection'))throw Error('unsupported_collection_type');
 const collections=infos.map(c=>({name:c.name,estimated_count_at_start:Number(d.getCollection(c.name).estimatedDocumentCount()),
   indexes:EJSON.serialize(d.getCollection(c.name).getIndexes(),{relaxed:false})}));
 print(JSON.stringify({database:'kiotrad',read_only_role_verified:true,collections,observed_at:new Date().toISOString()}));
}catch(_){print(JSON.stringify({error:'read_only_backup_metadata_failed'}));quit(1);}
''').encode()


def fresh_script(target):
    target_guard(target)
    return ('const name=' + json.dumps(target) + ''';
if(db.getMongo().getDBNames().includes(name))throw Error('Restore target already exists');
print(JSON.stringify({fresh:true,database:name}));
''').encode()


def validation_script(target, expected):
    target_guard(target)
    return ('const name=' + json.dumps(target) + ';const expected=' + json.dumps(expected) + ''';
const d=db.getSiblingDB(name);
const actual=d.getCollectionNames().filter(n=>!n.startsWith('system.')).sort();
if(JSON.stringify(actual)!==JSON.stringify(Object.keys(expected).sort()))throw Error('Restored collection membership differs');
const counts={};
for(const [name,count] of Object.entries(expected)){
 const found=Number(d.getCollection(name).countDocuments({}));
 if(found!==count)throw Error('Restored document count differs');
 counts[name]=found;
}
let references=0;
for(const name of ['season_checkpoints','provider_checkpoints']){
 if(!actual.includes(name))continue;
 for(const row of d.getCollection(name).find({capture_id:{$exists:true}})){
  if(!d.provider_captures.findOne({_id:row.capture_id},{_id:1}))throw Error('Restored checkpoint capture missing');
  references++;
 }
}
print(JSON.stringify({database:name,verified:true,counts,capture_references_checked:references,
 secondary_indexes_restored:false}));
''').encode()


def cleanup_script(target):
    target_guard(target)
    return ('const name=' + json.dumps(target) + ''';
print(JSON.stringify({database:name,result:db.getSiblingDB(name).dropDatabase()}));
''').encode()


VIEWS_SCRIPT = b'''<?php
$root='/app/storage/app/private/dashboard-views';
$count=0;
if (is_dir($root)) foreach (new DirectoryIterator($root) as $file) {
 if ($file->isLink() || !$file->isFile() || !preg_match('/^[a-f0-9]{64}\\.json$/D',$file->getFilename())) continue;
 if ($file->getSize()>104857600) throw new RuntimeException('View exceeds backup limit');
 $body=@file_get_contents($file->getPathname());
 if ($body===false) continue;
 echo json_encode(['type'=>'view','name'=>$file->getFilename(),'sha256'=>hash('sha256',$body),
   'body_base64'=>base64_encode($body)],JSON_THROW_ON_ERROR),PHP_EOL;
 $count++;
}
echo json_encode(['type'=>'complete','files'=>$count],JSON_THROW_ON_ERROR),PHP_EOL;
'''
