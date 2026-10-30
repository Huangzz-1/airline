#!/usr/bin/env python3
"""Keep local secrets in backend/.env and reuse them across Compose services."""
from pathlib import Path
import argparse,base64,os,secrets
parser=argparse.ArgumentParser()
parser.add_argument('--local',action='store_true',help='Select Mailpit and local mock payment services')
args=parser.parse_args()
root=Path(__file__).resolve().parents[1]
backend=root/'backend'/'.env'
compose_env=root/'.env'
def parse(text):
    return dict(line.split('=',1) for line in text.splitlines() if '=' in line and not line.lstrip().startswith('#'))
legacy=parse(compose_env.read_text()) if compose_env.exists() else {}
migrating_compose=compose_env.exists() and not compose_env.is_symlink()
if not backend.exists():backend.write_text((backend.parent/'.env.example').read_text())
lines=backend.read_text().splitlines()
values=parse('\n'.join(lines))
def set_value(name,value,overwrite=False):
    if not overwrite and values.get(name):return
    value=str(value)
    for index,line in enumerate(lines):
        if line.startswith(name+'='):lines[index]=name+'='+value;break
    else:lines.append(name+'='+value)
    values[name]=value
for name,value in [('DB_PASSWORD',secrets.token_hex(24)),('DB_ROOT_PASSWORD',secrets.token_hex(32)),('APP_KEY','base64:'+base64.b64encode(secrets.token_bytes(32)).decode())]:
    set_value(name,legacy.get(name) or value,overwrite=migrating_compose and bool(legacy.get(name)))
for name,value in [('APP_PORT',legacy.get('APP_PORT','8000')),('LOCAL_UID',os.getuid()),('LOCAL_GID',os.getgid())]:set_value(name,value)
if args.local:
    for name,value in [('APP_ENV','local'),('APP_URL','http://localhost:'+values['APP_PORT']),('MAIL_MAILER','smtp'),('MAIL_HOST','mailpit'),('MAIL_PORT','1025'),('MAIL_SCHEME','smtp'),('QUEUE_CONNECTION','database'),('PAYMENT_MODE','mock'),('VNPAY_ENABLED','true'),('MOMO_ENABLED','true'),('BANK_TRANSFER_ENABLED','true'),('BANK_TRANSFER_MODE','mock')]:set_value(name,value,True)
if values.get('APP_ENV')=='local':
    set_value('AIRLINE_BOOTSTRAP_ADMIN','true')
    set_value('LOCAL_ADMIN_EMAIL','admin@airline.test')
    set_value('LOCAL_ADMIN_PASSWORD',secrets.token_urlsafe(24))
if values.get('PAYMENT_MODE')=='mock':
    for name in ['VNPAY_HASH_SECRET','MOMO_ACCESS_KEY','MOMO_SECRET_KEY']:set_value(name,secrets.token_hex(32))
    set_value('VNPAY_TMN_CODE','LOCAL'+secrets.token_hex(3).upper())
    set_value('MOMO_PARTNER_CODE','LOCAL'+secrets.token_hex(4).upper())
if values.get('BANK_TRANSFER_MODE')=='mock':
    set_value('BANK_NAME','"Ngân hàng thử nghiệm"');set_value('BANK_ACCOUNT_NUMBER','000000000000');set_value('BANK_ACCOUNT_NAME','"AIRLINE LOCAL"')
backend.write_text('\n'.join(lines)+'\n');backend.chmod(0o600)
if not compose_env.is_symlink():
    if compose_env.exists():compose_env.unlink()
    compose_env.symlink_to('backend/.env')
print('Configuration ready in backend/.env; existing credentials preserved; secret values not displayed.')
