#!/bin/bash
# $gitdir: Directory containing git repo
# $gitbranch: Branch we are going to check
# file where results will be sent
resultfile=${WORKSPACE}/project_mess_detector.xml

# calculate some variables
mydir="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"

# list of excluded dirs
. ${mydir}/../define_excluded/define_excluded.sh

# checkout pristine copy of the configure branch
cd ${gitdir} && git checkout ${gitbranch} && git fetch && git reset --hard origin/${gitbranch}

# Run phpmd against the whole codebase
/opt/local/bin/php ${mydir}/project_mess_detector.php ${gitdir} xml codesize,unusedcode,design --exclude ${excluded_comma} --reportfile "${resultfile}"
# Always return ok
exit 0
