#!/bin/bash

# Define directories usually excluded by various CI tools
excluded=".git/
auth/cas/CAS/
backup/cc/schemas/
backup/cc/schemas11/
lib/adodb/
lib/editor/tinymce/tiny_mce/
lib/typo3/
lib/yui/
lib/pear/
lib/spikephpcoverage/
lib/zend/
lib/overlib/
lib/minify/
lib/simpletestlib/
lib/swfobject/
lib/tcpdf/
repository/s3/S3.php
repository/url/locallib.php
search/
backup/bb/bb5.5_to_moodle.xsl
backup/bb/bb6_to_moodle.xsl
backup/cc/schemas/
lang/
lib/alfresco/
lib/base32.php
lib/bennu/
lib/csshover.htc
lib/cookies.js
lib/dragmath/
lib/excel/
lib/flowplayer/
lib/htmlpurifier/
lib/jabber/XMPP/
lib/markdown.php
lib/simplepie/
lib/smarty/
lib/xhprof/xhprof_html/
lib/xhprof/xhprof_lib/
mod/lti/OAuthBody.php
question/format/qti_two/templates/
theme/mymobile/javascript/
theme/mymobile/style/jmobilerc2.css
webservice/amf/testclient/AMFTester.mxml
webservice/amf/testclient/customValidators/JSONValidator.as"

# Exclude syntax for grep commands (egrep-like regexp)
excluded_grep=""
for i in ${excluded}
do
    excluded_grep="${excluded_grep}|/${i}"
done
excluded_grep=${excluded_grep//|\/\.git/\/.git}
excluded_grep=${excluded_grep//\./\\.}

# Exclude syntax for phpcpd (list of exclude parameters)
excluded_list=""
for i in ${excluded}
do
    excluded_list="${excluded_list} --exclude ${i}"
done

# Exclude syntax for phpmd (comma separated)
excluded_comma=""
for i in ${excluded}
do
    excluded_comma="${excluded_comma},${i}"
done
excluded_comma=${excluded_comma//,\.git/.git}

# Exclude syntax for phpcs (coma separated with * wildcards)
excluded_comma_wildchars=""
for i in ${excluded}
do
    excluded_comma_wildchars="${excluded_comma_wildchars},*/${i}*"
done
excluded_comma_wildchars=${excluded_comma_wildchars//,\*\/\.git/*\/.git}
excluded_comma_wildchars=${excluded_comma_wildchars//\./\\.}
