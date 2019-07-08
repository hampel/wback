Website Backup (wback)
======================

Backup websites files and databases and store backups in the cloud.

Installation
------------

__Step 1 - install files__

1. create new directory `/srv/www/wback`
2. create a new server in deploy
3. copy SSH key to `~/.ssh/authorized_keys`
4. set up SSH command for composer install
5. set up SSH command for config cache
6. deploy project

__Step 2 - set permissions__

1. `cd /srv/www/wback && ~/tools/laravel.sh .`
2. `sudo -u www-data touch /srv/www/wback/storage/logs/laravel.log`

__Step 3 - create logrotate__

1. create wback logrotate definition: `sudo nano /etc/logrotate.d/wback` (see below)
2. create backup directory `sudo mkdir /var/www-backup/{hostname}/wback`
3. change permissions `sudo chown www-data:adm /var/www-backup/{hostname}/wback`

```text
# Laravel

/srv/www/wback/storage/logs/*.log
{
	su www-data www-data
	daily
	dateext
	dateformat .%Y%m%d
	extension .log
	missingok
	rotate 7
	compress
	#delaycompress
	notifempty
	#nocreate
	create 0664 www-data www-data
	olddir /var/www-backup/{hostname}/wback
}
```
