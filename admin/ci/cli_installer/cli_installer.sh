#!/bin/bash
# $gitdir: Directory containing git repo
# $gitbranchinstalled: Branch we are going to install the DB (and upgrade to)
# $gitbranchupgraded: Branch we are going to upgrade the DB from
# $dblibrary: Type of library (native, pdo...)
# $dbtype: Name of the driver (mysqli...)
# $dbhost1: DB1 host
# $dbhost2: DB2 host (optional)
# $dbuser1: DB1 user
# $dbuser2: DB2 user (optional)
# $dbpass1: DB1 password
# $dbpass2: DB2 password (optional)

# file where results will be sent
resultfile=$WORKSPACE/compare_databases_${gitbranchinstalled}_${gitbranchupgraded}.txt

# calculate some variables
mydir=`dirname $0`
installdb=ci_installed_${BUILD_NUMBER}
upgradedb=ci_upgraded_${BUILD_NUMBER}
dbprefixinstall="cii_"
dbprefixupgrade="ciu_"
if [[ -z "$dbhost2" ]]
then
    dbhost2=$dbhost1
fi
if [[ -z "$dbuser2" ]]
then
    dbuser2=$dbuser1
fi
if [[ -z "$dbpass2" ]]
then
    dbpass2=$dbpass1
fi

# Going to install the $gitbranchinstalled database
# Create the database
# TODO: Based on $dbtype, execute different DB creation commands
mysql --user=$dbuser1 --password=$dbpass1 --host=$dbhost1 --execute="CREATE DATABASE $installdb CHARACTER SET utf8 COLLATE utf8_bin"
# Do the moodle install
cd $gitdir && git checkout $gitbranchinstalled && git reset --hard origin/$gitbranchinstalled
rm -fr config.php
/opt/local/bin/php admin/cli/install.php --non-interactive --allow-unstable --agree-license --wwwroot="http://localhost" --dataroot="/tmp" --dbtype=$dbtype --dbhost=$dbhost1 --dbname=$installdb --dbuser=$dbuser1 --dbpass=$dbpass1 --prefix=$dbprefixinstall --fullname=$installdb --shortname=$installdb --adminuser=$dbuser1 --adminpass=$dbpass1

# Going to install and upgrade the $gitbranchupgraded database
# Create the database
# TODO: Based on $dbtype, execute different DB creation commands
mysql --user=$dbuser1 --password=$dbpass1 --host=$dbhost1 --execute="CREATE DATABASE $upgradedb CHARACTER SET utf8 COLLATE utf8_bin"
# Do the moodle install
cd $gitdir && git checkout $gitbranchupgraded && git reset --hard origin/$gitbranchupgraded
rm -fr config.php
/opt/local/bin/php admin/cli/install.php --non-interactive --allow-unstable --agree-license --wwwroot="http://localhost" --dataroot="/tmp" --dbtype=$dbtype --dbhost=$dbhost2 --dbname=$upgradedb --dbuser=$dbuser2 --dbpass=$dbpass2 --prefix=$dbprefixupgrade --fullname=$upgradedb --shortname=$upgradedb --adminuser=$dbuser2 --adminpass=$dbpass2
# Do the moodle upgrade
cd $gitdir && git checkout $gitbranchinstalled && git reset --hard origin/$gitbranchinstalled
/opt/local/bin/php admin/cli/upgrade.php --non-interactive --allow-unstable

# Run the DB compare utility, saving results to file
/opt/local/bin/php $mydir/compare_databases.php --dblibrary=$dblibrary --dbtype=$dbtype --dbhost1=$dbhost1 --dbname1=$installdb --dbuser1=$dbuser1 --dbpass1=$dbpass1 --dbprefix1=$dbprefixinstall --dbhost1=$dbhost1 --dbname2=$upgradedb --dbuser2=$dbuser2 --dbpass2=$dbpass2 --dbprefix2=$dbprefixupgrade > "$resultfile"
exitstatus=${PIPESTATUS[0]}

# Drop the databases and delete files
# TODO: Based on $dbtype, execute different DB deletion commands
mysqladmin --user=$dbuser1 --password=$dbpass1 --host=$dbhost1 --default-character-set=utf8 --force drop $installdb
mysqladmin --user=$dbuser2 --password=$dbpass2 --host=$dbhost2 --default-character-set=utf8 --force drop $upgradedb
rm -fr config.php

# If arrived here, return the exitstatus of the php execution
exit $exitstatus
