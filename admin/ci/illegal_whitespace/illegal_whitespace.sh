#!/bin/bash
# $gitdir: Directory containing git repo
# $gitbranch: Branch we are going to examine

# dirs and files (egrep-like regexp) we are going to exclude from analysis
exclude="/.git|/auth/cas/CAS|/lib/adodb|/lib/editor/tinymce/tiny_mce|/lib/typo3|/lib/yui|/lib/pear|/lib/spikephpcoverage|/lib/zend|/lib/overlib|/lib/minify|/lib/simpletestlib|/lib/swfobject|/lib/tcpdf|/repository/s3/S3.php|/repository/url/locallib.php|/search|/backup/bb/bb5.5_to_moodle.xsl|/backup/bb/bb6_to_moodle.xsl|/backup/cc/schemas|/lang/|/lib/alfresco|/lib/base32.php|/lib/bennu|/lib/csshover.htc|/lib/cookies.js|/lib/dragmath|/lib/excel|/lib/flowplayer|/lib/htmlpurifier|/lib/jabber/XMPP|/lib/markdown.php|/lib/simplepie|/lib/smarty|/lib/xhprof/xhprof_html|/lib/xhprof/xhprof_lib|/question/format/qti_two/templates|/webservice/amf/testclient/AMFTester.mxml|/webservice/amf/testclient/customValidators/JSONValidator.as"

# file where results will be sent
resultfile=$WORKSPACE/illegal_whitespace_$gitbranch.txt
countsfile=$WORKSPACE/illegal_whitespace_$gitbranch.csv

# Co to proper gitdir and gitpath
cd $gitdir && git checkout $gitbranch && git reset --hard origin/$gitbranch

# Search and send to $resultfile
echo -n > "$resultfile"
for i in `find . -type f`
do
    if [[ $i =~ $exclude ]]
    then
        continue
    fi
    content=`grep -PIn '^[ \t]+$|^ *\t *.+$|^.*[ \t]+$' $i`
    if [ ! -z "$content" ]
    then
        echo "## $i ##" >> "$resultfile"
        echo "$content" >> "$resultfile"
    fi
done

# Count and send to countsfile
count=`cat "$resultfile" | wc -l`
echo "$BUILD_NUMBER	$BUILD_ID	$count" >> "$countsfile"

# Compare 2 last executions to decide return status
count=0
for i in `tail -2 "$countsfile" | cut -s -f3`
do
    prevcount=$count
    count=$i
done
# Number of incorrect whitespace has grown
if (($prevcount < $count))
then
    exit 1
fi
exit 0
