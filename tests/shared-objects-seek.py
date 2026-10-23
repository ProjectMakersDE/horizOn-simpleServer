#!/usr/bin/env python3
"""Actual SQLite VM work for the production binary-key compound predicates."""
import base64,json,sqlite3,struct,subprocess
from pathlib import Path
repo=Path(__file__).resolve().parents[1]
php='''require "src/Core/Database.php";require "src/SharedObjects/SharedObjectService.php";$p=new PDO("sqlite::memory:");$p->exec(file_get_contents("migrations/sqlite.sql"));SharedObjectSchema::ensure($p);$service=new SharedObjectService($p);$service->put("load",0,["name"=>"load","active"=>true,"readPolicy"=>"all","writePolicy"=>"owner","maxObjectBytes"=>65536,"maxObjectsPerOwner"=>100,"ttlSeconds"=>null,"searchFields"=>[["key"=>"power","type"=>"integer"]],"positionEnabled"=>true]);$s=new SharedObjectStore($p);$out=[];foreach(["slot0","position_x"]as$f){foreach([0,9000]as$i){$seek=["partition"=>"all","owner"=>null,"field"=>$f,"lower"=>"09007199254740992","upper"=>"09007199254740992","exclusive"=>false,"afterId"=>sprintf("00000000-0000-4000-8000-%012d",$i),"afterSort"=>"09007199254740992"];$plan=$s->seekSql("load",$seek,256);$plan[1]=array_map(static function($a){return $a instanceof SharedObjectSqlBytes?["b"=>base64_encode($a->value)]:["v"=>$a];},$plan[1]);$out[]=$plan;}}echo json_encode($out,JSON_THROW_ON_ERROR);'''
plans=json.loads(subprocess.check_output(['php','-r',php],cwd=repo,text=True))
for plan in plans:plan[1]=[base64.b64decode(a['b']) if 'b'in a else a['v'] for a in plan[1]]
db=sqlite3.connect(':memory:');db.executescript((repo/'migrations/shared-objects-sqlite.sql').read_text())
key=struct.pack('>Q',9007199254740992);base=(1,1,b'',bytes.fromhex('00000000000040008000000000002012'),None,1,0,None,key,None,None,1,0,0,0,None)
db.executemany('INSERT INTO shared_objects VALUES('+','.join('?'*17)+')',[(bytes.fromhex(f'00000000000040008000{i:012}'),)+base for i in range(10000)])
def run(sql,args):
 steps=0
 def step():
  nonlocal steps
  steps+=1
  return 0
 db.set_progress_handler(step,1);rows=db.execute(sql,args).fetchall();db.set_progress_handler(None,0)
 assert len(rows)==256
 return steps,rows
proof={}
for field,index in [('s0',0),('x',2)]:
 (early_sql,early_args),(deep_sql,deep_args)=plans[index:index+2]
 early,_=run(early_sql,early_args);deep,rows=run(deep_sql,deep_args)
 assert rows[0][0].hex().endswith('000000009001')
 assert deep<=early*1.2,(field,early,deep)
 old=deep_sql.replace(f'({field},i)>(?,?)',f'({field}>? OR ({field}=? AND i>?))')
 oldargs=deep_args[:-2]+[deep_args[-2],deep_args[-2],deep_args[-1]]
 negative,_=run(old,oldargs);assert negative>deep*3,(field,negative,deep)
 proof[field]={'rows':256,'firstPageVmSteps':early,'deepPageVmSteps':deep,'oldFilterNegativeControlVmSteps':negative,'queryPlan':db.execute('EXPLAIN QUERY PLAN '+deep_sql,deep_args).fetchall()}
print('PASS: bounded compact binary compound SQLite seek through10000 equal-value rows, field+rectangle, with negative control')
print(json.dumps(proof,separators=(',',':')))
