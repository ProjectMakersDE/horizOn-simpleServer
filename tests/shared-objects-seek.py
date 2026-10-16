#!/usr/bin/env python3
"""Measure actual SQLite VM work for production compound predicates, with the old filter as negative control."""
import json,sqlite3,subprocess
from pathlib import Path
repo=Path(__file__).resolve().parents[1]
php='require "src/SharedObjects/SharedObjectStore.php";$p=new PDO("sqlite::memory:");$s=new SharedObjectStore($p);$out=[];foreach(["slot0","position_x"]as$f){foreach([0,9000]as$i){$seek=["partition"=>"all","owner"=>null,"field"=>$f,"lower"=>"09007199254740992","upper"=>"09007199254740992","exclusive"=>false,"afterId"=>sprintf("00000000-0000-4000-8000-%012d",$i),"afterSort"=>"09007199254740992"];$out[]=$s->seekSql("load",$seek,256);}}echo json_encode($out,JSON_THROW_ON_ERROR);'
plans=json.loads(subprocess.check_output(['php','-r',php],cwd=repo,text=True))
db=sqlite3.connect(':memory:');db.executescript((repo/'migrations/sqlite.sql').read_text())
base=('load','all','00000000-0000-4000-8000-000000002012',None,1,0,8192,'{}',None,'09007199254740992',None,None,'09007199254740992',0,0,None)
db.executemany('INSERT INTO shared_objects VALUES('+','.join('?'*17)+')',[(f'00000000-0000-4000-8000-{i:012}',)+base for i in range(10000)])
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
for field,index in [('slot0',0),('position_x',2)]:
 (early_sql,early_args),(deep_sql,deep_args)=plans[index:index+2]
 early,_=run(early_sql,early_args);deep,rows=run(deep_sql,deep_args)
 assert rows[0][0].endswith('000000009001')
 assert deep<=early*1.2,(field,early,deep)
 old=deep_sql.replace(f'({field},id)>(?,?)',f'({field}>? OR ({field}=? AND id>?))')
 oldargs=deep_args[:-2]+[deep_args[-2],deep_args[-2],deep_args[-1]]
 negative,_=run(old,oldargs);assert negative>deep*3,(field,negative,deep)
 proof[field]={'rows':256,'firstPageVmSteps':early,'deepPageVmSteps':deep,'oldFilterNegativeControlVmSteps':negative,'queryPlan':db.execute('EXPLAIN QUERY PLAN '+deep_sql,deep_args).fetchall()}
print('PASS: bounded compound SQLite seek through10000 equal-value rows, field+rectangle, with negative control')
print(json.dumps(proof,separators=(',',':')))
