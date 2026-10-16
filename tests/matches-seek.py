#!/usr/bin/env python3
"""Exercise actual production SQL through10k equal-strength/due keys, with a filter negative control."""
import json,sqlite3,subprocess
from pathlib import Path
repo=Path(__file__).resolve().parents[1]
php='require "src/Matches/MatchStore.php";$s=new MatchStore(new PDO("sqlite::memory:"));$o=[];foreach([0,9000]as$i){$id=sprintf("00000000-0000-4000-8000-%012d",$i);$o["open"][]=$s->openSql(5,5,["strength"=>5,"id"=>$id]);$o["due"][]=$s->dueSql(["cutoff"=>10,"afterDue"=>5,"afterId"=>$id]);}echo json_encode($o);'
queries=json.loads(subprocess.check_output(['php','-r',php],cwd=repo,text=True))
db=sqlite3.connect(':memory:');db.executescript((repo/'migrations/sqlite.sql').read_text())
db.executemany('INSERT INTO matches_state VALUES('+','.join('?'*14)+')',[(f'00000000-0000-4000-8000-{i:012}',1,1,1,0,'00000000-0000-4000-8000-000000001013','lobby','open',5,1,100,5,'{}','{}')for i in range(10000)])
def run(sql,args):
    steps=0
    def tick():
        nonlocal steps
        steps+=1
        return 0
    db.set_progress_handler(tick,1);rows=db.execute(sql,args).fetchall();db.set_progress_handler(None,0)
    return steps,rows
proof={}
for kind,pair in queries.items():
    early,_=run(*pair[0]);deep,rows=run(*pair[1]);assert len(rows)==(256 if kind=='open' else 20)
    assert deep<=early*1.2,(kind,early,deep)
    sql,args=pair[1];column='strength'if kind=='open'else'due_millis'
    old=sql.replace(f'({column},id)>(?,?)',f'({column}>? OR ({column}=? AND id>?))')
    at=0 if kind=='open'else 1
    oldargs=args[:at]+[args[at],args[at],args[at+1]]+args[at+2:]
    negative,_=run(old,oldargs);assert negative>deep*3,(kind,negative,deep)
    proof[kind]={'rows':len(rows),'firstVmSteps':early,'deepVmSteps':deep,'negativeVmSteps':negative,'plan':db.execute('EXPLAIN QUERY PLAN '+sql,args).fetchall()}
print('PASS: Matches compound SQLite seek through10000 equal strength/due rows and negative controls')
print(json.dumps(proof,separators=(',',':')))
